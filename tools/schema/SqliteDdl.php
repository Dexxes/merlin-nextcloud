<?php

declare(strict_types=1);

/**
 * Bildet das Nextcloud-Schema (NextcloudSchema::load()) auf SQLite ab: welcher
 * deklarierte Spaltentyp im Standalone-Schema erwartet wird, und auf Wunsch
 * gleich das CREATE TABLE dazu (`php tools/schema/print-sqlite-ddl.php`), als
 * Vorlage für neue Dateien in standalone/migrations/.
 */

namespace Merlin\Tools\Schema;

final class SqliteDdl {
	/** Deklarierter SQLite-Typ für eine Spalte aus dem Nextcloud-Schema. */
	public static function columnType(array $column): string {
		return match ($column['type']) {
			'bigint' => $column['autoincrement'] ? 'INTEGER' : 'BIGINT',
			'integer' => 'INTEGER',
			'smallint' => 'SMALLINT',
			'boolean' => 'BOOLEAN',
			'string' => 'VARCHAR(' . ($column['length'] ?? 255) . ')',
			'text', 'json' => 'TEXT',
			'datetime', 'datetimetz' => 'DATETIME',
			'date' => 'DATE',
			'float', 'decimal' => 'DOUBLE PRECISION',
			'blob', 'binary' => 'BLOB',
			default => throw new \RuntimeException('Unbekannter Spaltentyp ' . $column['type']),
		};
	}

	/** SQL-Literal für einen Default, wie SQLite es in PRAGMA table_info zurückgibt. */
	public static function defaultLiteral(array $column): ?string {
		$default = $column['default'];
		if ($default === null) {
			return null;
		}
		if (is_bool($default)) {
			return $default ? '1' : '0';
		}
		if (is_int($default) || is_float($default)) {
			return (string) $default;
		}
		return "'" . str_replace("'", "''", (string) $default) . "'";
	}

	public static function createTable(string $name, array $table): string {
		$lines = [];
		$singleAutoPk = count($table['primary']) === 1
			&& ($table['columns'][$table['primary'][0]]['autoincrement'] ?? false);
		foreach ($table['columns'] as $columnName => $column) {
			$line = '    ' . $columnName . ' ' . self::columnType($column);
			if ($singleAutoPk && $columnName === $table['primary'][0]) {
				$line .= ' PRIMARY KEY AUTOINCREMENT';
			}
			if ($column['notnull']) {
				$line .= ' NOT NULL';
			}
			$default = self::defaultLiteral($column);
			if ($default !== null) {
				$line .= ' DEFAULT ' . $default;
			}
			$lines[] = $line;
		}
		if (!$singleAutoPk && $table['primary'] !== []) {
			$lines[] = '    PRIMARY KEY (' . implode(', ', $table['primary']) . ')';
		}
		$sql = "CREATE TABLE {$name} (\n" . implode(",\n", $lines) . "\n);\n";
		foreach ($table['indexes'] as $indexName => $index) {
			$sql .= 'CREATE ' . ($index['unique'] ? 'UNIQUE ' : '') . "INDEX {$indexName} ON {$name} ("
				. implode(', ', $index['columns']) . ");\n";
		}
		return $sql;
	}
}
