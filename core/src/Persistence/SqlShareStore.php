<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Domain\ArticleShare;
use Merlin\Core\Port\Connection;
use Merlin\Core\Port\ShareStore;

/** ShareStore-Port in SQL (Tabelle merlin_shares). */
final class SqlShareStore implements ShareStore {
	private const TABLE = 'merlin_shares';

	/** @var RowMapper<ArticleShare> */
	private RowMapper $rows;

	public function __construct(private Connection $db) {
		$this->rows = new RowMapper(ArticleShare::class, ArticleShare::FIELDS);
	}

	public function findByToken(string $token): ?ArticleShare {
		$row = $this->db->fetchRow('SELECT * FROM *PREFIX*merlin_shares WHERE token = :token', ['token' => $token]);
		return $row === null ? null : $this->rows->toObject($row);
	}

	public function findByArticleId(int $articleId, string $userId): ?ArticleShare {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*merlin_shares WHERE article_id = :article_id AND user_id = :user_id',
			['article_id' => $articleId, 'user_id' => $userId],
		);
		return $row === null ? null : $this->rows->toObject($row);
	}

	public function insert(ArticleShare $share): ArticleShare {
		$share->setId($this->db->insert(self::TABLE, $this->rows->toRow($share)));
		return $share;
	}

	public function update(ArticleShare $share): ArticleShare {
		$row = $this->rows->toRow($share);
		$this->db->execute(
			'UPDATE *PREFIX*merlin_shares SET ' . RowMapper::assignments($row) . ' WHERE id = :id AND user_id = :owner',
			$row + ['id' => (int) $share->getId(), 'owner' => $share->getUserId()],
		);
		return $share;
	}

	public function deleteByArticleId(int $articleId, string $userId): void {
		$this->db->execute(
			'DELETE FROM *PREFIX*merlin_shares WHERE article_id = :article_id AND user_id = :user_id',
			['article_id' => $articleId, 'user_id' => $userId],
		);
	}
}
