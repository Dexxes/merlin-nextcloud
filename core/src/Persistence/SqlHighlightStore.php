<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Domain\Highlight;
use Merlin\Core\Port\Connection;
use Merlin\Core\Port\HighlightStore;

/**
 * HighlightStore-Port in SQL (Tabelle merlin_highlights), dieselben Abfragen
 * wie HighlightMapper der Nextcloud-App.
 */
final class SqlHighlightStore implements HighlightStore {
	private const TABLE = 'merlin_highlights';
	/** @var RowMapper<Highlight> */
	private RowMapper $rows;

	public function __construct(private Connection $db) {
		$this->rows = new RowMapper(Highlight::class, ['userId', 'articleId', 'highlightedText', 'startXpath',
			'startOffset', 'endXpath', 'endOffset', 'color', 'createdAt', 'authorType', 'authorName', 'authorNameKey',
			'pdfPage', 'pdfRects', 'revision']);
	}

	public function findByArticleId(int $articleId, string $userId): array {
		return array_map($this->rows->toObject(...), $this->db->fetchAll(
			'SELECT * FROM *PREFIX*merlin_highlights WHERE article_id = :article_id AND user_id = :user_id ORDER BY created_at ASC, id ASC',
			['article_id' => $articleId, 'user_id' => $userId],
		));
	}

	public function findById(int $id, string $userId): ?Highlight {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*merlin_highlights WHERE id = :id AND user_id = :user_id',
			['id' => $id, 'user_id' => $userId],
		);
		return $row === null ? null : $this->rows->toObject($row);
	}

	public function findInArticle(int $id, int $articleId, string $userId): ?Highlight {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*merlin_highlights WHERE id = :id AND article_id = :article_id AND user_id = :user_id',
			['id' => $id, 'article_id' => $articleId, 'user_id' => $userId],
		);
		return $row === null ? null : $this->rows->toObject($row);
	}

	public function insert(Highlight $highlight): Highlight {
		$row = $this->rows->toRow($highlight);
		// author_type ist NOT NULL mit Default 'owner'; null hieße "Standard".
		$row['author_type'] ??= 'owner';
		$highlight->setId($this->db->insert(self::TABLE, $row));
		return $highlight;
	}

	public function update(Highlight $highlight): Highlight {
		$row = $this->rows->toRow($highlight);
		$row['author_type'] ??= 'owner';
		$this->db->execute(
			'UPDATE *PREFIX*merlin_highlights SET ' . RowMapper::assignments($row) . ' WHERE id = :id AND user_id = :owner',
			$row + ['id' => (int) $highlight->getId(), 'owner' => $highlight->getUserId()],
		);
		return $highlight;
	}

	public function deleteById(int $id, string $userId): void {
		$this->db->execute(
			'DELETE FROM *PREFIX*merlin_highlights WHERE id = :id AND user_id = :user_id',
			['id' => $id, 'user_id' => $userId],
		);
	}

	public function countGuestSince(int $articleId, string $userId, \DateTime $since): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM *PREFIX*merlin_highlights WHERE article_id = :article_id AND user_id = :user_id'
			. " AND author_type = 'guest' AND created_at >= :since",
			['article_id' => $articleId, 'user_id' => $userId, 'since' => SqlValue::dateTime($since)],
		);
	}

	public function signature(int $articleId, string $userId): string {
		$row = $this->db->fetchRow(
			'SELECT COUNT(*) AS cnt, MAX(id) AS max_id, SUM(revision) AS revisions FROM *PREFIX*merlin_highlights'
			. ' WHERE article_id = :article_id AND user_id = :user_id',
			['article_id' => $articleId, 'user_id' => $userId],
		) ?? [];
		return ($row['cnt'] ?? 0) . ':' . ($row['max_id'] ?? 0) . ':' . ($row['revisions'] ?? 0);
	}
}
