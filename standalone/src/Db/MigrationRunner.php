<?php

declare(strict_types=1);

namespace Merlin\Standalone\Db;

use PDO;

/**
 * Wendet die noch fehlenden SQL-Dateien aus standalone/migrations/ in
 * Dateinamen-Reihenfolge an, jede in einer eigenen Transaktion.
 * Übernommen aus merlin-standalone-server (src/Migration/MigrationRunner.php,
 * Stand fe8ed23), Verzeichnis jetzt standalone/migrations/.
 */
final class MigrationRunner {
	private string $dir;

	public function __construct(private Connection $connection, ?string $dir = null) {
		$this->dir = $dir ?? dirname(__DIR__, 2) . '/migrations';
	}

	/** @return string[] Namen der neu angewandten Migrationen */
	public function migrate(): array {
		$db = $this->connection->pdo();
		$db->exec(
			'CREATE TABLE IF NOT EXISTS schema_migrations (
				version TEXT PRIMARY KEY,
				applied_at TEXT NOT NULL
			)'
		);

		$applied = $db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

		$newlyApplied = [];
		foreach ($this->pending($applied) as $version => $file) {
			$sql = file_get_contents($file);
			if ($sql === false) {
				throw new \RuntimeException("Migration {$file} lässt sich nicht lesen.");
			}

			$db->beginTransaction();
			try {
				$db->exec($sql);
				$db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)')
					->execute(['version' => $version, 'applied_at' => gmdate('c')]);
				$db->commit();
			} catch (\Throwable $e) {
				$db->rollBack();
				throw new \RuntimeException("Migration {$version} fehlgeschlagen: {$e->getMessage()}", 0, $e);
			}
			$newlyApplied[] = $version;
		}

		return $newlyApplied;
	}

	/**
	 * @param string[] $applied
	 * @return array<string, string> Version => Datei
	 */
	private function pending(array $applied): array {
		$files = glob($this->dir . '/*.sql') ?: [];
		sort($files, SORT_STRING);
		$pending = [];
		foreach ($files as $file) {
			$version = basename($file, '.sql');
			if (!in_array($version, $applied, true)) {
				$pending[$version] = $file;
			}
		}
		return $pending;
	}
}
