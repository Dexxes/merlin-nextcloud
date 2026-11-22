<?php

declare(strict_types=1);

/**
 * Migrationen des Standalone-Servers: frische SQLite-Datenbank anlegen,
 * zweiter Lauf wendet nichts mehr an, die Konto-Tabellen löschen per
 * Fremdschlüssel mit. Aufruf: php standalone/tests/test-migrations.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Merlin\Standalone\Db\Connection;
use Merlin\Standalone\Db\MigrationRunner;

$failures = 0;
function check(bool $ok, string $label): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
	if (!$ok) {
		$failures++;
	}
}

$dir = sys_get_temp_dir() . '/merlin-test-' . bin2hex(random_bytes(4));
$connection = Connection::sqlite($dir . '/merlin.sqlite');
$runner = new MigrationRunner($connection);

$first = $runner->migrate();
check($first !== [] && $first[0] === '001_initial', 'erster Lauf wendet 001_initial an');
check($runner->migrate() === [], 'zweiter Lauf wendet nichts an');

$pdo = $connection->pdo();
check(strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()) === 'wal', 'WAL-Modus');
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['merlin_articles', 'merlin_tags', 'merlin_highlights', 'merlin_comments', 'users', 'api_tokens', 'settings', 'user_settings'] as $table) {
	check(in_array($table, $tables, true), "Tabelle {$table}");
}

$now = gmdate('c');
$pdo->prepare('INSERT INTO users (username, email, password_hash, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
	->execute(['anna', 'anna@example.org', 'x', 'user', $now, $now]);
$userId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO api_tokens (user_id, name, token_hash, created_at) VALUES (?, ?, ?, ?)')
	->execute([$userId, 'Test', 'hash', $now]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
check((int) $pdo->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn() === 0, 'api_tokens löschen mit dem Nutzer mit');

// Autoincrement wie in Nextcloud: gelöschte IDs werden nicht neu vergeben.
$insert = $pdo->prepare("INSERT INTO merlin_tags (user_id, name, created_at) VALUES ('1', ?, '2026-01-01 00:00:00')");
$insert->execute(['a']);
$firstId = (int) $pdo->lastInsertId();
$pdo->exec('DELETE FROM merlin_tags');
$insert->execute(['b']);
check((int) $pdo->lastInsertId() > $firstId, 'merlin_*-IDs werden nicht wiederverwendet');

$pdo = null;
$connection = null;
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

if ($failures > 0) {
	echo "\n{$failures} Fehler\n";
	exit(1);
}
echo "\nalle Prüfungen ok\n";
