<?php

declare(strict_types=1);

/**
 * Testharness für Service\ReportBackendRules (merlin-reports-Meldungsbackend).
 *
 * Aufruf: php core/tests/test-report-backend.php
 */

require_once __DIR__ . '/autoload.php';

use Merlin\Core\Service\ReportBackendRules as R;

$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
	echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
	if (!$ok) {
		$failed++;
	}
};

echo "endpoint\n";
$e = R::endpoint('https://dexxes.de/tools/merlin-reports/', 'ping');
$check($e['url'] === 'https://dexxes.de/tools/merlin-reports/index.php?action=ping', 'Verzeichnis mit Slash → index.php');
$check($e['user'] === null && $e['password'] === null, 'ohne Zugangsdaten kein Basic Auth');
$e = R::endpoint('https://dexxes.de/tools/merlin-reports', 'report');
$check($e['url'] === 'https://dexxes.de/tools/merlin-reports/index.php?action=report', 'Verzeichnis ohne Slash');
$e = R::endpoint('  https://example.com/r/index.php?x=1#y ', 'ping');
$check($e['url'] === 'https://example.com/r/index.php?action=ping', 'index.php bleibt, Query/Fragment fallen weg');
$e = R::endpoint('https://example.com', 'ping');
$check($e['url'] === 'https://example.com/index.php?action=ping', 'nur Host');
$e = R::endpoint('https://example.com:8443/r/', 'ping');
$check($e['url'] === 'https://example.com:8443/r/index.php?action=ping', 'Port bleibt');
$e = R::endpoint('https://merlin:p%40ss%3Aw@dexxes.de/tools/merlin-reports/', 'ping');
$check($e['url'] === 'https://dexxes.de/tools/merlin-reports/index.php?action=ping', 'Zugangsdaten verschwinden aus der URL');
$check($e['user'] === 'merlin' && $e['password'] === 'p@ss:w', 'Zugangsdaten werden dekodiert');
$e = R::endpoint('https://merlin@example.com/', 'ping');
$check($e['user'] === 'merlin' && $e['password'] === '', 'Nutzer ohne Passwort');
$check(R::endpoint('', 'ping') === null, 'leer → null');
$check(R::endpoint('ftp://example.com/', 'ping') === null, 'ftp → null');
$check(R::endpoint('file:///etc/passwd', 'ping') === null, 'file → null');
$check(R::endpoint('example.com/r/', 'ping') === null, 'ohne Schema → null');
$check(R::endpoint('https://example.com/', 'ping&x=1') === null, 'ungültige Action → null');

echo "pingStatus\n";
$check(R::pingStatus(200, '{"ok":true}') === 'ok', '200 + ok:true');
$check(R::pingStatus(200, '{"ok":false}') === 'unexpected', '200 + ok:false → unexpected');
$check(R::pingStatus(200, '<html>') === 'unexpected', '200 ohne JSON → unexpected');
$check(R::pingStatus(401, 'Zugriff verweigert.') === 'auth', '401 → auth');
$check(R::pingStatus(403, '') === 'auth', '403 → auth');
$check(R::pingStatus(404, '{"ok":true}') === 'error', '404 → error');
$check(R::pingStatus(301, '') === 'error', 'Redirect → error');

echo $failed === 0 ? "\nAlle Tests bestanden.\n" : "\n$failed Test(s) fehlgeschlagen.\n";
exit($failed === 0 ? 0 : 1);
