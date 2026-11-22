<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Domain\Tag;
use Merlin\Core\Port\Connection;
use Merlin\Core\Port\TagStore;

/**
 * TagStore-Port in SQL (merlin_tags, merlin_article_tags), dieselben
 * Abfragen wie TagMapper der Nextcloud-App.
 */
final class SqlTagStore implements TagStore {
	private const TABLE = 'merlin_tags';
	/** @var RowMapper<Tag> */
	private RowMapper $rows;

	public function __construct(private Connection $db) {
		$this->rows = new RowMapper(Tag::class, ['userId', 'name', 'color', 'parentId', 'createdAt']);
	}

	public function find(int $id, string $userId): ?Tag {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*merlin_tags WHERE id = :id AND user_id = :user_id',
			['id' => $id, 'user_id' => $userId],
		);
		return $row === null ? null : $this->rows->toObject($row);
	}

	public function findAll(string $userId): array {
		return array_map($this->rows->toObject(...), $this->db->fetchAll(
			'SELECT * FROM *PREFIX*merlin_tags WHERE user_id = :user_id ORDER BY name ASC, id ASC',
			['user_id' => $userId],
		));
	}

	public function parentMap(string $userId): array {
		$parents = [];
		foreach ($this->db->fetchAll(
			'SELECT id, parent_id FROM *PREFIX*merlin_tags WHERE user_id = :user_id',
			['user_id' => $userId],
		) as $row) {
			$parents[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
		}
		return $parents;
	}

	public function insert(Tag $tag): Tag {
		$tag->setId($this->db->insert(self::TABLE, $this->rows->toRow($tag)));
		return $tag;
	}

	public function update(Tag $tag): Tag {
		$row = $this->rows->toRow($tag);
		$this->db->execute(
			'UPDATE *PREFIX*merlin_tags SET ' . RowMapper::assignments($row) . ' WHERE id = :id AND user_id = :owner',
			$row + ['id' => (int) $tag->getId(), 'owner' => $tag->getUserId()],
		);
		return $tag;
	}

	public function deleteWithLinks(array $tagIds, string $userId): void {
		foreach (array_chunk(array_map('intval', $tagIds), 500) as $chunk) {
			$this->db->transactional(function () use ($chunk, $userId): void {
				// Nur Zuordnungen zu eigenen Schlagworten löschen.
				$owned = array_map('intval', array_column($this->db->fetchAll(
					'SELECT id FROM *PREFIX*merlin_tags WHERE user_id = :user_id AND id IN ' . SqlValue::inList('id', $chunk)[0],
					['user_id' => $userId] + SqlValue::inList('id', $chunk)[1],
				), 'id'));
				if ($owned === []) {
					return;
				}
				[$in, $params] = SqlValue::inList('id', $owned);
				$this->db->execute("DELETE FROM *PREFIX*merlin_article_tags WHERE tag_id IN {$in}", $params);
				$this->db->execute(
					"DELETE FROM *PREFIX*merlin_tags WHERE user_id = :user_id AND id IN {$in}",
					['user_id' => $userId] + $params,
				);
			});
		}
	}

	public function findByArticleId(int $articleId): array {
		return $this->findByArticleIds([$articleId])[$articleId] ?? [];
	}

	public function findByArticleIds(array $articleIds): array {
		$articleIds = array_values(array_unique(array_map('intval', $articleIds)));
		$result = [];
		foreach (array_chunk($articleIds, 500) as $chunk) {
			[$in, $params] = SqlValue::inList('article', $chunk);
			foreach ($this->db->fetchAll(
				'SELECT t.*, mat.article_id AS merlin_article_id FROM *PREFIX*merlin_tags t'
				. ' INNER JOIN *PREFIX*merlin_article_tags mat ON t.id = mat.tag_id'
				. " WHERE mat.article_id IN {$in} ORDER BY t.name ASC, t.id ASC",
				$params,
			) as $row) {
				$result[(int) $row['merlin_article_id']][] = $this->rows->toObject($row);
			}
		}
		return $result;
	}

	public function addToArticle(int $articleId, int $tagId): void {
		$this->db->insert('merlin_article_tags', ['article_id' => $articleId, 'tag_id' => $tagId], false);
	}

	public function removeFromArticle(int $articleId, int $tagId): void {
		$this->db->execute(
			'DELETE FROM *PREFIX*merlin_article_tags WHERE article_id = :article_id AND tag_id = :tag_id',
			['article_id' => $articleId, 'tag_id' => $tagId],
		);
	}
}
