<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Domain\CommentGuest;
use Merlin\Core\Port\CommentGuestStore;
use Merlin\Core\Port\Connection;

/**
 * CommentGuestStore-Port in SQL (Tabelle merlin_comment_guests). Die
 * eindeutigen Indizes auf Name und Farbe melden sich beim Einfügen als
 * DuplicateEntryException (siehe PdoConnection).
 */
final class SqlCommentGuestStore implements CommentGuestStore {
	private const TABLE = 'merlin_comment_guests';
	/** @var RowMapper<CommentGuest> */
	private RowMapper $rows;

	public function __construct(private Connection $db) {
		$this->rows = new RowMapper(CommentGuest::class, ['userId', 'articleId', 'nameKey', 'name', 'color', 'createdAt']);
	}

	public function findByArticleId(int $articleId, string $userId): array {
		return array_map($this->rows->toObject(...), $this->db->fetchAll(
			'SELECT * FROM *PREFIX*merlin_comment_guests WHERE article_id = :article_id AND user_id = :user_id ORDER BY id ASC',
			['article_id' => $articleId, 'user_id' => $userId],
		));
	}

	public function insert(CommentGuest $guest): CommentGuest {
		$guest->setId($this->db->insert(self::TABLE, $this->rows->toRow($guest)));
		return $guest;
	}

	public function signature(int $articleId, string $userId): string {
		$row = $this->db->fetchRow(
			'SELECT COUNT(*) AS cnt, MAX(id) AS max_id FROM *PREFIX*merlin_comment_guests WHERE article_id = :article_id AND user_id = :user_id',
			['article_id' => $articleId, 'user_id' => $userId],
		) ?? [];
		return ($row['cnt'] ?? 0) . ':' . ($row['max_id'] ?? 0);
	}
}
