<?php

declare(strict_types=1);

namespace Merlin\Standalone\Db;

use Merlin\Core\Persistence\PdoConnection;
use PDO;

/**
 * Öffnet die SQLite-Datenbank des Servers als Connection-Port des Kerns
 * (Merlin\Core\Persistence\PdoConnection, ohne Tabellenpräfix). Vorerst nur
 * SQLite; MySQL/MariaDB und PostgreSQL kommen später, das SQL des Kerns
 * bleibt dafür portabel.
 */
final class Sqlite {
	public static function open(string $path): PdoConnection {
		if ($path !== ':memory:') {
			$dir = dirname($path);
			if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
				throw new \RuntimeException("Datenverzeichnis {$dir} lässt sich nicht anlegen.");
			}
		}
		$pdo = new PDO('sqlite:' . $path);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// Fremdschlüssel (Konto-Tabellen, ON DELETE CASCADE) gelten in SQLite nur,
		// wenn sie je Verbindung eingeschaltet werden.
		$pdo->exec('PRAGMA foreign_keys = ON');
		$pdo->exec('PRAGMA busy_timeout = 5000');
		if ($path !== ':memory:') {
			// Lesende Requests blockieren schreibende nicht (Extraktion nach der Antwort).
			$pdo->exec('PRAGMA journal_mode = WAL');
		}
		return new PdoConnection($pdo);
	}
}
