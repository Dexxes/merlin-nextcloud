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

		// Optional tag filter. tag_ids holds the tag plus its descendants
		// (nested tags); a subquery instead of a join so an article carrying
		// several of them is listed once.
		if (!empty($filters['tag_ids'])) {
			$sub = $this->db->getQueryBuilder();
			$sub->select('at.article_id')
				->from('merlin_article_tags', 'at')
				->where($sub->expr()->in('at.tag_id', $qb->createNamedParameter(array_values($filters['tag_ids']), IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->andWhere($qb->expr()->in('a.id', $qb->createFunction($sub->getSQL())));
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
		// not_category: einzelner Wert oder Liste (Seiten = alles außer
		// Video und Audio, siehe ArticleController::index()).
		if (isset($filters['not_category'])) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('a.category'),
				$qb->expr()->notIn('a.category', $qb->createNamedParameter(
					(array) $filters['not_category'],
					IQueryBuilder::PARAM_STR_ARRAY
				))
			));
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

		// Seiten/Videos/Audio sind die obersten Kategorien (category = "Video",
		// "Audio" oder etwas anderes - "Mixed", also Text mit Medium, zählt zu
		// den Seiten), Unread/Favorites/Archived darunter je Kategorie gezählt -
		// siehe getCounts() in merlin-standalone-server/src/Db/ArticleRepository.php.
		$counts = [
			'pages'  => ['total' => 0, 'unread' => 0, 'favorites' => 0, 'archived' => 0],
			'videos' => ['total' => 0, 'unread' => 0, 'favorites' => 0, 'archived' => 0],
			'audio'  => ['total' => 0, 'unread' => 0, 'favorites' => 0, 'archived' => 0],
		];

		while ($row = $result->fetch()) {
			$isArchived = (bool)(int)$row['is_archived'];
			$read       = (bool)(int)$row['is_read'];
			// Rohes SELECT ohne Entity-Hydration: is_favorite ist hier ein
			// DATETIME-String oder NULL, kein Integer mehr – nicht (int)/(bool)
			// casten (führt bei Datums-Strings zu Fehlinterpretation).
			$favorite = $row['is_favorite'] !== null;
			$group    = match ($row['category'] ?? '') {
				'Video' => 'videos',
				'Audio' => 'audio',
				default => 'pages',
			};

			if ($isArchived) {
				$counts[$group]['archived']++;
			} else {
				$counts[$group]['total']++;
				if (!$read) {
					$counts[$group]['unread']++;
				}
			}
			if ($favorite) {
				$counts[$group]['favorites']++;
			}
		}

		$result->closeCursor();

		return $counts;
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
	 * Nutzer mit mindestens einem archivierten Artikel – nur für die kann die
	 * Löschfrist greifen (RetentionService).
	 *
	 * @return string[]
	 */
	public function findUserIdsWithArchived(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_archived', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$userIds = [];
		while (($userId = $result->fetchOne()) !== false) {
			$userIds[] = (string) $userId;
		}
		$result->closeCursor();
		return $userIds;
	}

	/**
	 * IDs archivierter Artikel, deren Archivierung vor $cutoff liegt, getrennt
	 * nach Favoriten und Nicht-Favoriten (eigene Frist je Gruppe). Artikel in
	 * Verarbeitung bleiben unberührt.
	 *
	 * @return int[]
	 */
	public function findExpiredArchivedIds(string $userId, \DateTimeImmutable $cutoff, bool $favorites, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_archived', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('archived_at'))
			->andWhere($qb->expr()->lt('archived_at', $qb->createNamedParameter($cutoff->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->eq('is_processing', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($favorites ? $qb->expr()->isNotNull('is_favorite') : $qb->expr()->isNull('is_favorite'))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int) $id;
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Anzahl der Artikel, die findExpiredArchivedIds() ohne Limit liefern würde
	 * (Vorschau in den Admin-Einstellungen).
	 */
	public function countExpiredArchived(string $userId, \DateTimeImmutable $cutoff, bool $favorites): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_archived', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('archived_at'))
			->andWhere($qb->expr()->lt('archived_at', $qb->createNamedParameter($cutoff->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->eq('is_processing', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($favorites ? $qb->expr()->isNotNull('is_favorite') : $qb->expr()->isNull('is_favorite'));

		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Archivierte Artikel ohne Archivierungsdatum (vor dem Fix in update() und
	 * der Pocket-API entstanden) bekommen $now. Ihre Löschfrist beginnt damit
	 * erst jetzt, statt sie beim ersten Lauf sofort zu löschen.
	 *
	 * @return int Anzahl nachgetragener Zeilen
	 */
	public function backfillArchivedAt(\DateTimeImmutable $now): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->getTableName())
			->set('archived_at', $qb->createNamedParameter($now->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('is_archived', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('archived_at'))
			->executeStatement();
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
