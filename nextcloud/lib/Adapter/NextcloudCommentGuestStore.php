<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Domain\CommentGuest;
use Merlin\Core\Port\CommentGuestStore;
use Merlin\Core\Port\DuplicateEntryException;
use OCA\Merlin\Db\CommentGuest as CommentGuestEntity;
use OCA\Merlin\Db\CommentGuestMapper;
use OCP\DB\Exception as DbException;

/**
 * CommentGuestStore-Port des Kerns über CommentGuestMapper.
 */
class NextcloudCommentGuestStore implements CommentGuestStore {
	private const FIELDS = ['userId', 'articleId', 'nameKey', 'name', 'color', 'createdAt'];
	private const DATES = ['createdAt'];

	public function __construct(
		private CommentGuestMapper $mapper,
	) {
	}

	public function findByArticleId(int $articleId, string $userId): array {
		return array_values(array_map(static function (CommentGuestEntity $entity): CommentGuest {
			$guest = new CommentGuest();
			EntityCopy::toDomain($entity, $guest, self::FIELDS);
			return $guest;
		}, $this->mapper->findByArticleId($articleId, $userId)));
	}

	public function insert(CommentGuest $guest): CommentGuest {
		$entity = new CommentGuestEntity();
		EntityCopy::toEntity($guest, $entity, self::FIELDS, self::DATES);
		try {
			$entity = $this->mapper->insert($entity);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new DuplicateEntryException($e->getMessage(), 0, $e);
			}
			throw $e;
		}
		$guest->setId((int) $entity->getId());
		return $guest;
	}

	public function signature(int $articleId, string $userId): string {
		return $this->mapper->signature($articleId, $userId);
	}
}
