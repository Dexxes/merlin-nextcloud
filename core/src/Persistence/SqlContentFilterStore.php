<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Port\Connection;
use Merlin\Core\Port\ContentFilterStore;

/** ContentFilterStore-Port in SQL (Tabelle merlin_cfilter). */
final class SqlContentFilterStore implements ContentFilterStore {
	public function __construct(private Connection $db) {
	}

	public function readXml(string $scope, string $userId, string $domain): ?string {
		$xml = $this->db->fetchValue(
			'SELECT xml FROM *PREFIX*merlin_cfilter WHERE scope = :scope AND domain = :domain AND user_id = :user_id',
			['scope' => $scope, 'domain' => $domain, 'user_id' => $userId],
		);
		return $xml === null ? null : (string) $xml;
	}

	public function findId(string $scope, string $userId, string $domain): ?int {
		$id = $this->db->fetchValue(
			'SELECT id FROM *PREFIX*merlin_cfilter WHERE scope = :scope AND domain = :domain AND user_id = :user_id',
			['scope' => $scope, 'domain' => $domain, 'user_id' => $userId],
		);
		return $id === null ? null : (int) $id;
	}

	public function insert(string $scope, string $userId, string $domain, string $xml, \DateTime $now, string $updatedBy): void {
		$this->db->insert('merlin_cfilter', [
			'scope' => $scope,
			'user_id' => $userId,
			'domain' => $domain,
			'xml' => $xml,
			'updated_at' => SqlValue::dateTime($now),
			'updated_by' => $updatedBy,
		]);
	}

	public function update(int $id, string $xml, \DateTime $now, string $updatedBy): void {
		$this->db->execute(
			'UPDATE *PREFIX*merlin_cfilter SET xml = :xml, updated_at = :updated_at, updated_by = :updated_by WHERE id = :id',
			['xml' => $xml, 'updated_at' => SqlValue::dateTime($now), 'updated_by' => $updatedBy, 'id' => $id],
		);
	}

	public function delete(string $scope, string $userId, string $domain): void {
		$this->db->execute(
			'DELETE FROM *PREFIX*merlin_cfilter WHERE scope = :scope AND domain = :domain AND user_id = :user_id',
			['scope' => $scope, 'domain' => $domain, 'user_id' => $userId],
		);
	}

	public function deleteAllForUser(string $scope, string $userId): void {
		$this->db->execute(
			'DELETE FROM *PREFIX*merlin_cfilter WHERE scope = :scope AND user_id = :user_id',
			['scope' => $scope, 'user_id' => $userId],
		);
	}

	public function listDomains(string $scope, ?string $userId = null, bool $sorted = false): array {
		$sql = 'SELECT domain FROM *PREFIX*merlin_cfilter WHERE scope = :scope';
		$params = ['scope' => $scope];
		if ($userId !== null) {
			$sql .= ' AND user_id = :user_id';
			$params['user_id'] = $userId;
		}
		if ($sorted) {
			$sql .= ' ORDER BY domain';
		}
		return array_map(static fn (array $row) => (string) $row['domain'], $this->db->fetchAll($sql, $params));
	}

	public function countByDomain(string $scope): array {
		$out = [];
		foreach ($this->db->fetchAll(
			'SELECT domain, COUNT(user_id) AS cnt FROM *PREFIX*merlin_cfilter WHERE scope = :scope GROUP BY domain',
			['scope' => $scope],
		) as $row) {
			$out[(string) $row['domain']] = (int) $row['cnt'];
		}
		return $out;
	}
}
