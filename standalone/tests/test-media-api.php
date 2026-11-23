<?php

declare(strict_types=1);

/**
 * Vorlesen, PDF, Medienquellen und YouTube-Seite des Standalone-Servers über
 * HTTP (Handler aus dem Kern: Api\ArticleStreamApi, Api\MediaApi), für den
 * Besitzer und hinter einem Share-Link. Der Piper-Daemon läuft im Test nicht:
 * Vorlesen endet mit 503, wie in Nextcloud ohne Daemon. Echte PDFs von außen
 * holt der Test nicht (SSRF-Schutz und kein Netz nötig); die Durchreichung
 * selbst prüft core/tests/test-pdf-proxy.php.
 *
 * Aufruf: php standalone/tests/test-media-api.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/support/TestServer.php';

use Merlin\Standalone\Tests\TestServer;

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
	if (!$ok) {
		$failures++;
	}
}

$server = new TestServer('merlin-media');
[$code, $out] = $server->cli('migrate');
check($code === 0, 'bin/merlin migrate', $out);
[$code, $out] = $server->cli('create-admin --username=admin --email=admin@example.org --password=geheim123');
check($code === 0, 'bin/merlin create-admin', $out);
$server->start();
$http = $server->http(...);
$jar = (string) tempnam(sys_get_temp_dir(), 'merlin-media-cookies');
$anon = static fn (string $method, string $path, ?array $json = null) => $server->http($method, $path, $json, [], null, $jar);

try {
	$owner = $server->tokenFor('admin', 'geheim123');
	[$status] = $http('POST', '/admin/users', ['username' => 'bea', 'email' => 'bea@example.org', 'password' => 'passwort1', 'role' => 'user'], $owner);
	check($status === 201, 'zweites Konto anlegen');
	$bea = $server->tokenFor('bea', 'passwort1');

	$pdo = $server->pdo();
	$insert = $pdo->prepare('INSERT INTO merlin_articles (user_id, url, title, content, excerpt, site_name, reading_time, category, created_at, updated_at)'
		. " VALUES ('1', ?, ?, ?, NULL, 'example.org', 2, ?, '2026-02-01 10:00:00', '2026-02-01 10:00:00')");
	$saved = static function (string $title, string $content, ?string $category = null) use ($pdo, $insert): int {
		$insert->execute(['https://example.org/' . rawurlencode($title), $title, $content, $category]);
		return (int) $pdo->lastInsertId();
	};
	$id = $saved('Text', '<p>Ein Absatz zum Vorlesen.</p>');
	$hls = 'https://media.example.org/stream/master.m3u8';
	$videoId = $saved('Video', '<div class="merlin-media" data-media-kind="video" data-media-delivery="hls" data-media-src="' . $hls . '"></div><p>Text</p>');
	$audioId = $saved('Audio', '<div class="merlin-media" data-media-kind="audio" data-media-delivery="file" data-media-src="https://media.example.org/folge.mp3"></div>');

	echo "Vorlesen\n";
	[$status, $error, , $headers] = $http('GET', "/api/articles/{$id}/tts?lang=de", null, $owner);
	check($status === 503 && ($error['error'] ?? null) === 'TTS daemon unavailable', 'ohne Daemon: 503', "{$status} " . json_encode($error));
	check(str_starts_with($headers['content-type'] ?? '', 'application/json'), 'Fehler als JSON');
	[$status, $error] = $http('GET', "/api/articles/{$id}/tts", null, $bea);
	check($status === 404 && ($error['error'] ?? null) === 'Article not found', 'fremder Artikel: 404');
	[$status] = $http('GET', "/api/articles/{$id}/tts");
	check($status === 401, 'ohne Anmeldung: 401');

	echo "PDF\n";
	[$status, $error] = $http('GET', "/api/articles/{$id}/pdf", null, $owner);
	check($status === 404 && ($error['error'] ?? null) === 'Not a PDF article', 'kein PDF-Artikel: 404', (string) $status);
	[$status, $error] = $http('GET', "/api/articles/{$id}/pdf", null, $bea);
	check($status === 404 && ($error['error'] ?? null) === 'Article not found', 'fremder Artikel: 404');

	echo "Medien\n";
	[$status, $media] = $http('GET', "/api/articles/{$id}/media", null, $owner);
	check($status === 200 && $media === ['available' => false], 'ohne Quelle: available false', json_encode($media));
	[$status, $media] = $http('GET', "/api/articles/{$videoId}/media", null, $owner);
	check($status === 200 && ($media['available'] ?? null) === true && $media['kind'] === 'video' && $media['delivery'] === 'hls'
		&& ($media['variants'][0]['url'] ?? null) === $hls && $media['defaultIndex'] === 0, 'Marker: HLS-Video', json_encode($media));
	[$status, $stream] = $http('GET', "/api/articles/{$videoId}/video-stream", null, $owner);
	check($status === 200 && $stream === ['available' => true, 'type' => 'hls', 'variants' => $media['variants'], 'defaultIndex' => 0], 'altes Format', json_encode($stream));
	[$status, $stream] = $http('GET', "/api/articles/{$audioId}/video-stream", null, $owner);
	check($status === 200 && $stream === ['available' => false], 'altes Format nur für HLS-Video');
	[$status, $media] = $http('GET', "/api/articles/{$audioId}/media", null, $owner);
	check(($media['kind'] ?? null) === 'audio' && $media['delivery'] === 'file', 'Audio-Datei');
	[$status] = $http('GET', "/api/articles/{$videoId}/media", null, $bea);
	check($status === 404, 'fremder Artikel: 404');

	echo "YouTube-Seite\n";
	[$status, , $html, $headers] = $http('GET', '/api/youtube-embed?v=dQw4w9WgXcQ&t=42');
	check($status === 200 && str_starts_with($headers['content-type'] ?? '', 'text/html'), 'ohne Anmeldung: 200 HTML');
	check(str_contains($html, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?') && str_contains($html, 'start=42')
		&& str_contains($html, 'origin=' . rawurlencode($server->base)), 'iframe mit Startzeit und origin', $html);
	check(str_contains($headers['content-security-policy'] ?? '', 'frame-ancestors *')
		&& str_contains($headers['content-security-policy'] ?? '', 'frame-src https://www.youtube-nocookie.com'), 'eigene CSP', $headers['content-security-policy'] ?? '');
	[, , $html] = $http('GET', '/api/youtube-embed?v=abc&t=1x');
	check(!str_contains($html, 'start='), 'unsinnige Startzeit wird verworfen');
	[$status, , $body] = $http('GET', '/api/youtube-embed?v=' . rawurlencode('"><script>'));
	check($status === 400 && $body === 'Invalid video id', 'ungültige ID: 400');

	echo "Hinter einem Share-Link\n";
	[$status, $share] = $http('POST', "/api/articles/{$id}/share", [], $owner);
	check($status === 201, 'Link anlegen');
	$token = $share['token'];
	[$status, $error] = $anon('GET', "/s/{$token}/tts");
	check($status === 503 && ($error['error'] ?? null) === 'TTS daemon unavailable', 'Vorlesen: bis zum Daemon', (string) $status);
	[$status, $error] = $anon('GET', "/s/{$token}/pdf");
	check($status === 404 && ($error['error'] ?? null) === 'Not a PDF article', 'PDF: kein PDF-Artikel');
	[$status] = $anon('GET', '/s/' . str_repeat('0', 32) . '/tts');
	check($status === 404, 'unbekannter Token: 404');

	[$status, $locked] = $http('POST', "/api/articles/{$videoId}/share", ['password' => 'geheim-123'], $owner);
	$lockedToken = $locked['token'];
	[$status, $error] = $anon('GET', "/s/{$lockedToken}/tts");
	check($status === 401 && ($error['locked'] ?? null) === true, 'mit Passwort, gesperrt: 401', json_encode($error));
	[$status] = $anon('GET', "/s/{$lockedToken}/pdf");
	check($status === 401, 'PDF gesperrt: 401');
	[$status] = $anon('POST', "/s/{$lockedToken}/unlock", ['password' => 'geheim-123']);
	check($status === 200, 'entsperren');
	[$status] = $anon('GET', "/s/{$lockedToken}/tts");
	check($status === 503, 'nach dem Entsperren: bis zum Daemon');

	[$status] = $http('DELETE', "/api/articles/{$id}/share", null, $owner);
	[$status] = $anon('GET', "/s/{$token}/tts");
	check($status === 404, 'widerrufen: 404');

	$log = $server->log();
	check(!preg_match('/Unbehandelter Fehler|PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'keine unbehandelten Fehler im Log', $log);
} finally {
	$server->stop();
	@unlink($jar);
}

if ($failures > 0) {
	echo "\n{$failures} Fehler\n";
	exit(1);
}
echo "\nalle Prüfungen ok\n";
