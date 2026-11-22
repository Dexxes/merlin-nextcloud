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
	 * Baumstruktur der Tags eines Nutzers für Service\TagTree.
	 *
	 * @return array<int, ?int> id => parentId (null = oberste Ebene)
	 */
	public function findParentMap(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('id', 'parent_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$parents = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$parents[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
		}
		$result->closeCursor();

		return $parents;
	}

	/**
	 * Löscht Tags samt ihren Artikel-Zuordnungen (die Artikel bleiben).
	 *
	 * @param int[] $tagIds
	 */
	public function deleteWithLinks(array $tagIds, string $userId): void {
		if ($tagIds === []) {
			return;
		}
		foreach (array_chunk($tagIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('merlin_article_tags')
				->where($qb->expr()->in('tag_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		}
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
