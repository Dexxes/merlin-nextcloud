<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Domain\Comment;
use Merlin\Core\Port\CommentStore;
use OCA\Merlin\Db\Comment as CommentEntity;
use OCA\Merlin\Db\CommentMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * CommentStore-Port des Kerns über CommentMapper.
 */
class NextcloudCommentStore implements CommentStore {
	private const FIELDS = ['userId', 'articleId', 'highlightId', 'quotedText', 'parentId', 'replyToId',
		'authorType', 'authorName', 'authorNameKey', 'body', 'createdAt', 'updatedAt', 'deletedAt'];
	private const DATES = ['createdAt', 'updatedAt', 'deletedAt'];

	public function __construct(
		private CommentMapper $mapper,
	) {
	}

	public function findByArticleId(int $articleId, string $userId): array {
		return array_values(array_map(self::toDomain(...), $this->mapper->findByArticleId($articleId, $userId)));
	}

	public function findInArticle(int $id, int $articleId, string $userId): ?Comment {
		try {
			return self::toDomain($this->mapper->findInArticle($id, $articleId, $userId));
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findForOwner(int $id, string $userId): ?Comment {
		try {
			return self::toDomain($this->mapper->findForOwner($id, $userId));
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function insert(Comment $comment): Comment {
		$entity = new CommentEntity();
		EntityCopy::toEntity($comment, $entity, self::FIELDS, self::DATES);
		$entity = $this->mapper->insert($entity);
		$comment->setId((int) $entity->getId());
		return $comment;
	}

	public function update(Comment $comment): Comment {
		$entity = $this->mapper->findForOwner((int) $comment->getId(), $comment->getUserId());
		EntityCopy::toEntity($comment, $entity, self::FIELDS, self::DATES);
		$this->mapper->update($entity);
		return $comment;
	}

	public function delete(Comment $comment): void {
		$entity = new CommentEntity();
		$entity->setId((int) $comment->getId());
		$this->mapper->delete($entity);
	}

	public function countForHighlight(int $highlightId, string $userId): int {
		return $this->mapper->countForHighlight($highlightId, $userId);
	}

	public function countReplies(int $rootId): int {
		return $this->mapper->countReplies($rootId);
	}

	public function countGuestSince(int $articleId, string $userId, \DateTime $since): int {
		return $this->mapper->countGuestSince($articleId, $userId, $since);
	}

	public function detachHighlight(int $highlightId, string $userId): void {
		$this->mapper->detachHighlight($highlightId, $userId);
	}

	public function updateQuotedText(int $highlightId, string $userId, string $quotedText): void {
		$this->mapper->updateQuotedText($highlightId, $userId, $quotedText);
	}

	public function signature(int $articleId, string $userId): string {
		return $this->mapper->signature($articleId, $userId);
	}

	private static function toDomain(CommentEntity $entity): Comment {
		$comment = new Comment();
		EntityCopy::toDomain($entity, $comment, self::FIELDS);
		return $comment;
	}
}
