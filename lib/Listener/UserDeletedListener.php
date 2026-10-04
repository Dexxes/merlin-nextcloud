<?php

declare(strict_types=1);

namespace OCA\Merlin\Listener;

use OCA\Merlin\Db\SiteCredentialMapper;
use OCA\Merlin\Service\ArticleDeletionService;
use OCA\Merlin\Service\ContentFilterRepository;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Räumt die Merlin-Daten eines gelöschten Nutzers auf: Artikel samt
 * Highlights, Tag-Zuordnungen und Share-Links, Tags, private
 * Content-Filter-Overrides (scope='user') und Paywall-Zugangsdaten. Die
 * Reader-Einstellungen (IConfig-User-Values) entfernt Nextcloud selbst.
 *
 * Kein Fremdschlüssel auf oc_users nötig (Nextcloud-App-Tabellen verzichten
 * i. d. R. auf harte FKs zwischen App- und Core-Tabellen) — dieser Listener
 * ist der einzige Aufräummechanismus für verwaiste user_id-Zeilen in den
 * Merlin-Tabellen. Ohne ihn blieben die Daten eines gelöschten Nutzers
 * unsichtbar, aber für immer in der DB liegen.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private ContentFilterRepository $repository,
		private SiteCredentialMapper $siteCredentialMapper,
		private ArticleDeletionService $deletionService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}

		$userId = $event->getUser()->getUID();

		try {
			$this->deletionService->deleteAllForUser($userId);
		} catch (\Throwable $e) {
			// Fail-open wie unten: die Nutzerlöschung darf nicht an Merlin scheitern.
			$this->logger->error('articles: Aufräumen der Artikel nach Nutzerlöschung fehlgeschlagen', [
				'userId'    => $userId,
				'exception' => $e,
			]);
		}

		try {
			$this->repository->deleteAllUserCustom($userId);
		} catch (\Throwable $e) {
			// Nutzerlöschung darf an einem Merlin-Aufräumfehler nicht scheitern –
			// verwaiste Zeilen sind unschön, aber harmlos (nie wieder erreichbar,
			// da user_id nirgends sonst referenziert wird).
			$this->logger->error('content-filters: Aufräumen der User-Overrides nach Nutzerlöschung fehlgeschlagen', [
				'userId'    => $userId,
				'exception' => $e,
			]);
		}

		try {
			$this->siteCredentialMapper->deleteAllForUser($userId);
		} catch (\Throwable $e) {
			// Gleiches Fail-open-Prinzip wie oben: verwaiste, aber verschlüsselte
			// Zeilen sind unschön, dürfen die Nutzerlöschung aber nicht blockieren.
			$this->logger->error('site-credentials: Aufräumen der Paywall-Zugangsdaten nach Nutzerlöschung fehlgeschlagen', [
				'userId'    => $userId,
				'exception' => $e,
			]);
		}
	}
}
