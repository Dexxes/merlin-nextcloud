<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\Connection;
use Merlin\Core\Port\DuplicateEntryException;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Connection-Port des Kerns über Nextclouds IDBConnection, damit die
 * SQL-Stores aus Merlin\Core\Persistence auch in der App laufen.
 * IDBConnection ersetzt `*PREFIX*` selbst. Parameter bekommen ihren Typ aus
 * dem PHP-Wert (int, bool als 0/1, null, sonst Text), damit z. B. LIMIT auf
 * MySQL als Zahl gebunden wird.
 */
class NextcloudConnection implements Connection {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function fetchAll(string $sql, array $params = []): array {
		$result = $this->db->executeQuery($sql, ...self::bind($params));
		try {
			return array_values($result->fetchAll());
		} finally {
			$result->closeCursor();
		}
	}

	public function fetchRow(string $sql, array $params = []): ?array {
		$result = $this->db->executeQuery($sql, ...self::bind($params));
		try {
			$row = $result->fetch();
			return $row === false ? null : $row;
		} finally {
			$result->closeCursor();
		}
	}

	public function fetchValue(string $sql, array $params = []): mixed {
		$result = $this->db->executeQuery($sql, ...self::bind($params));
		try {
			$value = $result->fetchOne();
			return $value === false ? null : $value;
		} finally {
			$result->closeCursor();
		}
	}

	public function execute(string $sql, array $params = []): int {
		return $this->db->executeStatement($sql, ...self::bind($params));
	}

	public function insert(string $table, array $values, bool $returnsId = true): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $column => $value) {
			$qb->setValue($column, $qb->createNamedParameter(is_bool($value) ? (int) $value : $value, self::type($value)));
		}
		try {
			$qb->executeStatement();
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new DuplicateEntryException($e->getMessage(), 0, $e);
			}
			throw $e;
		}
		return $returnsId ? $qb->getLastInsertId() : 0;
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

	/**
	 * @param array<string, mixed> $params
	 * @return array{0: array<string, mixed>, 1: array<string, int>}
	 */
	private static function bind(array $params): array {
		$values = [];
		$types = [];
		foreach ($params as $name => $value) {
			$name = ltrim((string) $name, ':');
			$values[$name] = is_bool($value) ? (int) $value : $value;
			$types[$name] = self::type($value);
		}
		return [$values, $types];
	}

	private static function type(mixed $value): int {
		return match (true) {
			$value === null => IQueryBuilder::PARAM_NULL,
			is_int($value), is_bool($value) => IQueryBuilder::PARAM_INT,
			default => IQueryBuilder::PARAM_STR,
		};
	}
}
