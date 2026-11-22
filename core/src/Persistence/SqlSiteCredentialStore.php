<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Domain\SiteCredential;
use Merlin\Core\Port\Connection;
use Merlin\Core\Port\SiteCredentialStore;

/** SiteCredentialStore-Port in SQL (Tabelle merlin_site_cred). */
final class SqlSiteCredentialStore implements SiteCredentialStore {
	private const TABLE = 'merlin_site_cred';
	/** @var RowMapper<SiteCredential> */
	private RowMapper $rows;

	public function __construct(private Connection $db) {
		$this->rows = new RowMapper(SiteCredential::class, ['userId', 'domain', 'usernameEnc', 'passwordEnc',
			'sessionCookiesEnc', 'cookieExpiresAt', 'lastLoginStatus', 'lastLoginAt', 'createdAt']);
	}

	public function find(string $userId, string $domain): ?SiteCredential {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*merlin_site_cred WHERE user_id = :user_id AND domain = :domain',
			['user_id' => $userId, 'domain' => $domain],
		);
		return $row === null ? null : $this->rows->toObject($row);
	}

	public function findAllForUser(string $userId): array {
		return array_map($this->rows->toObject(...), $this->db->fetchAll(
			'SELECT * FROM *PREFIX*merlin_site_cred WHERE user_id = :user_id ORDER BY domain ASC',
			['user_id' => $userId],
		));
	}

	public function save(SiteCredential $credential): void {
		$row = $this->rows->toRow($credential);
		if ($credential->getId() === null) {
			$row['created_at'] ??= SqlValue::dateTime(new \DateTimeImmutable());
			$credential->setId($this->db->insert(self::TABLE, $row));
			return;
		}
		// created_at bleibt beim Aktualisieren, wenn das Objekt keinen Wert kennt.
		if ($row['created_at'] === null) {
			unset($row['created_at']);
		}
		$updated = $this->db->execute(
			'UPDATE *PREFIX*merlin_site_cred SET ' . RowMapper::assignments($row) . ' WHERE id = :id',
			$row + ['id' => $credential->getId()],
		);
		if ($updated === 0 && $this->find($credential->getUserId(), $credential->getDomain()) === null) {
			throw new \RuntimeException('Zugangsdaten nicht mehr vorhanden: ' . $credential->getDomain());
		}
	}

	public function deleteByUserAndDomain(string $userId, string $domain): void {
		$this->db->execute(
			'DELETE FROM *PREFIX*merlin_site_cred WHERE user_id = :user_id AND domain = :domain',
			['user_id' => $userId, 'domain' => $domain],
		);
	}
}
