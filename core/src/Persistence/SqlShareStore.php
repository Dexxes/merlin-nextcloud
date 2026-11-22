<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Domain\ArticleShare;
use Merlin\Core\Port\Connection;
use Merlin\Core\Port\ShareStore;

/** ShareStore-Port in SQL (Tabelle merlin_shares). */
final class SqlShareStore implements ShareStore {
	/** @var RowMapper<ArticleShare> */
	private RowMapper $rows;

	public function __construct(private Connection $db) {
		$this->rows = new RowMapper(ArticleShare::class, ['userId', 'articleId', 'token', 'passwordHash', 'expiresAt', 'allowComments']);
	}

	public function findByToken(string $token): ?ArticleShare {
		$row = $this->db->fetchRow('SELECT * FROM *PREFIX*merlin_shares WHERE token = :token', ['token' => $token]);
		return $row === null ? null : $this->rows->toObject($row);
	}
}
