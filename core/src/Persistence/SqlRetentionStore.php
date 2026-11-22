<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Port\Connection;
use Merlin\Core\Port\RetentionStore;

/**
 * RetentionStore-Port in SQL (merlin_articles, merlin_shares), dieselben
 * Bedingungen wie ArticleMapper/ArticleShareMapper der Nextcloud-App.
 */
final class SqlRetentionStore implements RetentionStore {
	private const EXPIRED = ' FROM *PREFIX*merlin_articles WHERE user_id = :user_id AND is_archived = 1'
		. ' AND archived_at IS NOT NULL AND archived_at < :cutoff AND is_processing = 0';

	public function __construct(private Connection $db) {
	}

	public function findUserIdsWithArchived(): array {
		return array_map(
			static fn (array $row) => (string) $row['user_id'],
			$this->db->fetchAll('SELECT DISTINCT user_id FROM *PREFIX*merlin_articles WHERE is_archived = 1'),
		);
	}

	public function countExpiredArchived(string $userId, \DateTimeImmutable $cutoff, bool $favorites): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(id)' . self::EXPIRED . self::favorites($favorites),
			['user_id' => $userId, 'cutoff' => SqlValue::dateTime($cutoff)],
		);
	}

	public function findExpiredArchivedIds(string $userId, \DateTimeImmutable $cutoff, bool $favorites, int $limit): array {
		return array_map(
			static fn (array $row) => (int) $row['id'],
			$this->db->fetchAll(
				'SELECT id' . self::EXPIRED . self::favorites($favorites) . ' ORDER BY id ASC LIMIT ' . max(1, $limit),
				['user_id' => $userId, 'cutoff' => SqlValue::dateTime($cutoff)],
			),
		);
	}

	public function deleteSharesExpiredBefore(\DateTimeImmutable $cutoff): int {
		return $this->db->execute(
			'DELETE FROM *PREFIX*merlin_shares WHERE expires_at IS NOT NULL AND expires_at < :cutoff',
			['cutoff' => SqlValue::dateTime($cutoff)],
		);
	}

	private static function favorites(bool $favorites): string {
		return $favorites ? ' AND is_favorite IS NOT NULL' : ' AND is_favorite IS NULL';
	}
}
