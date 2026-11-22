<?php

declare(strict_types=1);

namespace Merlin\Core\Service;

/**
 * Reine Regeln für das merlin-reports-Meldungsbackend (ohne
 * Nextcloud-Abhängigkeiten, damit core/tests/test-report-backend.php sie ohne
 * Composer prüfen kann).
 *
 * Das Backend wird nicht mehr direkt aus dem Browser angesprochen, sondern
 * über ReportController/ReportBackendService serverseitig: ein fetch() aus
 * der Nextcloud-Origin (z. B. merlin.example.com) auf eine andere Origin
 * (z. B. example.com/tools/merlin-reports/) scheitert sonst an CORS, sobald
 * das Backend keinen Access-Control-Allow-Origin-Header schickt – und bei
 * einem per HTTP Basic Auth geschützten Verzeichnis schickt es den auf einer
 * 401-Antwort praktisch nie.
 */
final class ReportBackendRules {

	public const STATUS_OK = 'ok';
	public const STATUS_AUTH = 'auth';
	public const STATUS_ERROR = 'error';
	/** 2xx, aber keine {"ok": true}-Antwort (z. B. älteres Backend ohne ping-Aktion). */
	public const STATUS_UNEXPECTED = 'unexpected';

	/**
	 * Baut aus der vom Nutzer eingetragenen Backend-URL die Aufruf-URL für
	 * $action und trennt Zugangsdaten aus der URL ab
	 * (https://nutzer:passwort@host/pfad/ → Basic-Auth-Header statt URL).
	 *
	 * - "…/merlin-reports" und "…/merlin-reports/" → "…/merlin-reports/index.php?action=…"
	 * - "…/merlin-reports/index.php" (oder ein anderes *.php) bleibt, wie es ist
	 * - Query und Fragment der eingetragenen URL werden verworfen
	 *
	 * @return array{url: string, user: ?string, password: ?string}|null
	 *         null bei leerer/ungültiger URL oder anderem Schema als http(s)
	 */
	public static function endpoint(string $baseUrl, string $action): ?array {
		$baseUrl = trim($baseUrl);
		if ($baseUrl === '' || preg_match('/^[a-z]+$/', $action) !== 1) {
			return null;
		}

		$parts = parse_url($baseUrl);
		if ($parts === false) {
			return null;
		}
		$scheme = strtolower($parts['scheme'] ?? '');
		$host = $parts['host'] ?? '';
		if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
			return null;
		}

		$path = $parts['path'] ?? '';
		if (!preg_match('/\.php$/i', $path)) {
			$path = rtrim($path, '/') . '/index.php';
		}

		$hostPart = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
		$url = $scheme . '://' . $hostPart
			. (isset($parts['port']) ? ':' . $parts['port'] : '')
			. $path . '?action=' . $action;

		$user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
		$password = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
		if ($user === '') {
			$user = null;
		}

		return ['url' => $url, 'user' => $user, 'password' => $user === null ? null : ($password ?? '')];
	}

	/**
	 * Bewertet die Antwort auf action=ping: "ok" nur bei 2xx mit JSON
	 * {"ok": true}, "auth" bei 401/403 (Backend verlangt Anmeldung bzw. die
	 * Zugangsdaten stimmen nicht), "unexpected" bei 2xx ohne ok:true (Server
	 * antwortet, kennt aber kein ping), sonst "error".
	 */
	public static function pingStatus(int $httpCode, ?string $body): string {
		if ($httpCode === 401 || $httpCode === 403) {
			return self::STATUS_AUTH;
		}
		if ($httpCode < 200 || $httpCode >= 300 || $body === null) {
			return self::STATUS_ERROR;
		}
		$json = json_decode($body, true);
		return (is_array($json) && ($json['ok'] ?? false) === true) ? self::STATUS_OK : self::STATUS_UNEXPECTED;
	}
}
