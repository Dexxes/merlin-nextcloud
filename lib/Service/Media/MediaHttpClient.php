<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media;

use OCA\Merlin\Service\Http\SsrfSafeResolver;

/**
 * Minimaler SSRF-abgesicherter JSON-Client für die Provider (einzelner Hop,
 * keine Redirect-Verfolgung): anders als ContentExtractorService::
 * httpRequestFollowingRedirects() rufen die Provider ausschließlich fest
 * hinterlegte Sender-API-Hosts auf, nie eine vom Nutzer stammende URL
 * direkt – das komplexere Redirect-Hardening dort ist für diesen
 * Anwendungsfall nicht nötig, die IP-Prüfung/Pinning-Logik aus dem
 * SsrfSafeResolver-Trait aber trotzdem als Defense-in-Depth sinnvoll.
 *
 * Fehler (Netzwerk, Nicht-2xx, kein JSON) liefern null.
 */
class MediaHttpClient {
	use SsrfSafeResolver;

	private const HTTP_TIMEOUT_SECONDS = 8;

	/**
	 * @param list<string> $extraHeaders
	 * @return array<mixed>|null
	 */
	public function getJson(string $url, array $extraHeaders = []): ?array {
		return $this->request($url, 'GET', null, $extraHeaders);
	}

	/**
	 * @param array<string, mixed> $body
	 * @param list<string> $extraHeaders
	 * @return array<mixed>|null
	 */
	public function postJson(string $url, array $body, array $extraHeaders = []): ?array {
		return $this->request($url, 'POST', json_encode($body, JSON_THROW_ON_ERROR), $extraHeaders);
	}

	/**
	 * @param list<string> $extraHeaders
	 * @return array<mixed>|null
	 */
	private function request(string $url, string $method, ?string $body, array $extraHeaders): ?array {
		$parsed = parse_url($url);
		$host   = $parsed['host'] ?? '';
		$scheme = strtolower($parsed['scheme'] ?? '');
		$port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

		$ips  = $this->assertPublicHostAndResolve($url);
		$pins = $this->buildResolvePin($host, $port, $ips);

		$ch = curl_init($url);
		$headers = array_merge(['Accept: application/json'], $extraHeaders);
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
		}

		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_RESOLVE        => $pins,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Merlin/1.0)',
			CURLOPT_CUSTOMREQUEST  => $method,
		];
		if ($body !== null) {
			$opts[CURLOPT_POSTFIELDS] = $body;
		}
		curl_setopt_array($ch, $opts);

		$response  = curl_exec($ch);
		$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($response === false || $curlError !== '') {
			return null;
		}
		if ($httpCode < 200 || $httpCode >= 300) {
			return null;
		}

		try {
			$decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}
}
