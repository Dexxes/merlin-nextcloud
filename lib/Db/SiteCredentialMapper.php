<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SiteCredential>
 */
class SiteCredentialMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'merlin_site_cred', SiteCredential::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(string $userId, string $domain): SiteCredential {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('domain', $qb->createNamedParameter($domain)));

		return $this->findEntity($qb);
	}

	/**
	 * @return list<SiteCredential>
	 */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('domain', 'ASC');

		return $this->findEntities($qb);
	}

	public function deleteByUserAndDomain(string $userId, string $domain): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('domain', $qb->createNamedParameter($domain)));
		$qb->executeStatement();
	}

	/** Aufgerufen von UserDeletedListener, analog ContentFilterRepository::deleteAllUserCustom(). */
	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
