<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Comment>
 */
class CommentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'merlin_comments', Comment::class);
	}

	/**
	 * @return Comment[] Wurzeln und Antworten, älteste zuerst
	 */
	public function findByArticleId(int $articleId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Kommentar eines bestimmten Artikels: der Artikel kommt immer aus Login
	 * oder Share-Token, nie aus dem Request (IDOR-Schutz).
	 *
	 * @throws DoesNotExistException
	 */
	public function findInArticle(int $id, int $articleId, string $userId): Comment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findForOwner(int $id, string $userId): Comment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/** Kommentare (auch gelöschte Platzhalter) an einer Textstelle. */
	public function countForHighlight(int $highlightId, string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('highlight_id', $qb->createNamedParameter($highlightId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	public function countReplies(int $rootId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('parent_id', $qb->createNamedParameter($rootId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Gast-Kommentare eines Artikels seit $since (Tagesobergrenze gegen Spam).
	 */
	public function countGuestSince(int $articleId, string $userId, \DateTime $since): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('author_type', $qb->createNamedParameter(Comment::AUTHOR_GUEST)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATE)));
		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Markierung gelöscht: ihre Threads bleiben, nur der Verweis entfällt
	 * (quoted_text hält den markierten Text fest).
	 */
	public function detachHighlight(int $highlightId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('highlight_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('highlight_id', $qb->createNamedParameter($highlightId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	/**
	 * Änderungsmarke für den Push-Kanal: ändert sich bei jedem neuen,
	 * bearbeiteten oder gelöschten Kommentar.
	 */
	public function signature(int $articleId, string $userId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->selectAlias($qb->func()->max('id'), 'max_id')
			->selectAlias($qb->func()->max('updated_at'), 'max_updated')
			->from($this->getTableName())
			->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch() ?: [];
		$result->closeCursor();
		return ($row['cnt'] ?? 0) . ':' . ($row['max_id'] ?? 0) . ':' . ($row['max_updated'] ?? '');
	}
}
