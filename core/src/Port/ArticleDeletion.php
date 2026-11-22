<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Löscht Artikel eines Nutzers samt Markierungen, Tag-Zuordnungen und Shares.
 *
 * Nextcloud: OCA\Merlin\Service\ArticleDeletionService.
 */
interface ArticleDeletion {
	/**
	 * @param int[] $articleIds
	 * @return int Anzahl gelöschter Artikel
	 */
	public function deleteArticles(string $userId, array $articleIds): int;
}
