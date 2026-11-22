<?php

declare(strict_types=1);

namespace Merlin\Standalone\Account;

use Merlin\Core\ContentFilter\ContentFilterRepository;
use Merlin\Core\Port\Connection;
use Merlin\Core\Port\ContentFilterSource;
use Merlin\Core\Service\ArticleDeletionService;
use Psr\Log\LoggerInterface;

/**
 * Entfernt alle Merlin-Daten eines Kontos, bevor es gelöscht wird: dasselbe
 * wie UserDeletedListener in der Nextcloud-App (Artikel samt Markierungen,
 * Kommentaren, Shares und Tags, eigene Content-Filter, Paywall-Zugangsdaten),
 * dazu die Nutzerwerte in user_settings. Die Konto-Tabellen (api_tokens,
 * password_reset_tokens, login_flow_tokens) löschen per Fremdschlüssel mit.
 */
final class UserDataCleaner {
	public function __construct(
		private Connection $db,
		private ArticleDeletionService $articles,
		private ContentFilterSource $contentFilters,
		private LoggerInterface $logger,
	) {
	}

	public function deleteAllFor(string $userId): void {
		$this->articles->deleteAllForUser($userId);
		if ($this->contentFilters instanceof ContentFilterRepository) {
			$this->contentFilters->deleteAllUserCustom($userId);
		}
		foreach (['merlin_site_cred', 'merlin_settings', 'user_settings'] as $table) {
			$this->db->execute("DELETE FROM *PREFIX*{$table} WHERE user_id = :user_id", ['user_id' => $userId]);
		}
		$this->logger->info('Merlin-Daten des Kontos entfernt', ['userId' => $userId]);
	}
}
