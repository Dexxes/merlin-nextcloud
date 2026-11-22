<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\ContentFilterStore;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * ContentFilterStore-Port des Kerns über IDBConnection (Tabelle merlin_cfilter).
 */
class NextcloudContentFilterStore implements ContentFilterStore {
	private const TABLE = 'merlin_cfilter';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function readXml(string $scope, string $userId, string $domain): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('xml')
			->from(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->andWhere($qb->expr()->eq('domain', $qb->createNamedParameter($domain)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$xml    = $result->fetchOne();
		$result->closeCursor();

		return $xml === false ? null : (string) $xml;
	}

	public function findId(string $scope, string $userId, string $domain): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->andWhere($qb->expr()->eq('domain', $qb->createNamedParameter($domain)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$id     = $result->fetchOne();
		$result->closeCursor();

		return $id === false ? null : (int) $id;
	}

	public function insert(string $scope, string $userId, string $domain, string $xml, \DateTime $now, string $updatedBy): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'scope'      => $qb->createNamedParameter($scope),
				'user_id'    => $qb->createNamedParameter($userId),
				'domain'     => $qb->createNamedParameter($domain),
				'xml'        => $qb->createNamedParameter($xml),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
				'updated_by' => $qb->createNamedParameter($updatedBy),
			]);
		$qb->executeStatement();
	}

	public function update(int $id, string $xml, \DateTime $now, string $updatedBy): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('xml', $qb->createNamedParameter($xml))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->set('updated_by', $qb->createNamedParameter($updatedBy))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function delete(string $scope, string $userId, string $domain): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->andWhere($qb->expr()->eq('domain', $qb->createNamedParameter($domain)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function deleteAllForUser(string $scope, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function listDomains(string $scope, ?string $userId = null, bool $sorted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('domain')
			->from(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)));
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		if ($sorted) {
			$qb->orderBy('domain');
		}

		$result = $qb->executeQuery();
		$out    = array_column($result->fetchAll(), 'domain');
		$result->closeCursor();
		return $out;
	}

	public function countByDomain(string $scope): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('domain')
			->selectAlias($qb->func()->count('user_id'), 'cnt')
			->from(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->groupBy('domain');

		$result = $qb->executeQuery();
		$out    = [];
		foreach ($result->fetchAll() as $row) {
			$out[(string) $row['domain']] = (int) $row['cnt'];
		}
		$result->closeCursor();
		return $out;
	}
}
