<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\RetentionStore;
use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\ArticleShareMapper;

/**
 * RetentionStore-Port des Kerns über die Mapper der Nextcloud-App.
 */
class NextcloudRetentionStore implements RetentionStore {
	public function __construct(
		private ArticleMapper $articleMapper,
		private ArticleShareMapper $shareMapper,
	) {
	}

	public function findUserIdsWithArchived(): array {
		return $this->articleMapper->findUserIdsWithArchived();
	}

	public function countExpiredArchived(string $userId, \DateTimeImmutable $cutoff, bool $favorites): int {
		return $this->articleMapper->countExpiredArchived($userId, $cutoff, $favorites);
	}

	public function findExpiredArchivedIds(string $userId, \DateTimeImmutable $cutoff, bool $favorites, int $limit): array {
		return $this->articleMapper->findExpiredArchivedIds($userId, $cutoff, $favorites, $limit);
	}

	public function deleteSharesExpiredBefore(\DateTimeImmutable $cutoff): int {
		return $this->shareMapper->deleteExpiredBefore($cutoff);
	}
}
