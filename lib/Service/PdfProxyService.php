<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Db\Article;
use OCA\Merlin\Service\Http\SsrfSafeResolver;

/**
 * Reicht die PDF eines PDF-Artikels (category "PDF") pro Request vom Quellserver
 * an den Reader durch, ohne sie zu speichern.
 *
 * Warum ein Proxy? Der Browser kann die PDF nicht direkt vom Quellserver in die
 * Reader-Seite laden: fremde Server setzen selten CORS-Header (pdf.js braucht
 * fetch/XHR) und blockieren Einbettung per X-Frame-Options/frame-ancestors, und
 * unsere eigene CSP soll nicht für beliebige Hosts geöffnet werden. Die Seite
 * lädt die PDF deshalb vom eigenen Origin (`/api/articles/{id}/pdf`,
 * `/s/{token}/pdf`).
 *
 * Sicherheit:
 *   - Es wird ausschließlich die in der DB gespeicherte Artikel-URL abgerufen,
 *     nie eine vom Client übergebene.
 *   - Jeder Hop (auch Redirects) läuft durch den SSRF-Guard (SsrfSafeResolver:
 *     Prüfung auf private/reservierte Adressen + IP-Pinning gegen DNS-Rebinding).
 *   - Nur Antworten, die wirklich eine PDF sind (`%PDF-`-Magic am Dateianfang,
 *     bei Range-Fortsetzungen PDF-/octet-stream-Content-Type), maximal 100 MB.
 *   - Die Antwort ist gehärtet (`nosniff`, `Content-Security-Policy: sandbox`,
 *     `Content-Disposition: inline`), damit ein untergeschobenes HTML-Dokument
 *     nie im Nextcloud-Origin ausgeführt wird.
 *   - Es werden keine Cookies/Zugangsdaten an den Quellserver gesendet.
 *
 * Range-Requests werden weitergereicht (pdf.js lädt Seiten nach), anders als
 * bei TtsStreamService. Beendet den PHP-Prozess wie dieser selbst per exit().
 */
class PdfProxyService {
	use SsrfSafeResolver;

	private const MAX_REDIRECTS = 5;

	/** Obergrenze für eine einzelne PDF (auch bei Range-Antworten über Content-Length geprüft). */
	private const MAX_BYTES = 100 * 1024 * 1024;

	/**
	 * Läuft nie normal zurück (siehe finish()).
	 */
	public function stream(Article $article): void {
		$this->streamFrom((string) $article->getUrl(), $article->getCategory());
	}

