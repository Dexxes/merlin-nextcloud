<?php

declare(strict_types=1);

namespace Merlin\Standalone\Db;

use PDO;

/**
 * Die eine PDO-Verbindung des Servers. Vorerst nur SQLite (MySQL/MariaDB und
 * PostgreSQL kommen später, das SQL des Kerns bleibt dafür portabel).
 */
final class Connection {
	public function __construct(private PDO $pdo) {
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		$this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
	}

	public static function sqlite(string $path): self {
		if ($path !== ':memory:') {
			$dir = dirname($path);
			if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
				throw new \RuntimeException("Datenverzeichnis {$dir} lässt sich nicht anlegen.");
			}
		}
		$pdo = new PDO('sqlite:' . $path);
		$connection = new self($pdo);
		// Fremdschlüssel (Konto-Tabellen, ON DELETE CASCADE) gelten in SQLite nur,
		// wenn sie je Verbindung eingeschaltet werden.
		$pdo->exec('PRAGMA foreign_keys = ON');
		$pdo->exec('PRAGMA busy_timeout = 5000');
		if ($path !== ':memory:') {
			// Lesende Requests blockieren schreibende nicht (Extraktion nach der Antwort).
			$pdo->exec('PRAGMA journal_mode = WAL');
		}
		return $connection;
	}

	public function pdo(): PDO {
		return $this->pdo;
	}

	public function driver(): string {
		return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
	}
}
