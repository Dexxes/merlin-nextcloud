<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\AppInfo\Application;
use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\ArticleShareMapper;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Löschfrist für archivierte Artikel: liest Admin- und Nutzerwerte und führt
 * die Löschung aus (RetentionCleanupJob, occ merlin:retention:run). Die
 * Rechenregeln selbst stehen in RetentionPolicy.
 *
 * Speicherorte:
 * - Admin-Maximum: IAppConfig merlin/retention_max_days und
 *   merlin/retention_favorites_max_days (int, 0 = unbegrenzt).
 * - Nutzerwahl: IConfig-User-Values unter 'reader' (wie alle Reader-Settings,
 *   siehe SettingsController), retentionDays/retentionFavoritesDays.
 * - Bestätigter Hinweis: 'reader'/retentionNoticeAck (RetentionPolicy::fingerprint()).
 */
class RetentionService {
	public const APP_KEY_MAX_DAYS = 'retention_max_days';
	public const APP_KEY_FAVORITES_MAX_DAYS = 'retention_favorites_max_days';

	public const USER_SETTINGS_APP = 'reader';
	public const USER_KEY_DAYS = 'retentionDays';
	public const USER_KEY_FAVORITES_DAYS = 'retentionFavoritesDays';
	public const USER_KEY_NOTICE_ACK = 'retentionNoticeAck';

	/** Abgelaufene Share-Links bleiben so lange liegen (410 statt 404), dann weg. */
	public const SHARE_GRACE_DAYS = 30;

	private const BATCH = 500;

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private ArticleMapper $articleMapper,
		private ArticleShareMapper $shareMapper,
		private ArticleDeletionService $deletionService,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{days: int, favoritesDays: int} */
	public function getAdminLimits(): array {
		return [
			'days' => RetentionPolicy::normalizeDays($this->appConfig->getValueInt(Application::APP_ID, self::APP_KEY_MAX_DAYS, 0)),
			'favoritesDays' => RetentionPolicy::normalizeDays($this->appConfig->getValueInt(Application::APP_ID, self::APP_KEY_FAVORITES_MAX_DAYS, 0)),
		];
	}

