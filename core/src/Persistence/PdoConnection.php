<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Port\Connection;
use Merlin\Core\Port\DuplicateEntryException;
use PDO;

/**
 * Connection-Port über PDO (Standalone-Server, Testharnesses). Getestet mit
 * SQLite; die Erkennung eindeutiger Indizes kennt auch MySQL/MariaDB und
 * PostgreSQL.
 */
final class PdoConnection implements Connection {
	private int $depth = 0;

	public function __construct(
		private PDO $pdo,
		private string $tablePrefix = '',
	) {
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	}

	public function pdo(): PDO {
		return $this->pdo;
	}

	public function fetchAll(string $sql, array $params = []): array {
		return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
	}

	public function fetchRow(string $sql, array $params = []): ?array {
		$row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	public function fetchValue(string $sql, array $params = []): mixed {
		$value = $this->run($sql, $params)->fetchColumn();
		return $value === false ? null : $value;
	}

	public function execute(string $sql, array $params = []): int {
		return $this->run($sql, $params)->rowCount();
	}

	public function insert(string $table, array $values, bool $returnsId = true): int {
		$columns = array_keys($values);
		$sql = 'INSERT INTO *PREFIX*' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
			. implode(', ', array_map(static fn (string $c) => ':' . $c, $columns)) . ')';
		try {
			$this->run($sql, $values);
		} catch (\PDOException $e) {
			if (self::isUniqueViolation($e)) {
				throw new DuplicateEntryException($e->getMessage(), 0, $e);
			}
			throw $e;
		}
		if (!$returnsId) {
			return 0;
		}
		$name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? $this->tablePrefix . $table . '_id_seq' : null;
		return (int) $this->pdo->lastInsertId($name);
	}

	public function transactional(callable $fn): mixed {
		// Verschachtelte Aufrufe laufen in der äußeren Transaktion mit.
		if ($this->depth > 0) {
			return $fn();
		}
		$this->depth++;
		$this->pdo->beginTransaction();
		try {
			$result = $fn();
			$this->pdo->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->pdo->rollBack();
			throw $e;
		} finally {
			$this->depth--;
		}
	}

	public function tableExists(string $table): bool {
		$name = $this->tablePrefix . $table;
		return match ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
			'sqlite' => $this->fetchValue("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name", ['name' => $name]) !== null,
			default => $this->fetchValue(
				'SELECT 1 FROM information_schema.tables WHERE table_name = :name',
				['name' => $name],
			) !== null,
		};
	}

	private function run(string $sql, array $params): \PDOStatement {
		$statement = $this->pdo->prepare(str_replace('*PREFIX*', $this->tablePrefix, $sql));
		foreach ($params as $name => $value) {
			$type = match (true) {
				$value === null => PDO::PARAM_NULL,
				is_int($value) => PDO::PARAM_INT,
				is_bool($value) => PDO::PARAM_INT,
				default => PDO::PARAM_STR,
			};
			$statement->bindValue(':' . ltrim((string) $name, ':'), is_bool($value) ? (int) $value : $value, $type);
		}
		$statement->execute();
		return $statement;
	}

	private static function isUniqueViolation(\PDOException $e): bool {
		$sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
		$driverCode = (int) ($e->errorInfo[1] ?? 0);
		return $sqlState === '23505'                                  // PostgreSQL
			|| ($sqlState === '23000' && $driverCode === 1062)        // MySQL/MariaDB
			|| ($sqlState === '23000' && str_contains($e->getMessage(), 'UNIQUE constraint failed')); // SQLite
	}
}
