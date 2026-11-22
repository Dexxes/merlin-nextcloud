<?php

declare(strict_types=1);

/**
 * Schema-Parität: Die merlin_*-Tabellen des Standalone-Servers
 * (standalone/migrations/*.sql) müssen dem Stand der Nextcloud-Migrationen
 * (nextcloud/lib/Migration/) entsprechen: dieselben Tabellen, Spalten,
 * Typen, Nullbarkeit, Defaults, Primärschlüssel und Indizes. Fehlt in einer
 * Variante eine Spalte, schlägt der Test fehl.
 *
 * Läuft ohne Nextcloud und ohne Composer: php tools/test-schema-parity.php
 */

require_once __DIR__ . '/schema/NextcloudSchema.php';
require_once __DIR__ . '/schema/SqliteDdl.php';

use Merlin\Tools\Schema\NextcloudSchema;
use Merlin\Tools\Schema\SqliteDdl;

$errors = [];
$checks = 0;

function fail(string $message): void {
	global $errors;
	$errors[] = $message;
}

$nextcloud = NextcloudSchema::load();
if (count($nextcloud) < 5) {
	fwrite(STDERR, "Nextcloud-Schema unvollständig gelesen (" . count($nextcloud) . " Tabellen)\n");
	exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$files = glob(dirname(__DIR__) . '/standalone/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
	$pdo->exec((string) file_get_contents($file));
}

$standaloneTables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'merlin\\_%' ESCAPE '\\'")
	->fetchAll(PDO::FETCH_COLUMN);
sort($standaloneTables);

foreach (array_diff(array_keys($nextcloud), $standaloneTables) as $table) {
	fail("Tabelle {$table} fehlt im Standalone-Schema");
}
foreach (array_diff($standaloneTables, array_keys($nextcloud)) as $table) {
	fail("Tabelle {$table} gibt es nur im Standalone-Schema");
}

foreach ($nextcloud as $tableName => $expected) {
	if (!in_array($tableName, $standaloneTables, true)) {
		continue;
	}
	$actual = [];
	foreach ($pdo->query("PRAGMA table_info({$tableName})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$actual[$row['name']] = $row;
	}

	foreach (array_diff(array_keys($expected['columns']), array_keys($actual)) as $column) {
		fail("{$tableName}.{$column} fehlt im Standalone-Schema");
	}
	foreach (array_diff(array_keys($actual), array_keys($expected['columns'])) as $column) {
		fail("{$tableName}.{$column} gibt es nur im Standalone-Schema");
	}

	$actualPk = [];
	foreach ($actual as $name => $row) {
		if ((int) $row['pk'] > 0) {
			$actualPk[(int) $row['pk']] = $name;
		}
	}
	ksort($actualPk);
	$checks++;
	if (array_values($actualPk) !== $expected['primary']) {
		fail("{$tableName}: Primärschlüssel (" . implode(', ', $actualPk) . ') statt (' . implode(', ', $expected['primary']) . ')');
	}

	foreach ($expected['columns'] as $columnName => $column) {
		if (!isset($actual[$columnName])) {
			continue;
		}
		$row = $actual[$columnName];
		$checks += 3;
		$type = SqliteDdl::columnType($column);
		if (strtoupper((string) $row['type']) !== $type) {
			fail("{$tableName}.{$columnName}: Typ {$row['type']} statt {$type} (Nextcloud {$column['type']})");
		}
		// Ein INTEGER PRIMARY KEY ist in SQLite nie NULL, auch ohne NOT NULL.
		$isRowId = $expected['primary'] === [$columnName] && $column['autoincrement'];
		if (!$isRowId && (bool) $row['notnull'] !== $column['notnull']) {
			fail("{$tableName}.{$columnName}: " . ($column['notnull'] ? 'muss NOT NULL sein' : 'darf nicht NOT NULL sein'));
		}
		$default = SqliteDdl::defaultLiteral($column);
		if ($row['dflt_value'] !== $default) {
			fail("{$tableName}.{$columnName}: Default " . var_export($row['dflt_value'], true) . ' statt ' . var_export($default, true));
		}
	}

	$actualIndexes = [];
	foreach ($pdo->query("PRAGMA index_list({$tableName})")->fetchAll(PDO::FETCH_ASSOC) as $index) {
		if ($index['origin'] === 'pk') {
			continue;
		}
		$columns = $pdo->query("PRAGMA index_info({$index['name']})")->fetchAll(PDO::FETCH_ASSOC);
		usort($columns, static fn ($a, $b) => $a['seqno'] <=> $b['seqno']);
		$actualIndexes[$index['name']] = [
			'columns' => array_column($columns, 'name'),
			'unique' => (bool) $index['unique'],
		];
	}
	ksort($actualIndexes);
	foreach ($expected['indexes'] as $indexName => $index) {
		$checks++;
		if (!isset($actualIndexes[$indexName])) {
			fail("{$tableName}: Index {$indexName} (" . implode(', ', $index['columns']) . ') fehlt im Standalone-Schema');
		} elseif ($actualIndexes[$indexName] !== $index) {
			fail("{$tableName}: Index {$indexName} ist " . json_encode($actualIndexes[$indexName]) . ' statt ' . json_encode($index));
		}
	}
	foreach (array_diff(array_keys($actualIndexes), array_keys($expected['indexes'])) as $indexName) {
		fail("{$tableName}: Index {$indexName} gibt es nur im Standalone-Schema");
	}
}

if ($errors !== []) {
	foreach ($errors as $error) {
		fwrite(STDERR, "FAIL {$error}\n");
	}
	fwrite(STDERR, count($errors) . " Abweichung(en) zwischen Nextcloud- und Standalone-Schema.\n"
		. "Vorlage für die fehlenden Teile: php tools/schema/print-sqlite-ddl.php <tabelle>\n");
	exit(1);
}

echo 'ok: ' . count($nextcloud) . " merlin_*-Tabellen, {$checks} Prüfungen, Nextcloud und Standalone gleich\n";