	public function setAdminLimits(int $days, int $favoritesDays): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::APP_KEY_MAX_DAYS, RetentionPolicy::normalizeDays($days));
		$this->appConfig->setValueInt(Application::APP_ID, self::APP_KEY_FAVORITES_MAX_DAYS, RetentionPolicy::normalizeDays($favoritesDays));
	}

	/** @return array{days: int, favoritesDays: int} */
	public function getUserChoice(string $userId): array {
		return [
			'days' => RetentionPolicy::normalizeDays((int) $this->config->getUserValue($userId, self::USER_SETTINGS_APP, self::USER_KEY_DAYS, '0')),
			'favoritesDays' => RetentionPolicy::normalizeDays((int) $this->config->getUserValue($userId, self::USER_SETTINGS_APP, self::USER_KEY_FAVORITES_DAYS, '0')),
		];
	}

	/**
	 * Effektive Fristen eines Nutzers; $adminLimits erlaubt, eine noch nicht
	 * gespeicherte Admin-Vorgabe durchzurechnen (Vorschau).
	 *
	 * @param array{days: int, favoritesDays: int}|null $adminLimits
	 * @return array{days: int, favoritesDays: int}
	 */
	public function getEffective(string $userId, ?array $adminLimits = null): array {
		$admin = $adminLimits ?? $this->getAdminLimits();
		$user = $this->getUserChoice($userId);
		return [
			'days' => RetentionPolicy::effectiveDays($admin['days'], $user['days']),
			'favoritesDays' => RetentionPolicy::effectiveDays($admin['favoritesDays'], $user['favoritesDays']),
		];
	}

	/**
	 * Nur lesende Zusatzwerte für GET /api/settings. Bewusst flache Skalare:
	 * iOS dekodiert die Antwort als [String: SettingValue] und scheitert an
	 * verschachtelten Objekten.
	 *
	 * @return array<string, int|bool>
	 */
	public function getSettingsInfo(string $userId): array {
		$admin = $this->getAdminLimits();
		$effective = $this->getEffective($userId, $admin);
		$ack = $this->config->getUserValue($userId, self::USER_SETTINGS_APP, self::USER_KEY_NOTICE_ACK, '');

		return [
			'retentionMaxDays' => $admin['days'],
			'retentionFavoritesMaxDays' => $admin['favoritesDays'],
			'retentionEffectiveDays' => $effective['days'],
			'retentionFavoritesEffectiveDays' => $effective['favoritesDays'],
			'retentionNoticeRequired' => RetentionPolicy::noticeRequired($ack, $effective['days'], $effective['favoritesDays']),
		];
	}

	/** Hinweis als gelesen markieren: die aktuell gültigen Fristen werden gemerkt. */
	public function acknowledgeNotice(string $userId): void {
		$effective = $this->getEffective($userId);
		$this->config->setUserValue(
			$userId,
			self::USER_SETTINGS_APP,
			self::USER_KEY_NOTICE_ACK,
			RetentionPolicy::fingerprint($effective['days'], $effective['favoritesDays']),
		);
	}

	/**
	 * Wie viele Artikel der nächste Lauf mit dieser Admin-Vorgabe löschen würde.
	 */
	public function preview(int $adminDays, int $adminFavoritesDays, ?\DateTimeImmutable $now = null): int {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$admin = [
			'days' => RetentionPolicy::normalizeDays($adminDays),
			'favoritesDays' => RetentionPolicy::normalizeDays($adminFavoritesDays),
		];

		$count = 0;
		foreach ($this->articleMapper->findUserIdsWithArchived() as $userId) {
			$effective = $this->getEffective($userId, $admin);
			foreach ([false => $effective['days'], true => $effective['favoritesDays']] as $favorites => $days) {
				$cutoff = RetentionPolicy::cutoff($days, $now);
				if ($cutoff !== null) {
					$count += $this->articleMapper->countExpiredArchived($userId, $cutoff, (bool) $favorites);
				}
			}
		}
		return $count;
	}

	/**
	 * Löscht abgelaufene archivierte Artikel (alle Nutzer oder nur $onlyUserId)
	 * und lange abgelaufene Share-Links. Ein Fehler bei einem Nutzer wird
	 * geloggt und hält die anderen nicht auf.
	 *
	 * @return array{articles: int, shares: int, users: array<string, int>}
	 */
	public function run(?string $onlyUserId = null, bool $dryRun = false, ?\DateTimeImmutable $now = null): array {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$admin = $this->getAdminLimits();
		$userIds = $onlyUserId !== null ? [$onlyUserId] : $this->articleMapper->findUserIdsWithArchived();

		$total = 0;
		$perUser = [];
		foreach ($userIds as $userId) {
			try {
				$count = $this->runForUser($userId, $admin, $now, $dryRun);
			} catch (\Throwable $e) {
				$this->logger->error('retention: Löschen abgelaufener Artikel fehlgeschlagen', [
					'userId' => $userId,
					'exception' => $e,
				]);
				continue;
			}
			if ($count > 0) {
				$perUser[$userId] = $count;
				$total += $count;
				if (!$dryRun) {
					$this->logger->info('retention: {count} abgelaufene archivierte Artikel gelöscht', [
						'count' => $count,
						'userId' => $userId,
					]);
				}
			}
		}

		$shares = 0;
		if (!$dryRun && $onlyUserId === null) {
			$shares = $this->shareMapper->deleteExpiredBefore($now->modify('-' . self::SHARE_GRACE_DAYS . ' days'));
		}

		return ['articles' => $total, 'shares' => $shares, 'users' => $perUser];
	}

	/**
	 * @param array{days: int, favoritesDays: int} $admin
	 */
	private function runForUser(string $userId, array $admin, \DateTimeImmutable $now, bool $dryRun): int {
		$effective = $this->getEffective($userId, $admin);
		$count = 0;

		foreach ([false => $effective['days'], true => $effective['favoritesDays']] as $favorites => $days) {
			$cutoff = RetentionPolicy::cutoff($days, $now);
			if ($cutoff === null) {
				continue;
			}
			if ($dryRun) {
				$count += $this->articleMapper->countExpiredArchived($userId, $cutoff, (bool) $favorites);
				continue;
			}
			// Blockweise, damit ein Lauf keine langen Sperren hält.
			do {
				$ids = $this->articleMapper->findExpiredArchivedIds($userId, $cutoff, (bool) $favorites, self::BATCH);
				if ($ids === []) {
					break;
				}
				$deleted = $this->deletionService->deleteArticles($userId, $ids);
				$count += $deleted;
			} while (count($ids) === self::BATCH && $deleted > 0);
		}

		return $count;
	}
}
