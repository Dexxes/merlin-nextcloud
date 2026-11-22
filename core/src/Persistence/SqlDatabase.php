<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Port\Connection;
use Merlin\Core\Port\Database;

/**
 * Database-Port (Löschen über mehrere Tabellen, ArticleDeletionService) auf
 * einer Connection. Tabellen- und Spaltennamen kommen nur aus dem Kern, nie
 * aus Eingaben; sie werden trotzdem auf Bezeichner-Zeichen geprüft.
 */
final class SqlDatabase implements Database {
	public function __construct(private Connection $db) {
	}

	public function transactional(callable $fn): mixed {
		return $this->db->transactional($fn);
	}

	public function tableExists(string $table): bool {
		return $this->db->tableExists(self::name($table));
	}

	public function selectIds(string $table, string $column, array $equals, array $in = []): array {
		[$where, $params] = self::where($equals, $in);
		return array_map(
			static fn (array $row) => (int) $row[$column],
			$this->db->fetchAll('SELECT ' . self::name($column) . ' FROM *PREFIX*' . self::name($table) . $where, $params),
		);
	}

	public function delete(string $table, array $equals = [], array $in = []): int {
		[$where, $params] = self::where($equals, $in);
		return $this->db->execute('DELETE FROM *PREFIX*' . self::name($table) . $where, $params);
	}

	public function deleteWhereInSelect(string $table, string $column, string $subTable, array $subEquals): int {
		[$where, $params] = self::where($subEquals, []);
		return $this->db->execute(
			'DELETE FROM *PREFIX*' . self::name($table) . ' WHERE ' . self::name($column)
			. ' IN (SELECT id FROM *PREFIX*' . self::name($subTable) . $where . ')',
			$params,
		);
	}

	public function deleteWhereNotInSelect(string $table, string $column, string $subTable): int {
		return $this->db->execute(
			'DELETE FROM *PREFIX*' . self::name($table) . ' WHERE ' . self::name($column)
			. ' NOT IN (SELECT id FROM *PREFIX*' . self::name($subTable) . ')',
		);
	}

	/**
	 * @param array<string, string> $equals
	 * @param array<string, list<int>> $in
	 * @return array{0: string, 1: array<string, mixed>}
	 */
	private static function where(array $equals, array $in): array {
		$conditions = [];
		$params = [];
		$n = 0;
		foreach ($equals as $column => $value) {
			$conditions[] = self::name($column) . ' = :p' . $n;
			$params['p' . $n++] = $value;
		}
		foreach ($in as $column => $ids) {
			if ($ids === []) {
				// Leere Liste trifft nichts (wie IN () in Nextclouds QueryBuilder).
				$conditions[] = '1 = 0';
				continue;
			}
			$placeholders = [];
			foreach ($ids as $id) {
				$placeholders[] = ':p' . $n;
				$params['p' . $n++] = (int) $id;
			}
			$conditions[] = self::name($column) . ' IN (' . implode(', ', $placeholders) . ')';
		}
		return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
	}

	private static function name(string $identifier): string {
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
			throw new \InvalidArgumentException("Ungültiger SQL-Bezeichner: {$identifier}");
		}
		return $identifier;
	}
}
