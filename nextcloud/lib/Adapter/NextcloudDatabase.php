<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\Database;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Database-Port des Kerns über IDBConnection und Nextclouds QueryBuilder
 * (Tabellenpräfix und Quoting wie überall in der App).
 */
class NextcloudDatabase implements Database {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function transactional(callable $fn): mixed {
		$this->db->beginTransaction();
		try {
			$result = $fn();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function tableExists(string $table): bool {
		return $this->db->tableExists($table);
	}

	public function selectIds(string $table, string $column, array $equals, array $in = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)->from($table);
		$this->where($qb, $equals, $in);

		$result = $qb->executeQuery();
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int) $id;
		}
		$result->closeCursor();
		return $ids;
	}

	public function delete(string $table, array $equals = [], array $in = []): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table);
		$this->where($qb, $equals, $in);
		return $qb->executeStatement();
	}

	public function deleteWhereInSelect(string $table, string $column, string $subTable, array $subEquals): int {
		$sub = $this->db->getQueryBuilder();
		$sub->select('id')->from($subTable);
		$this->where($sub, $subEquals, []);

		$qb = $this->db->getQueryBuilder();
		return $qb->delete($table)
			->where($qb->expr()->in($column, $qb->createFunction($sub->getSQL())))
			->setParameters($sub->getParameters(), $sub->getParameterTypes())
			->executeStatement();
	}

	public function deleteWhereNotInSelect(string $table, string $column, string $subTable): int {
		$sub = $this->db->getQueryBuilder();
		$sub->select('id')->from($subTable);

		$qb = $this->db->getQueryBuilder();
		return $qb->delete($table)
			->where($qb->expr()->notIn($column, $qb->createFunction($sub->getSQL())))
			->executeStatement();
	}

	/**
	 * @param array<string, string> $equals
	 * @param array<string, list<int>> $in
	 */
	private function where(IQueryBuilder $qb, array $equals, array $in): void {
		$first = true;
		$add = static function ($condition) use ($qb, &$first): void {
			$first ? $qb->where($condition) : $qb->andWhere($condition);
			$first = false;
		};
		foreach ($equals as $column => $value) {
			$add($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		}
		foreach ($in as $column => $ids) {
			$add($qb->expr()->in($column, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		}
	}
}
