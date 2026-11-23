<?php

declare(strict_types=1);

/**
 * Markierungen, Kommentare und Share-Links des Standalone-Servers über HTTP
 * (Handler aus dem Kern, core/src/Api/routes.php). Dieselben Abläufe wie
 * nextcloud/tests/Api/CommentApiTest.php und ShareApiTest.php gegen die
 * Nextcloud-App, dazu die Push-Kanäle (Server-Sent Events), Gast-Rechte über
 * X-Merlin-Guest-Name, Honeypot, Brute-Force-Bremse beim Passwort und das
 * Rate-Limit der Gast-Schreibrouten.
 *
 * Aufruf: php standalone/tests/test-discussion-api.php
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

$server = new TestServer('merlin-discussion');
[$code, $out] = $server->cli('migrate');
check($code === 0, 'bin/merlin migrate', $out);
[$code, $out] = $server->cli('create-admin --username=admin --email=admin@example.org --password=geheim123');
check($code === 0, 'bin/merlin create-admin', $out);
$server->start();
$http = $server->http(...);

try {
	$owner = $server->tokenFor('admin', 'geheim123');
	[$status] = $http('POST', '/admin/users', ['username' => 'bea', 'email' => 'bea@example.org', 'password' => 'passwort1', 'role' => 'user'], $owner);
	check($status === 201, 'zweites Konto anlegen');
	$bea = $server->tokenFor('bea', 'passwort1');

	$pdo = $server->pdo();
	$insert = $pdo->prepare('INSERT INTO merlin_articles (user_id, url, title, content, excerpt, site_name, reading_time, category, created_at, updated_at)'
		. " VALUES ('1', ?, ?, ?, 'Auszug', 'example.org', 2, ?, '2026-02-01 10:00:00', '2026-02-01 10:00:00')");
	$saved = static function (string $title, ?string $category = null) use ($pdo, $insert): int {
		$insert->execute(['https://example.org/' . rawurlencode($title), $title, "<p>Dies ist ein Absatz. {$title}: Zweiter Absatz mit Text.</p>", $category]);
		return (int) $pdo->lastInsertId();
	};
	$id = $saved('Geteilter Artikel');
	$pdfId = $saved('Ein PDF', 'PDF');

	echo "Markierungen\n";
	$passage = ['highlightedText' => 'Dies ist ein Absatz', 'startXpath' => 'p[1]', 'startOffset' => 0, 'endXpath' => 'p[1]', 'endOffset' => 19];
	[$status, $highlight] = $http('POST', "/api/articles/{$id}/highlights", $passage + ['color' => 'yellow'], $owner);
	check($status === 201 && ($highlight['authorType'] ?? null) === 'owner' && ($highlight['color'] ?? null) === 'yellow', 'anlegen: 201', json_encode($highlight));
	[$status, $error] = $http('POST', "/api/articles/{$id}/highlights", ['highlightedText' => 'x', 'startXpath' => '/html/body', 'endXpath' => '/html/body'], $owner);
	check($status === 400 && ($error['error'] ?? null) === 'highlight_invalid', 'ungültiger XPath: 400');
	[$status] = $http('POST', "/api/articles/{$id}/highlights", $passage, $bea);
	check($status === 404, 'fremder Artikel: 404');
	[$status] = $http('POST', "/api/articles/{$id}/highlights", $passage + ['pdfPage' => 1, 'pdfRects' => [[0.1, 0.1, 0.2, 0.05]]], $owner);
	check($status === 400, 'PDF-Stelle an einem HTML-Artikel: 400');
	[$status, $pdfHighlight] = $http('POST', "/api/articles/{$pdfId}/highlights", ['highlightedText' => 'Seite eins', 'pdfPage' => 1, 'pdfRects' => [[0.1, 0.1, 0.2, 0.05]]], $owner);
	check($status === 201 && ($pdfHighlight['pdfPage'] ?? null) === 1 && ($pdfHighlight['startXpath'] ?? null) === 'pdf[1]/page[1]', 'PDF-Markierung', json_encode($pdfHighlight));
	[$status, $list] = $http('GET', "/api/articles/{$id}/highlights", null, $owner);
	check($status === 200 && array_column($list, 'id') === [$highlight['id']], 'Liste');
	[$status, $list] = $http('GET', "/api/articles/{$id}/highlights", null, $bea);
	check($status === 200 && $list === [], 'fremde Markierungen bleiben unsichtbar');
	[$status, $recolored] = $http('PUT', "/api/highlights/{$highlight['id']}", $passage + ['color' => 'green'], $owner);
	check($status === 200 && ($recolored['color'] ?? null) === 'green', 'Farbe ändern');
	[$status] = $http('PUT', "/api/highlights/{$highlight['id']}", $passage + ['color' => 'lila'], $owner);
	check($status === 400, 'unbekannte Farbe: 400');
	[$status] = $http('PUT', "/api/highlights/{$highlight['id']}", $passage, $bea);
	check($status === 404, 'fremde Markierung ändern: 404');

	echo "Kommentare\n";
	[$status, $comment] = $http('POST', "/api/articles/{$id}/comments", ['body' => 'Kommentar an der Markierung', 'highlightId' => $highlight['id']], $owner);
	check($status === 201 && $comment['highlightId'] === $highlight['id'] && $comment['quotedText'] === 'Dies ist ein Absatz'
		&& $comment['authorType'] === 'owner' && $comment['authorName'] === 'admin', 'an der Markierung', json_encode($comment));
	[$status, $reply] = $http('POST', "/api/articles/{$id}/comments", ['body' => 'Antwort', 'parentId' => $comment['id']], $owner);
	check($status === 201 && $reply['parentId'] === $comment['id'], 'Antwort');
	[$status, $edited] = $http('PUT', "/api/comments/{$comment['id']}", ['body' => 'Geändert'], $owner);
	check($status === 200 && $edited['body'] === 'Geändert', 'bearbeiten');
	[$status] = $http('PUT', "/api/comments/{$comment['id']}", ['body' => 'fremd'], $bea);
	check($status === 404, 'fremden Kommentar bearbeiten: 404');
	[$status] = $http('POST', "/api/articles/{$id}/comments", ['body' => ''], $owner);
	check($status >= 400 && $status < 500, 'leerer Text: 4xx');
	[$status] = $http('GET', "/api/articles/{$id}/comments", null, $bea);
	check($status === 404, 'fremder Artikel: 404');
	[$status, $raw] = $http('DELETE', "/api/comments/{$reply['id']}", null, $owner);
	check($status === 204, 'Antwort löschen: 204');
	[$status, $payload] = $http('GET', "/api/articles/{$id}/comments", null, $owner);
	check($status === 200 && isset($payload['signature']) && count($payload['highlights'] ?? []) === 1
		&& in_array('Geändert', array_column($payload['comments'] ?? [], 'body'), true), 'Threads samt Änderungsmarke', json_encode($payload));
	// Endet von selbst; nicht abbrechen (ein abgebrochener Stream lässt im
	// eingebauten PHP-Server connection_aborted() für den Worker gesetzt).
	[$status, , $events] = $http('GET', "/api/articles/{$id}/comments/stream", null, $owner);
	check($status === 200 && str_contains($events, 'event: closed'), 'Push-Kanal ohne Share-Link endet mit closed', $events);

	echo "Share-Link\n";
	[$status, $state] = $http('GET', "/api/articles/{$id}/share", null, $owner);
	check($status === 200 && $state === ['enabled' => false], 'noch kein Link');
	[$status, $share] = $http('POST', "/api/articles/{$id}/share", ['allowComments' => true], $owner);
	$token = (string) ($share['token'] ?? '');
	check($status === 201 && $share['enabled'] === true && preg_match('/^[a-f0-9]{32}$/', $token) === 1 && $share['hasPassword'] === false
		&& $share['url'] === $server->base . '/s/' . $token && !isset($share['passwordHash']), 'anlegen', json_encode($share));
	[$status, $again] = $http('POST', "/api/articles/{$id}/share", [], $owner);
	check($status === 200 && ($again['token'] ?? null) === $token, 'erneut anlegen liefert denselben Link');
	[$status] = $http('POST', "/api/articles/{$id}/share", [], $bea);
	check($status === 404, 'fremder Artikel: 404');

	echo "Öffentliche Ansicht und Gäste\n";
	$guestJar = $server->dir . '/cookies-gast.txt';
	$anon = static fn (string $method, string $path, ?array $json = null, array $headers = []) => $http($method, $path, $json, $headers, null, $guestJar);
	[$status, $data] = $anon('GET', "/s/{$token}/data");
	check($status === 200 && $data['title'] === 'Geteilter Artikel' && str_contains($data['content'], 'Zweiter Absatz') && $data['allowComments'] === true
		&& $data['ownerName'] === 'admin' && count($data['highlights']) === 1 && isset($data['signature'], $data['guestColors']), 'Daten ohne Anmeldung', json_encode($data));
	[$status, $guest] = $anon('POST', "/s/{$token}/guest", ['authorName' => 'Gast CI']);
	check($status === 200 && $guest['name'] === 'Gast CI' && $guest['color'] !== '', 'Namen wählen', json_encode($guest));
	[$status] = $anon('POST', "/s/{$token}/guest", ['authorName' => 'admin']);
	check($status >= 400 && $status < 500, 'Name des Besitzers ist vergeben');
	[$status, $guestComment] = $anon('POST', "/s/{$token}/comments", ['authorName' => 'Gast CI', 'body' => 'Kommentar eines Gasts']);
	check($status === 201 && $guestComment['authorType'] === 'guest' && $guestComment['authorName'] === 'Gast CI', 'Gast kommentiert');
	[$status] = $anon('POST', "/s/{$token}/comments", ['authorName' => 'Gast CI', 'body' => 'Spam', 'website' => 'https://spam.example']);
	check($status === 400, 'Honeypot: 400');
	[$status] = $anon('PUT', "/s/{$token}/comments/{$guestComment['id']}", ['body' => 'Fremd geändert'], ['X-Merlin-Guest-Name' => rawurlencode('Jemand')]);
	check($status === 403, 'anderer Name darf nicht ändern');
	[$status, $guestEdited] = $anon('PUT', "/s/{$token}/comments/{$guestComment['id']}", ['body' => 'Selbst geändert'], ['X-Merlin-Guest-Name' => rawurlencode('Gast CI')]);
	check($status === 200 && $guestEdited['body'] === 'Selbst geändert', 'eigener Name darf ändern (X-Merlin-Guest-Name)');
	[$status] = $anon('PUT', "/s/{$token}/comments/{$comment['id']}", ['body' => 'Übernahme'], ['X-Merlin-Guest-Name' => 'admin']);
	check($status === 403, 'Kommentar des Besitzers bleibt unberührt');
	[$status, $guestHighlight] = $anon('POST', "/s/{$token}/highlights", ['authorName' => 'Gast CI', 'highlightedText' => 'Zweiter Absatz',
		'startXpath' => 'p[1]', 'startOffset' => 40, 'endXpath' => 'p[1]', 'endOffset' => 54]);
	check($status === 201 && $guestHighlight['authorType'] === 'guest', 'Gast markiert', json_encode($guestHighlight));
	[$status, $events] = $server->sse("/s/{$token}/events", 'event: comments', [], $guestJar);
	check($status === 200 && str_contains($events, 'event: comments') && str_contains($events, 'Selbst ge'), 'Push-Kanal der öffentlichen Ansicht', substr($events, -300));
	[$status] = $anon('DELETE', "/s/{$token}/highlights/{$guestHighlight['id']}", null, ['X-Merlin-Guest-Name' => rawurlencode('Gast CI')]);
	check($status === 204, 'Gast löscht eigene Markierung');
	[$status, $public] = $anon('GET', "/s/{$token}/comments");
	check($status === 200 && in_array('Selbst geändert', array_column($public['comments'], 'body'), true) && count($public['highlights']) === 1, 'Threads öffentlich');
	[$status, $ownerView] = $http('GET', "/api/articles/{$id}/comments", null, $owner);
	check(in_array('Selbst geändert', array_column($ownerView['comments'], 'body'), true), 'Besitzer sieht den Gast-Kommentar');

	[$status, $closed] = $http('PUT', "/api/articles/{$id}/share", ['allowComments' => false], $owner);
	check($status === 200 && $closed['allowComments'] === false && $closed['token'] === $token, 'Kommentare abschalten');
	[$status, $data] = $anon('GET', "/s/{$token}/data");
	check($status === 200 && $data['allowComments'] === false, 'Lesen geht weiter');
	[$status, $error] = $anon('POST', "/s/{$token}/comments", ['authorName' => 'Gast CI', 'body' => 'nicht erlaubt']);
	check($status === 403 && ($error['error'] ?? null) === 'comments_closed', 'Schreiben nicht: comments_closed');

	echo "Passwort\n";
	[$status, $protected] = $http('PUT', "/api/articles/{$id}/share", ['password' => 'geheim-123', 'allowComments' => true], $owner);
	check($status === 200 && $protected['hasPassword'] === true, 'Passwort setzen');
	$lockedJar = $server->dir . '/cookies-zweiter-gast.txt';
	[$status, $locked] = $http('GET', "/s/{$token}/data", null, [], null, $lockedJar);
	check($status === 401 && ($locked['locked'] ?? null) === true, 'gesperrt: 401');
	$start = microtime(true);
	[$status] = $http('POST', "/s/{$token}/unlock", ['password' => 'falsch'], [], null, $lockedJar);
	check($status === 403, 'falsches Passwort: 403');
	$attempts = (int) $pdo->query("SELECT COUNT(*) FROM security_attempts WHERE action = 'throttle:merlin_public_share_unlock'")->fetchColumn();
	check($attempts === 1, 'Fehlversuch für die Bremse gemerkt');
	[$status] = $http('POST', "/s/{$token}/unlock", ['password' => 'geheim-123'], [], null, $lockedJar);
	check($status === 200 && microtime(true) - $start >= 0.15, 'richtiges Passwort nach Wartezeit');
	$attempts = (int) $pdo->query("SELECT COUNT(*) FROM security_attempts WHERE action = 'throttle:merlin_public_share_unlock'")->fetchColumn();
	check($attempts === 0, 'Erfolg setzt die Bremse zurück');
	[$status, $data] = $http('GET', "/s/{$token}/data", null, [], null, $lockedJar);
	check($status === 200 && $data['title'] === 'Geteilter Artikel', 'entsperrt (Session)');

	[$status, $renewed] = $http('POST', "/api/articles/{$id}/share/regenerate", null, $owner);
	check($status === 200 && $renewed['token'] !== $token && $renewed['hasPassword'] === true, 'Token erneuern');
	[$status] = $http('GET', "/s/{$token}/data", null, [], null, $lockedJar);
	check($status === 404, 'alter Link: 404');
	$token = $renewed['token'];

	echo "Rate-Limit\n";
	[$status] = $http('PUT', "/api/articles/{$id}/share", ['password' => null], $owner);
	check($status === 200, 'Passwort entfernen');
	$limited = 0;
	for ($i = 0; $i < 21 && $limited === 0; $i++) {
		[$status] = $anon('POST', "/s/{$token}/guest", ['authorName' => 'Gast CI']);
		$limited = $status === 429 ? $i : 0;
	}
	check($limited === 18, 'guest: nach 20 Anfragen in 5 Minuten 429', "nach {$i}");
	[$status] = $anon('POST', "/s/{$token}/comments", ['authorName' => 'Gast CI', 'body' => 'Andere Route zählt getrennt']);
	check($status === 201, 'andere Route hat ihr eigenes Limit');

	echo "Widerrufen\n";
	[$status] = $http('DELETE', "/api/articles/{$id}/share", null, $bea);
	[$status2] = $anon('GET', "/s/{$token}/data");
	check($status === 204 && $status2 === 200, 'fremder Widerruf löscht nichts');
	[$status] = $http('DELETE', "/api/articles/{$id}/share", null, $owner);
	check($status === 204, 'widerrufen: 204');
	[$status] = $anon('GET', "/s/{$token}/data");
	check($status === 404, 'danach 404');
	[$status] = $anon('GET', "/s/{$token}/events");
	check($status === 404, 'Push-Kanal: 404');
	[$status] = $http('DELETE', "/api/highlights/{$highlight['id']}", null, $owner);
	check($status === 204, 'Markierung löschen: 204');
	[$status, $after] = $http('GET', "/api/articles/{$id}/comments", null, $owner);
	$kept = array_values(array_filter($after['comments'], static fn (array $c) => $c['id'] === $comment['id']));
	check($kept !== [] && $kept[0]['highlightId'] === null && $kept[0]['quotedText'] === 'Dies ist ein Absatz', 'Thread bleibt mit Zitat');

	$log = $server->log();
	check(!preg_match('/Unbehandelter Fehler|PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'keine unbehandelten Fehler im Log', $log);
} finally {
	$server->stop();
}

if ($failures > 0) {
	echo "\n{$failures} Fehler\n";
	exit(1);
}
echo "\nalle Prüfungen ok\n";
