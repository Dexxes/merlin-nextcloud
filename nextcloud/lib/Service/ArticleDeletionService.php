<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Einziger Weg, Artikel zu löschen: entfernt mit dem Artikel auch seine
 * Highlights, Kommentare, Tag-Zuordnungen und Share-Links. Vorher löschte
 * ArticleController::destroy() nur die Zeile in merlin_articles und ließ den
 * Rest als Waisen liegen.
 *
 * Benutzt von ArticleController::destroy(), der Pocket-API (ExtensionController),
 * dem Löschfrist-Job (RetentionService) und UserDeletedListener.
 */
class ArticleDeletionService {
	/** IN-Listen klein halten (Oracle erlaubt höchstens 1000 Einträge). */
	private const CHUNK = 500;

	public function __construct(
		private IDBConnection $db,
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
			$this->db->beginTransaction();
			try {
				// Nur eigene Artikel: die Kaskade darf nie fremde Zeilen treffen,
				// auch wenn ein Aufrufer falsche IDs übergibt.
				$ownIds = $this->filterOwnArticleIds($userId, $chunk);
				if ($ownIds !== []) {
					$this->deleteWhereIn('merlin_highlights', 'article_id', $ownIds);
					$this->deleteWhereIn('merlin_comments', 'article_id', $ownIds);
					$this->deleteWhereIn('merlin_comment_guests', 'article_id', $ownIds);
					$this->deleteWhereIn('merlin_article_tags', 'article_id', $ownIds);
					$this->deleteWhereIn('merlin_shares', 'article_id', $ownIds);
					$deleted += $this->deleteWhereIn('merlin_articles', 'id', $ownIds);
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}

		return $deleted;
	}

	/**
	 * Entfernt alle Merlin-Daten eines Nutzers (Artikel samt Highlights,
	 * Tag-Zuordnungen und Shares, dazu seine Tags). Für UserDeletedListener.
	 */
	public function deleteAllForUser(string $userId): void {
		$this->db->beginTransaction();
		try {
			foreach (['merlin_highlights', 'merlin_comments', 'merlin_comment_guests', 'merlin_shares'] as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
					->executeStatement();
			}

			// merlin_article_tags hat keine user_id-Spalte: über die Artikel bzw.
			// Tags des Nutzers gehen.
			foreach ([['merlin_articles', 'article_id'], ['merlin_tags', 'tag_id']] as [$owner, $column]) {
				$sub = $this->db->getQueryBuilder();
				$sub->select('id')
					->from($owner)
					->where($sub->expr()->eq('user_id', $sub->createNamedParameter($userId)));

				$qb = $this->db->getQueryBuilder();
				$qb->delete('merlin_article_tags')
					->where($qb->expr()->in($column, $qb->createFunction($sub->getSQL())))
					->setParameters($sub->getParameters(), $sub->getParameterTypes())
					->executeStatement();
			}

			foreach (['merlin_articles', 'merlin_tags'] as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
					->executeStatement();
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Räumt Waisen auf, die vor diesem Service entstanden sind: Highlights,
	 * Tag-Zuordnungen und Shares, deren Artikel es nicht mehr gibt.
	 *
	 * @return int Anzahl entfernter Zeilen
	 */
	public function deleteOrphans(): int {
		$removed = 0;
		foreach (['merlin_highlights', 'merlin_comments', 'merlin_comment_guests', 'merlin_article_tags', 'merlin_shares'] as $table) {
			// Läuft auch aus Migration 000028, also vor der Migration, die
			// merlin_comments anlegt.
			if (!$this->db->tableExists($table)) {
				continue;
			}
			$sub = $this->db->getQueryBuilder();
			$sub->select('id')->from('merlin_articles');

			$qb = $this->db->getQueryBuilder();
			$removed += $qb->delete($table)
				->where($qb->expr()->notIn('article_id', $qb->createFunction($sub->getSQL())))
				->executeStatement();
		}
		return $removed;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function filterOwnArticleIds(string $userId, array $ids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('merlin_articles')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$result = $qb->executeQuery();
		$own = [];
		while (($id = $result->fetchOne()) !== false) {
			$own[] = (int) $id;
		}
		$result->closeCursor();
		return $own;
	}

	/**
	 * @param int[] $ids
	 */
	private function deleteWhereIn(string $table, string $column, array $ids): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($table)
			->where($qb->expr()->in($column, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
