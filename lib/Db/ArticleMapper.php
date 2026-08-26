<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Article>
 */
class ArticleMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'merlin_articles', Article::class);
	}

	/**
	 * @param int $id
	 * @param string $userId
	 * @return Article
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): Article {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * @param string $userId
	 * @param array $filters
	 * @param int $limit
	 * @param int $offset
	 * @return Article[]
	 */
	public function findAll(string $userId, array $filters = [], int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('a.*')
			->from($this->getTableName(), 'a')
			->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
			->setMaxResults($limit)
			->setFirstResult($offset);

		// Optional tag filter – join the article_tags pivot table
		if (isset($filters['tag_id'])) {
			$qb->innerJoin('a', 'merlin_article_tags', 'at', $qb->expr()->eq('a.id', 'at.article_id'))
				->andWhere($qb->expr()->eq('at.tag_id', $qb->createNamedParameter($filters['tag_id'], IQueryBuilder::PARAM_INT)));
		}

		// Apply remaining column filters (prefix with alias to avoid ambiguity)
		if (isset($filters['is_read'])) {
			$qb->andWhere($qb->expr()->eq('a.is_read', $qb->createNamedParameter($filters['is_read'], IQueryBuilder::PARAM_BOOL)));
		}
		// is_favorite ist kein Bool mehr, sondern NULL (nicht favorisiert) oder
		// ein Zeitstempel (Favorisierungszeitpunkt) – daher IS [NOT] NULL statt eq().
		if (isset($filters['is_favorite'])) {
			if ($filters['is_favorite']) {
				$qb->andWhere($qb->expr()->isNotNull('a.is_favorite'));
			} else {
				$qb->andWhere($qb->expr()->isNull('a.is_favorite'));
			}
		}
		if (isset($filters['is_archived'])) {
			$qb->andWhere($qb->expr()->eq('a.is_archived', $qb->createNamedParameter($filters['is_archived'], IQueryBuilder::PARAM_BOOL)));
		}
		if (isset($filters['category'])) {
			$qb->andWhere($qb->expr()->eq('a.category', $qb->createNamedParameter($filters['category'])));
		}

		// Favoriten-Ansicht: chronologisch nach Favorisierungszeitpunkt statt
		// nach Erstellungsdatum sortieren. Archiv-Ansicht analog nach
		// Archivierungszeitpunkt. Sonst wie gehabt nach created_at.
		if (isset($filters['is_favorite']) && $filters['is_favorite']) {
			$qb->orderBy('a.is_favorite', 'DESC');
		} elseif (isset($filters['is_archived']) && $filters['is_archived']) {
			$qb->orderBy('a.archived_at', 'DESC');
		} else {
			$qb->orderBy('a.created_at', 'DESC');
		}

		return $this->findEntities($qb);
	}

	/**
	 * @param int $tagId
	 * @param string $userId
	 * @param int $limit
	 * @param int $offset
	 * @return Article[]
	 */
	public function findByTag(int $tagId, string $userId, int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('a.*')
			->from($this->getTableName(), 'a')
			->innerJoin('a', 'merlin_article_tags', 'at', $qb->expr()->eq('a.id', 'at.article_id'))
			->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('at.tag_id', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit)
			->setFirstResult($offset)
			->orderBy('a.created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Return total / unread / favorite counts for a user — always unfiltered
	 * so sidebar badges stay correct regardless of the current view filter.
	 *
	 * Uses a single SELECT of three lightweight columns and counts in PHP to
	 * avoid any DBAL version incompatibilities with aggregate SQL functions.
	 *
	 * @return array{total: int, unread: int, favorites: int, archived: int}
	 */
	public function getCounts(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('is_read', 'is_favorite', 'is_archived', 'category')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();

		$total = 0;
		$unread = 0;
		$favorites = 0;
		$archived = 0;
		$videos = 0;

		while ($row = $result->fetch()) {
			$isArchived = (bool)(int)$row['is_archived'];
			$read       = (bool)(int)$row['is_read'];
			// Rohes SELECT ohne Entity-Hydration: is_favorite ist hier ein
			// DATETIME-String oder NULL, kein Integer mehr – nicht (int)/(bool)
			// casten (führt bei Datums-Strings zu Fehlinterpretation).
			$favorite   = $row['is_favorite'] !== null;
			$isVideo    = ($row['category'] ?? '') === 'Video';

			if ($isArchived) {
				$archived++;
			} else {
				$total++;
				if (!$read) {
					$unread++;
				}
				if ($isVideo) {
					$videos++;
				}
			}
			if ($favorite) {
				$favorites++;
			}
		}

		$result->closeCursor();

		return [
			'total'     => $total,
			'unread'    => $unread,
			'favorites' => $favorites,
			'archived'  => $archived,
			'videos'    => $videos,
		];
	}

	/**
	 * Summiert die Bytegröße der Textspalten aller Artikel eines Nutzers
	 * (strlen() statt DB-seitigem LENGTH()/OCTET_LENGTH() – vermeidet
	 * Portabilitätsunterschiede zwischen MySQL/PostgreSQL/SQLite und liefert
	 * verlässlich die Bytelänge, nicht die Zeichenanzahl). Dient der
	 * Speicherverbrauchs-Anzeige in den iOS-Einstellungen (Pendant zu
	 * ArticleRepository::getStorageStats() in merlin-standalone-server).
	 *
	 * @return array{count: int, bytes: int}
	 */
	public function getStorageStats(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('title', 'content', 'excerpt', 'author', 'site_name', 'url', 'image_url')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();

		$count = 0;
		$bytes = 0;
		while ($row = $result->fetch()) {
			$count++;
			foreach ($row as $value) {
				$bytes += strlen((string)($value ?? ''));
			}
		}
		$result->closeCursor();

		return ['count' => $count, 'bytes' => $bytes];
	}

	/**
	 * Reset isProcessing = 0 for every article that has been stuck in the
	 * processing state for longer than $ageMinutes minutes.
	 *
	 * Handles crashed/killed PHP processes that never ran their shutdown
	 * handler, leaving the flag permanently set to 1 in the database.
	 */
	public function clearStuckProcessing(string $userId, int $ageMinutes = 5): void {
		$cutoff = (new \DateTime())
			->modify("-{$ageMinutes} minutes")
			->format('Y-m-d H:i:s');

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('is_processing', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_processing', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($cutoff)))
			->executeStatement();
	}

	/**
	 * Return all articles that are still being processed for a given user.
	 *
	 * @return Article[]
	 */
	public function findProcessing(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_processing', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Delete all articles for a user
	 */
	public function deleteByUserId(string $userId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}

	/**
	 * Full-text search across title, excerpt, author, and site name.
	 *
	 * @return Article[]
	 */
	public function search(string $userId, string $term, int $limit = 20, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$like = '%' . $this->db->escapeLikeParameter($term) . '%';

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_archived', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->iLike('title',   $qb->createNamedParameter($like)),
					$qb->expr()->iLike('excerpt', $qb->createNamedParameter($like)),
					$qb->expr()->iLike('author',  $qb->createNamedParameter($like)),
					$qb->expr()->iLike('site_name', $qb->createNamedParameter($like)),
				)
			)
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities($qb);
	}
}
