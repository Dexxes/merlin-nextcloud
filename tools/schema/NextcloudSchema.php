<?php

declare(strict_types=1);

/**
 * Liest das Tabellenschema der Nextcloud-App aus ihren Migrationsklassen,
 * ohne Nextcloud: Jede `changeSchema()` aus nextcloud/lib/Migration/ läuft der
 * Reihe nach gegen einen aufzeichnenden Schema-Stub (siehe ocp-stubs.php).
 * Datenschritte (`preSchemaChange`/`postSchemaChange`) laufen nicht.
 *
 * Ergebnis von `NextcloudSchema::load()`:
 *   [tabelle => ['columns' => [name => [type, notnull, default, length, autoincrement]],
 *               'primary' => [spalten], 'indexes' => [name => ['columns' => [...], 'unique' => bool]]]]
 *
 * Genutzt von tools/test-schema-parity.php (Vergleich mit standalone/migrations/).
 */

namespace Merlin\Tools\Schema;

require_once __DIR__ . '/ocp-stubs.php';

final class NextcloudSchema {
	/** @return array<string, array{columns: array<string, array<string, mixed>>, primary: string[], indexes: array<string, array{columns: string[], unique: bool}>}> */
	public static function load(?string $migrationDir = null): array {
		$migrationDir ??= dirname(__DIR__, 2) . '/nextcloud/lib/Migration';
		$files = glob($migrationDir . '/Version*.php') ?: [];
		sort($files, SORT_STRING);

		$schema = new RecordingSchema();
		$output = new SilentOutput();
		foreach ($files as $file) {
			require_once $file;
			$class = 'OCA\\Merlin\\Migration\\' . basename($file, '.php');
			// Konstruktoren verlangen IDBConnection & Co.; changeSchema() nutzt sie nicht.
			$step = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
			$step->changeSchema($output, static fn () => $schema, []);
		}

		return $schema->export();
	}
}

final class SilentOutput implements \OCP\Migration\IOutput {
	public function debug(string $message): void {
	}
	public function info($message): void {
	}
	public function warning($message): void {
	}
	public function startProgress($max = 0): void {
	}
	public function advance($step = 1, $description = ''): void {
	}
	public function finishProgress(): void {
	}
}

final class RecordingSchema implements \OCP\DB\ISchemaWrapper {
	/** @var array<string, RecordingTable> */
	private array $tables = [];

	public function hasTable($tableName): bool {
		return isset($this->tables[$tableName]);
	}

	public function createTable($tableName): RecordingTable {
		if (isset($this->tables[$tableName])) {
			throw new \RuntimeException("Tabelle {$tableName} existiert schon");
		}
		return $this->tables[$tableName] = new RecordingTable($tableName);
	}

	public function getTable($tableName): RecordingTable {
		return $this->tables[$tableName] ?? throw new \RuntimeException("Tabelle {$tableName} fehlt");
	}

	public function dropTable($tableName): self {
		unset($this->tables[$tableName]);
		return $this;
	}

	public function getTableNames(): array {
		return array_keys($this->tables);
	}

	public function export(): array {
		$out = [];
		ksort($this->tables);
		foreach ($this->tables as $name => $table) {
			$out[$name] = $table->export();
		}
		return $out;
	}
}

final class RecordingTable {
	/** @var array<string, RecordingColumn> */
	private array $columns = [];
	/** @var string[] */
	private array $primary = [];
	/** @var array<string, array{columns: string[], unique: bool}> */
	private array $indexes = [];

	public function __construct(private string $name) {
	}

	public function getName(): string {
		return $this->name;
	}

	public function addColumn($name, $typeName, array $options = []): RecordingColumn {
		if (isset($this->columns[$name])) {
			throw new \RuntimeException("Spalte {$this->name}.{$name} existiert schon");
		}
		return $this->columns[$name] = new RecordingColumn((string) $typeName, $options);
	}

	public function hasColumn($name): bool {
		return isset($this->columns[$name]);
	}

	public function getColumn($name): RecordingColumn {
		return $this->columns[$name] ?? throw new \RuntimeException("Spalte {$this->name}.{$name} fehlt");
	}

	public function changeColumn($name, array $options): self {
		$this->getColumn($name)->setOptions($options);
		return $this;
	}

	public function modifyColumn($name, array $options): self {
		return $this->changeColumn($name, $options);
	}

	public function dropColumn($name): self {
		unset($this->columns[$name]);
		foreach ($this->indexes as $indexName => $index) {
			if (in_array($name, $index['columns'], true)) {
				unset($this->indexes[$indexName]);
			}
		}
		return $this;
	}

	public function setPrimaryKey(array $columnNames, $indexName = false): self {
		$this->primary = array_values($columnNames);
		return $this;
	}

	public function addIndex(array $columnNames, $indexName = null, array $flags = [], array $options = []): self {
		return $this->index($columnNames, $indexName, false);
	}

	public function addUniqueIndex(array $columnNames, $indexName = null, array $options = []): self {
		return $this->index($columnNames, $indexName, true);
	}

	public function hasIndex($indexName): bool {
		return isset($this->indexes[$indexName]);
	}

	public function dropIndex($indexName): self {
		unset($this->indexes[$indexName]);
		return $this;
	}

	private function index(array $columnNames, $indexName, bool $unique): self {
		$indexName ??= $this->name . '_' . implode('_', $columnNames);
		if (isset($this->indexes[$indexName])) {
			throw new \RuntimeException("Index {$indexName} existiert schon");
		}
		$this->indexes[$indexName] = ['columns' => array_values($columnNames), 'unique' => $unique];
		return $this;
	}

	public function export(): array {
		$columns = [];
		foreach ($this->columns as $name => $column) {
			$columns[$name] = $column->export();
		}
		ksort($this->indexes);
		return ['columns' => $columns, 'primary' => $this->primary, 'indexes' => $this->indexes];
	}
}

final class RecordingColumn {
	private array $options = [];

	public function __construct(private string $type, array $options) {
		$this->setOptions($options);
	}

	public function setOptions(array $options): self {
		foreach ($options as $key => $value) {
			$this->options[strtolower((string) $key)] = $value;
		}
		return $this;
	}

	public function setNotnull($notnull): self {
		$this->options['notnull'] = (bool) $notnull;
		return $this;
	}

	public function setDefault($default): self {
		$this->options['default'] = $default;
		return $this;
	}

	public function setLength($length): self {
		$this->options['length'] = $length;
		return $this;
	}

	public function getType(): string {
		return $this->type;
	}

	public function export(): array {
		return [
			'type' => $this->type,
			// Doctrine: notnull ist ohne Angabe true.
			'notnull' => (bool) ($this->options['notnull'] ?? true),
			'default' => $this->options['default'] ?? null,
			'length' => isset($this->options['length']) ? (int) $this->options['length'] : null,
			'autoincrement' => (bool) ($this->options['autoincrement'] ?? false),
		];
	}
}
