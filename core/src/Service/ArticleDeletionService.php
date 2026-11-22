<?php

declare(strict_types=1);

namespace Merlin\Core\Service;

use Merlin\Core\Port\ArticleDeletion;
use Merlin\Core\Port\Database;

/**
 * Einziger Weg, Artikel zu löschen: entfernt mit dem Artikel auch seine
 * Highlights, Kommentare, Tag-Zuordnungen und Share-Links. Vorher löschte
 * ArticleController::destroy() nur die Zeile in merlin_articles und ließ den
 * Rest als Waisen liegen.
 *
 * Benutzt von ArticleController::destroy(), der Pocket-API (ExtensionController),
 * dem Löschfrist-Job (RetentionService) und UserDeletedListener. Tabellen über
 * den Port Database.
 */
class ArticleDeletionService implements ArticleDeletion {
	/** IN-Listen klein halten (Oracle erlaubt höchstens 1000 Einträge). */
	private const CHUNK = 500;

	/** Tabellen, deren Zeilen an einem Artikel hängen (Spalte article_id). */
	private const ARTICLE_TABLES = ['merlin_highlights', 'merlin_comments', 'merlin_comment_guests', 'merlin_article_tags', 'merlin_shares'];

	public function __construct(
		private Database $db,
	) {
	}

	/**
	 * Löscht die angegebenen Artikel eines Nutzers samt abhängiger Daten.
	 * IDs, die nicht dem Nutzer gehören, werden ignoriert.
	 *
	 * @param int[] $articleIds
	 * @return int Anzahl gelöschter Artikel
	 */
	public function deleteArticles(string $userId, array $articleIds): int {
		$articleIds = array_values(array_unique(array_map('intval', $articleIds)));
		$deleted = 0;

		foreach (array_chunk($articleIds, self::CHUNK) as $chunk) {
			$deleted += $this->db->transactional(function () use ($userId, $chunk): int {
				// Nur eigene Artikel: die Kaskade darf nie fremde Zeilen treffen,
				// auch wenn ein Aufrufer falsche IDs übergibt.
				$ownIds = $this->db->selectIds('merlin_articles', 'id', ['user_id' => $userId], ['id' => $chunk]);
				if ($ownIds === []) {
					return 0;
				}
				foreach (self::ARTICLE_TABLES as $table) {
					$this->db->delete($table, [], ['article_id' => $ownIds]);
				}
				return $this->db->delete('merlin_articles', [], ['id' => $ownIds]);
			});
		}

		return $deleted;
	}

	/**
	 * Entfernt alle Merlin-Daten eines Nutzers (Artikel samt Highlights,
	 * Tag-Zuordnungen und Shares, dazu seine Tags). Für UserDeletedListener.
	 */
	public function deleteAllForUser(string $userId): void {
		$this->db->transactional(function () use ($userId): void {
			foreach (['merlin_highlights', 'merlin_comments', 'merlin_comment_guests', 'merlin_shares'] as $table) {
				$this->db->delete($table, ['user_id' => $userId]);
			}

			// merlin_article_tags hat keine user_id-Spalte: über die Artikel bzw.
			// Tags des Nutzers gehen.
			foreach ([['merlin_articles', 'article_id'], ['merlin_tags', 'tag_id']] as [$owner, $column]) {
				$this->db->deleteWhereInSelect('merlin_article_tags', $column, $owner, ['user_id' => $userId]);
			}

			foreach (['merlin_articles', 'merlin_tags'] as $table) {
				$this->db->delete($table, ['user_id' => $userId]);
			}
		});
	}

	/**
	 * Räumt Waisen auf, die vor diesem Service entstanden sind: Highlights,
	 * Tag-Zuordnungen und Shares, deren Artikel es nicht mehr gibt.
	 *
	 * @return int Anzahl entfernter Zeilen
	 */
	public function deleteOrphans(): int {
		$removed = 0;
		foreach (self::ARTICLE_TABLES as $table) {
			// Läuft auch aus Migration 000028, also vor der Migration, die
			// merlin_comments anlegt.
			if (!$this->db->tableExists($table)) {
				continue;
			}
			$removed += $this->db->deleteWhereNotInSelect($table, 'article_id', 'merlin_articles');
		}
		return $removed;
	}
}
