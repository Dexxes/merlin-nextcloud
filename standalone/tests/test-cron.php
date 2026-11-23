<?php

declare(strict_types=1);

/**
 * Tägliche Aufgaben des Standalone-Servers (Cron\DailyJobs): die Löschfrist
 * über bin/merlin cron (höchstens einmal am Tag, --force sofort) und ohne
 * System-Cron nach dem Öffnen des Readers. Gegenstück zu Nextclouds
 * BackgroundJob\RetentionCleanupJob; die Löschlogik selbst prüft
 * core/tests/test-retention.php.
 *
 * Aufruf: php standalone/tests/test-cron.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/support/TestServer.php';

use Merlin\Standalone\Cron\DailyJobs;
use Merlin\Standalone\Tests\TestServer;

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
	if (!$ok) {
		$failures++;
	}
}

$server = new TestServer('merlin-cron');
[$code, $out] = $server->cli('migrate');
check($code === 0, 'bin/merlin migrate', $out);
[$code, $out] = $server->cli('create-admin --username=admin --email=admin@example.org --password=geheim123');
check($code === 0, 'bin/merlin create-admin', $out);
$server->start();
$http = $server->http(...);
$pdo = $server->pdo();

$archive = static function (string $url, string $archivedAt) use ($pdo): void {
	$pdo->prepare("INSERT INTO merlin_articles (user_id, url, title, content, is_archived, archived_at, created_at, updated_at) VALUES ('1', ?, 'A', '', 1, ?, '2026-01-01 00:00:00', '2026-01-01 00:00:00')")
		->execute([$url, $archivedAt]);
};
$count = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM merlin_articles')->fetchColumn();
$lastRun = static fn (): int => (int) $pdo->query("SELECT value FROM settings WHERE name = '" . DailyJobs::LAST_RUN . "'")->fetchColumn();

try {
	$admin = $server->tokenFor('admin', 'geheim123');
	[$status] = $http('PUT', '/api/admin/retention', ['days' => 30, 'favoritesDays' => 0], $admin);
	check($status === 200, 'Admin setzt Löschfrist 30 Tage');

	$archive('https://example.org/alt', gmdate('Y-m-d H:i:s', time() - 40 * 86400));
	$archive('https://example.org/neu', gmdate('Y-m-d H:i:s', time() - 5 * 86400));

	[$code, $out] = $server->cli('cron');
	check($code === 0 && str_contains($out, '1 Artikel'), 'bin/merlin cron löscht den abgelaufenen Artikel', $out);
	check($count() === 1, 'der jüngere bleibt');
	check(abs($lastRun() - time()) < 60, 'Zeitpunkt des Laufs gespeichert');

	$archive('https://example.org/alt2', gmdate('Y-m-d H:i:s', time() - 40 * 86400));
	[$code, $out] = $server->cli('cron');
	check($code === 0 && str_contains($out, 'Nichts fällig') && $count() === 2, 'zweiter Lauf am selben Tag tut nichts', $out);
	[$code, $out] = $server->cli('cron --force');
	check($code === 0 && str_contains($out, '1 Artikel') && $count() === 1, '--force läuft sofort', $out);

	// Ohne System-Cron: der Reader holt den Lauf nach, wenn er fällig ist.
	$archive('https://example.org/alt3', gmdate('Y-m-d H:i:s', time() - 40 * 86400));
	$jar = $server->dir . '/cookies-admin.txt';
	$http('GET', '/', null, [], null, $jar);
	usleep(300_000);
	check($count() === 2, 'Reader: nicht fällig, nichts gelöscht');
	$pdo->exec("UPDATE settings SET value = '" . (time() - DailyJobs::INTERVAL - 10) . "' WHERE name = '" . DailyJobs::LAST_RUN . "'");
	$http('GET', '/', null, [], null, $jar);
	for ($i = 0; $i < 20 && $count() !== 1; $i++) {
		usleep(100_000);
	}
	check($count() === 1 && abs($lastRun() - time()) < 60, 'Reader: fälliger Lauf nach der Antwort');

	check(!str_contains($server->log(), 'PHP Fatal') && !str_contains($server->log(), 'fehlgeschlagen'), 'keine Fehler im Log');
} finally {
	$server->stop();
}

echo $failures === 0 ? "\nalle Prüfungen ok\n" : "\n{$failures} Fehler\n";
exit($failures === 0 ? 0 : 1);
