<?php

declare(strict_types=1);

namespace Merlin\Standalone\Cron;

use Merlin\Core\Port\Scheduler;
use Merlin\Core\Service\RetentionService;
use Merlin\Standalone\Adapter\LocalFileStorage;
use Merlin\Standalone\Db\EmailConfirmationRepository;
use Merlin\Standalone\Db\LoginFlowRepository;
use Merlin\Standalone\Db\ServerSettings;
use Psr\Log\LoggerInterface;

/**
 * Tägliche Hintergrundarbeit des Standalone-Servers, das Gegenstück zu
 * Nextclouds BackgroundJob\RetentionCleanupJob: archivierte Artikel nach
 * Ablauf der Löschfrist und lange abgelaufene Share-Links löschen
 * (Core RetentionService::run()), danach Merlin Dateien ohne Eintrag und
 * liegen gebliebene Uploads wegräumen (LocalFileStorage::cleanup(); in
 * Nextcloud bleiben solche Dateien in Files liegen).
 *
 * Zwei Auslöser, wie bei Nextcloud (Cron oder AJAX):
 *  - bin/merlin cron, aus dem System-Cron (z. B. stündlich; gearbeitet wird
 *    höchstens einmal in INTERVAL Sekunden)
 *  - ohne System-Cron ('background_jobs' => 'web', Standard): nach dem
 *    Ausliefern des Readers, sobald der letzte Lauf INTERVAL zurückliegt.
 *
 * Der Zeitpunkt des letzten Laufs steht in der Tabelle settings; er wird vor
 * dem Lauf gesetzt, damit zwei gleichzeitige Aufrufe nicht beide löschen.
 */
final class DailyJobs {
	public const INTERVAL = 24 * 60 * 60;
	public const LAST_RUN = 'daily_jobs_last_run';

	public function __construct(
		private readonly ServerSettings $settings,
		private readonly RetentionService $retention,
		private readonly LocalFileStorage $files,
		private readonly Scheduler $scheduler,
		private readonly LoggerInterface $logger,
		private readonly LoginFlowRepository $loginFlows,
		private readonly EmailConfirmationRepository $confirmations,
		private readonly string $mode = 'web',
	) {
	}

	/** Ist der nächste Lauf fällig? */
	public function isDue(?int $now = null): bool {
		$last = (int) $this->settings->get(self::LAST_RUN, '0');
		return ($now ?? time()) - $last >= self::INTERVAL;
	}

	/**
	 * @return array{articles: int, shares: int, users: array<string, int>, files: int}|null null, wenn nicht fällig
	 */
	public function runIfDue(bool $force = false): ?array {
		if (!$force && !$this->isDue()) {
			return null;
		}
		$this->settings->set(self::LAST_RUN, (string) time());
		$result = $this->retention->run();
		$result['files'] = $this->files->cleanup();
		// Abgelaufene Login Flows (mit noch nicht abgeholtem Token) und nie
		// bestätigte Registrierungen.
		$this->loginFlows->deleteExpired();
		$this->confirmations->deleteExpiredUnconfirmed();
		$this->logger->info('Tägliche Aufgaben: {articles} Artikel, {shares} Share-Links, {files} Dateien gelöscht', [
			'articles' => $result['articles'],
			'shares' => $result['shares'],
			'files' => $result['files'],
		]);
		return $result;
	}

	/** Ohne System-Cron: nach der Antwort laufen lassen, wenn fällig (AppController::reader). */
	public function scheduleFromWebRequest(): void {
		if ($this->mode !== 'web' || !$this->isDue()) {
			return;
		}
		$this->scheduler->afterResponse(function (): void {
			try {
				$this->runIfDue();
			} catch (\Throwable $e) {
				$this->logger->error('Tägliche Aufgaben fehlgeschlagen', ['exception' => $e]);
			}
		});
	}
}
