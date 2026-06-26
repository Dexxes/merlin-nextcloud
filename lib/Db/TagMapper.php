<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Tag>
 */
class TagMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'merlin_tags', Tag::class);
	}

	/**
	 * @param int $id
	 * @param string $userId
	 * @return Tag
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): Tag {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * @param string $userId
	 * @return Tag[]
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @param int $articleId
	 * @return Tag[]
	 */
	public function findByArticleId(int $articleId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('t.*')
			->from($this->getTableName(), 't')
			->innerJoin('t', 'merlin_article_tags', 'at', $qb->expr()->eq('t.id', 'at.tag_id'))
			->where($qb->expr()->eq('at.article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
			->orderBy('t.name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Add tag to article
	 */
	public function addToArticle(int $articleId, int $tagId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->insert('merlin_article_tags')
			->values([
				'article_id' => $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT),
				'tag_id' => $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT),
			]);

		$qb->executeStatement();
	}

	/**
	 * Remove tag from article
	 */
	public function removeFromArticle(int $articleId, int $tagId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete('merlin_article_tags')
			->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Delete all tags for a user
	 */
	public function deleteByUserId(string $userId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}
}
