<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Datenbankabfragen der Löschfrist: archivierte Artikel nach Archivierungs-
 * datum und abgelaufene Share-Links.
 *
 * Nextcloud: OCA\Merlin\Adapter\NextcloudRetentionStore (ArticleMapper,
 * ArticleShareMapper).
 */
interface RetentionStore {
	/**
	 * Nutzer mit mindestens einem archivierten Artikel.
	 *
	 * @return list<string>
	 */
	public function findUserIdsWithArchived(): array;

	/**
	 * Anzahl archivierter Artikel, die vor $cutoff archiviert wurden;
	 * $favorites wählt Favoriten oder Nicht-Favoriten.
	 */
	public function countExpiredArchived(string $userId, \DateTimeImmutable $cutoff, bool $favorites): int;

	/**
	 * IDs wie bei countExpiredArchived(), höchstens $limit.
	 *
	 * @return list<int>
	 */
	public function findExpiredArchivedIds(string $userId, \DateTimeImmutable $cutoff, bool $favorites, int $limit): array;

	/**
	 * Löscht Share-Links, die vor $cutoff abgelaufen sind.
	 *
	 * @return int Anzahl gelöschter Links
	 */
	public function deleteSharesExpiredBefore(\DateTimeImmutable $cutoff): int;
}
