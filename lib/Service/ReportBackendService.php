<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Service\Http\SsrfSafeResolver;
use Psr\Log\LoggerInterface;

/**
 * Serverseitiger Zugriff auf das merlin-reports-Meldungsbackend
 * (Verbindungstest und Meldung senden), siehe ReportBackendRules.
 *
 * Die Backend-URL stammt vom Nutzer, daher derselbe SSRF-Schutz wie beim
 * Artikelabruf (keine privaten/reservierten Ziel-IPs, DNS-Pinning) und keine
 * Redirect-Verfolgung. Zugangsdaten in der URL (https://nutzer:pw@host/…)
 * gehen als HTTP Basic Auth mit und werden nie geloggt.
 */
class ReportBackendService {
	use SsrfSafeResolver;

	private const HTTP_TIMEOUT_SECONDS = 8;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{status: string, httpStatus: ?int}
	 *         status: ReportBackendRules::STATUS_OK|STATUS_AUTH|STATUS_ERROR
	 */
	public function ping(string $baseUrl): array {
		$endpoint = ReportBackendRules::endpoint($baseUrl, 'ping');
		if ($endpoint === null) {
			return ['status' => ReportBackendRules::STATUS_ERROR, 'httpStatus' => null];
		}
		$res = $this->request('GET', $endpoint, null);
		if ($res === null) {
			return ['status' => ReportBackendRules::STATUS_ERROR, 'httpStatus' => null];
		}
		return [
			'status' => ReportBackendRules::pingStatus($res['code'], $res['body']),
			'httpStatus' => $res['code'],
		];
	}

	/**
	 * Sendet eine Meldung (gleicher JSON-Body wie früher direkt aus dem Browser).
	 *
	 * @return array{status: string, httpStatus: ?int}
	 */
	public function report(string $baseUrl, string $articleUrl, string $comment): array {
		$endpoint = ReportBackendRules::endpoint($baseUrl, 'report');
		if ($endpoint === null) {
			return ['status' => ReportBackendRules::STATUS_ERROR, 'httpStatus' => null];
		}
		$body = json_encode(['url' => $articleUrl, 'comment' => $comment], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$res = $this->request('POST', $endpoint, (string) $body);
		if ($res === null) {
			return ['status' => ReportBackendRules::STATUS_ERROR, 'httpStatus' => null];
		}
		$code = $res['code'];
		$status = ($code === 401 || $code === 403)
			? ReportBackendRules::STATUS_AUTH
			: (($code >= 200 && $code < 300) ? ReportBackendRules::STATUS_OK : ReportBackendRules::STATUS_ERROR);
		return ['status' => $status, 'httpStatus' => $code];
	}

	/**
	 * @param array{url: string, user: ?string, password: ?string} $endpoint
	 * @return array{code: int, body: string}|null null bei Netzwerk-/SSRF-Fehler
	 */
	private function request(string $method, array $endpoint, ?string $jsonBody): ?array {
		$url = $endpoint['url'];
		$parsed = parse_url($url);
		$host = $parsed['host'] ?? '';
		$scheme = strtolower($parsed['scheme'] ?? '');
		$port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

		try {
			$ips = $this->assertPublicHostAndResolve($url);
		} catch (\Exception $e) {
			$this->logger->info('Meldungsbackend nicht erlaubt/auflösbar: ' . $e->getMessage(), ['app' => 'merlin']);
			return null;
		}

		$headers = ['Accept: application/json'];
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_RESOLVE        => $this->buildResolvePin($host, (int) $port, $ips),
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Merlin/1.0)',
		];
		if ($endpoint['user'] !== null) {
			$opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
			$opts[CURLOPT_USERPWD] = $endpoint['user'] . ':' . ($endpoint['password'] ?? '');
		}
		if ($method === 'POST') {
			$opts[CURLOPT_POST] = true;
			$opts[CURLOPT_POSTFIELDS] = $jsonBody ?? '';
			$headers[] = 'Content-Type: application/json';
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;

		$ch = curl_init($url);
		curl_setopt_array($ch, $opts);
		$response = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($response === false || $curlError !== '') {
			$this->logger->info('Meldungsbackend nicht erreichbar: ' . $curlError, ['app' => 'merlin']);
			return null;
		}
		return ['code' => $code, 'body' => (string) $response];
	}
}
