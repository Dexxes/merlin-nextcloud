<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Domain\Comment;
use Merlin\Core\Port\CommentStore;
use Merlin\Core\Port\Connection;

/**
 * CommentStore-Port in SQL (Tabelle merlin_comments), dieselben Abfragen wie
 * CommentMapper der Nextcloud-App.
 */
final class SqlCommentStore implements CommentStore {
	private const TABLE = 'merlin_comments';
	/** @var RowMapper<Comment> */
	private RowMapper $rows;

	public function __construct(private Connection $db) {
		$this->rows = new RowMapper(Comment::class, ['userId', 'articleId', 'highlightId', 'quotedText', 'parentId',
			'replyToId', 'authorType', 'authorName', 'authorNameKey', 'body', 'createdAt', 'updatedAt', 'deletedAt']);
	}

	public function findByArticleId(int $articleId, string $userId): array {
		return array_map($this->rows->toObject(...), $this->db->fetchAll(
			'SELECT * FROM *PREFIX*merlin_comments WHERE article_id = :article_id AND user_id = :user_id ORDER BY created_at ASC, id ASC',
			['article_id' => $articleId, 'user_id' => $userId],
		));
	}

	public function findInArticle(int $id, int $articleId, string $userId): ?Comment {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*merlin_comments WHERE id = :id AND article_id = :article_id AND user_id = :user_id',
			['id' => $id, 'article_id' => $articleId, 'user_id' => $userId],
		);
		return $row === null ? null : $this->rows->toObject($row);
	}

	public function findForOwner(int $id, string $userId): ?Comment {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*merlin_comments WHERE id = :id AND user_id = :user_id',
			['id' => $id, 'user_id' => $userId],
		);
		return $row === null ? null : $this->rows->toObject($row);
	}

	public function insert(Comment $comment): Comment {
		$comment->setId($this->db->insert(self::TABLE, $this->rows->toRow($comment)));
		return $comment;
	}

	public function update(Comment $comment): Comment {
		$row = $this->rows->toRow($comment);
		$this->db->execute(
			'UPDATE *PREFIX*merlin_comments SET ' . RowMapper::assignments($row) . ' WHERE id = :id',
			$row + ['id' => (int) $comment->getId()],
		);
		return $comment;
	}

	public function delete(Comment $comment): void {
		$this->db->execute('DELETE FROM *PREFIX*merlin_comments WHERE id = :id', ['id' => (int) $comment->getId()]);
	}

	public function countForHighlight(int $highlightId, string $userId): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM *PREFIX*merlin_comments WHERE highlight_id = :highlight_id AND user_id = :user_id',
			['highlight_id' => $highlightId, 'user_id' => $userId],
		);
	}

	public function countReplies(int $rootId): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM *PREFIX*merlin_comments WHERE parent_id = :parent_id',
			['parent_id' => $rootId],
		);
	}

	public function countGuestSince(int $articleId, string $userId, \DateTime $since): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM *PREFIX*merlin_comments WHERE article_id = :article_id AND user_id = :user_id'
			. ' AND author_type = :author_type AND created_at >= :since',
			['article_id' => $articleId, 'user_id' => $userId, 'author_type' => Comment::AUTHOR_GUEST,
				'since' => SqlValue::dateTime($since)],
		);
	}

	public function detachHighlight(int $highlightId, string $userId): void {
		$this->db->execute(
			'UPDATE *PREFIX*merlin_comments SET highlight_id = NULL WHERE highlight_id = :highlight_id AND user_id = :user_id',
			['highlight_id' => $highlightId, 'user_id' => $userId],
		);
	}

	public function updateQuotedText(int $highlightId, string $userId, string $quotedText): void {
		$this->db->execute(
			'UPDATE *PREFIX*merlin_comments SET quoted_text = :quoted_text WHERE highlight_id = :highlight_id AND user_id = :user_id',
			['quoted_text' => $quotedText, 'highlight_id' => $highlightId, 'user_id' => $userId],
		);
	}

	public function signature(int $articleId, string $userId): string {
		$row = $this->db->fetchRow(
			'SELECT COUNT(*) AS cnt, MAX(id) AS max_id, MAX(updated_at) AS max_updated FROM *PREFIX*merlin_comments'
			. ' WHERE article_id = :article_id AND user_id = :user_id',
			['article_id' => $articleId, 'user_id' => $userId],
		) ?? [];
		return ($row['cnt'] ?? 0) . ':' . ($row['max_id'] ?? 0) . ':' . ($row['max_updated'] ?? '');
	}
}