	/**
	 * Wie stream(), aber mit der bereits ausgelesenen Artikel-URL/-Kategorie
	 * (public, damit tools/test-pdf-proxy.php ohne Nextcloud-Entity auskommt).
	 */
	public function streamFrom(string $url, ?string $category): void {
		if (!$this->isPdfArticle($category, $url)) {
			$this->fail(404, 'Not a PDF article');
		}

		$range = $this->requestedRange();

		$this->prepareOutput();

		$current = $url;
		for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
			$parsed = parse_url($current);
			$host   = $parsed['host'] ?? '';
			$scheme = strtolower($parsed['scheme'] ?? '');
			$port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

			try {
				$ips = $this->guardHost($current);
			} catch (\Exception) {
				$this->fail(502, 'PDF source not reachable');
			}

			$status   = 0;
			$headers  = [];
			$started  = false;
			$problem  = null;

			$ch = curl_init($current);
			curl_setopt_array($ch, [
				CURLOPT_FOLLOWLOCATION => false, // manuell, damit jeder Hop den SSRF-Guard passiert
				CURLOPT_RESOLVE        => $this->buildResolvePin($host, (int) $port, $ips),
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 300,
				CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/150.0',
				CURLOPT_HTTPHEADER     => array_values(array_filter([
					'Accept: application/pdf,*/*;q=0.8',
					'Accept-Encoding: identity', // PDFs sind bereits komprimiert; Content-Length/Range bleiben so stimmig
					'Referer: ' . $scheme . '://' . $host . '/',
					$range !== null ? 'Range: ' . $range : null,
				])),
				CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status, &$headers): int {
					$trimmed = trim($line);
					if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $m) === 1) {
						$status  = (int) $m[1];
						$headers = [];
					} elseif (str_contains($trimmed, ':')) {
						[$name, $value] = explode(':', $trimmed, 2);
						$headers[strtolower(trim($name))] = trim($value);
					}
					return strlen($line);
				},
				CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use (&$status, &$headers, &$started, &$problem): int {
					if ($status >= 300 && $status < 400) {
						return strlen($data); // Redirect-Body verwerfen
					}
					if (!$started) {
						$problem = $this->checkResponse($status, $headers, $data);
						if ($problem !== null) {
							return -1; // Transfer abbrechen
						}
						$this->emitHeaders($status, $this->responseHeaders($status, $headers));
						$started = true;
					}
					if (connection_aborted()) {
						return -1;
					}
					echo $data;
					if (ob_get_level()) {
						ob_flush();
					}
					flush();
					return strlen($data);
				},
			]);
			curl_exec($ch);
			$errno = curl_errno($ch);
			curl_close($ch);

			if ($status >= 300 && $status < 400 && isset($headers['location'])) {
				$current = $this->resolveLocation($headers['location'], $current);
				continue;
			}

			if ($started) {
				// Fehler nach Beginn der Übertragung (z. B. Client getrennt) lassen sich nicht mehr melden.
				$this->finish();
			}
			if ($problem !== null) {
				$this->fail($problem[0], $problem[1]);
			}
			if ($errno !== 0) {
				$this->fail(502, 'PDF source not reachable');
			}
			// Leere 2xx-Antwort ohne Body.
			$this->fail(502, 'Empty response from PDF source');
		}

		$this->fail(502, 'Too many redirects');
	}

	// ── Prüfungen ────────────────────────────────────────────────────────────

	private function isPdfArticle(?string $category, string $url): bool {
		if ($category === ContentExtractorService::PDF_CATEGORY) {
			return true;
		}
		$path = parse_url($url, PHP_URL_PATH);
		return is_string($path) && preg_match('/\.pdf$/i', rawurldecode($path)) === 1;
	}

	/**
	 * Übernimmt einen einfachen `Range: bytes=a-b`-Header des Clients (pdf.js);
	 * alles andere (Mehrfach-Ranges, Unsinn) wird ignoriert.
	 */
	private function requestedRange(): ?string {
		$range = $_SERVER['HTTP_RANGE'] ?? null;
		return is_string($range) && preg_match('/^bytes=\d*-\d*$/', $range) === 1 ? $range : null;
	}

	/**
	 * @param array<string, string> $headers Antwort-Header des Quellservers (Namen kleingeschrieben)
	 * @return array{0: int, 1: string}|null [HTTP-Status, Meldung] bei Ablehnung, sonst null
	 */
	private function checkResponse(int $status, array $headers, string $firstChunk): ?array {
		if ($status !== 200 && $status !== 206) {
			return [502, 'PDF source answered with HTTP ' . $status];
		}
		$length = isset($headers['content-length']) && ctype_digit($headers['content-length'])
			? (int) $headers['content-length']
			: null;
		if ($length !== null && $length > self::MAX_BYTES) {
			return [413, 'PDF too large'];
		}

		$type      = strtolower($headers['content-type'] ?? '');
		$isFromTop = $status === 200 || str_starts_with($headers['content-range'] ?? '', 'bytes 0-');
		if ($isFromTop) {
			// Ab Dateianfang: Magic prüfen (die Spezifikation erlaubt Müll in den ersten 1024 Bytes).
			if (!str_contains(substr($firstChunk, 0, 1024), '%PDF-')) {
				return [502, 'Source is not a PDF'];
			}
		} elseif (!str_contains($type, 'pdf') && !str_contains($type, 'octet-stream')) {
			// Fortsetzung mitten in der Datei: keine Magic vorhanden, nur der Typ lässt sich prüfen.
			return [502, 'Source is not a PDF'];
		}
		return null;
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	private function responseHeaders(int $status, array $headers): array {
		$out = [
			'Content-Type'            => 'application/pdf',
			'Content-Disposition'     => 'inline; filename="document.pdf"',
			'X-Content-Type-Options'  => 'nosniff',
			'Content-Security-Policy' => "sandbox; default-src 'none'",
			'Cache-Control'           => 'private, max-age=600',
			'Accept-Ranges'           => 'bytes',
			// Proxy-Puffer aus (siehe TtsStreamService) und keine zusätzliche Kompression.
			'X-Accel-Buffering'       => 'no',
			'Content-Encoding'        => 'identity',
		];
		if (isset($headers['content-length']) && ctype_digit($headers['content-length'])) {
			$out['Content-Length'] = $headers['content-length'];
		}
		if ($status === 206 && isset($headers['content-range'])) {
			$out['Content-Range'] = $headers['content-range'];
		}
		return $out;
	}

	/**
	 * Löst einen (ggf. relativen) Location-Header gegen den aktuellen Hop auf.
	 */
	private function resolveLocation(string $location, string $base): string {
		if (preg_match('#^https?://#i', $location) === 1) {
			return $location;
		}
		$parts  = parse_url($base);
		$origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
			. (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (str_starts_with($location, '//')) {
			return ($parts['scheme'] ?? 'https') . ':' . $location;
		}
		if (str_starts_with($location, '/')) {
			return $origin . $location;
		}
		$dir = rtrim(dirname($parts['path'] ?? '/'), '/');
		return $origin . $dir . '/' . $location;
	}

	// ── Ein-/Ausgabe (protected: in tools/test-pdf-proxy.php überschrieben) ──

	/**
	 * SSRF-Guard für einen Hop: liefert die geprüften IPs oder wirft.
	 *
	 * @return list<string>
	 * @throws \Exception
	 */
	protected function guardHost(string $url): array {
		return $this->assertPublicHostAndResolve($url);
	}

	/**
	 * Stellt Ausgabe auf Streaming um (Buffer/Kompression aus, kein Zeitlimit).
	 * ignore_user_abort: PHP soll den Abbruch selbst über connection_aborted() melden.
	 */
	protected function prepareOutput(): void {
		set_time_limit(0);
		ignore_user_abort(true);
		while (ob_get_level()) {
			ob_end_clean();
		}
		ini_set('output_buffering', 'off');
		ini_set('zlib.output_compression', 'off');
		ob_implicit_flush(true);
	}

	/**
	 * @param array<string, string> $headers
	 */
	protected function emitHeaders(int $status, array $headers): void {
		http_response_code($status);
		foreach ($headers as $name => $value) {
			header($name . ': ' . $value);
		}
	}

	/**
	 * Meldet einen Fehler als JSON, solange noch keine Nutzdaten gesendet wurden.
	 */
	protected function fail(int $status, string $message): never {
		http_response_code($status);
		header('Content-Type: application/json');
		echo json_encode(['error' => $message]);
		$this->finish();
	}

	/**
	 * Beendet den Prozess, bevor Nextcloud eigene Response-Bytes anhängt (siehe TtsStreamService).
	 */
	protected function finish(): never {
		exit();
	}
}
