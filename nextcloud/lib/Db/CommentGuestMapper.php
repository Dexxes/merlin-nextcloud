<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CommentGuest>
 */
class CommentGuestMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'merlin_comment_guests', CommentGuest::class);
	}

	/**
	 * @return CommentGuest[] älteste zuerst
	 */
	public function findByArticleId(int $articleId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** Geht in die Änderungsmarke ein, damit neue Gäste sofort gepusht werden. */
	public function signature(int $articleId, string $userId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->selectAlias($qb->func()->max('id'), 'max_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$row = $result->fetch() ?: [];
		$result->closeCursor();
		return ($row['cnt'] ?? 0) . ':' . ($row['max_id'] ?? 0);
	}
}
