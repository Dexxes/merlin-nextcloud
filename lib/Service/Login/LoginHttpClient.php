<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Login;

use OCA\Merlin\Db\SiteCredential;
use OCA\Merlin\Service\Http\SsrfSafeResolver;

/**
 * Gemeinsame HTTP-Bausteine der Login-Provider: ein einzelner, SSRF-geprüfter
 * curl-Request ohne Redirect-Following plus Set-Cookie-Parsing.
 *
 * Extrahiert aus PianoJsonFormLoginProvider, als mit
 * KeycloakFormPostLoginProvider ein zweiter Provider dazukam. Zwei
 * Erweiterungen gegenüber dem Original: safeRequest() liefert zusätzlich den
 * Response-Body (Keycloak muss HTML-Formulare aus der Antwort lesen), und ein
 * vom SSRF-Guard abgelehnter Host wird als LoginFailedException
 * (STATUS_LOGIN_FLOW_BROKEN) gemeldet statt als nackte \Exception, damit
 * SiteCredentialService ihn wie jeden anderen kaputten Login-Ablauf
 * behandelt.
 */
trait LoginHttpClient {
	use SsrfSafeResolver;

	/**
	 * Führt einen einzelnen, SSRF-geprüften Request aus. Redirects werden
	 * bewusst NICHT verfolgt: jeder Provider prüft selbst, welcher Status an
	 * welcher Stelle seines Ablaufs erwartet ist, und ein automatisch
	 * verfolgter Redirect könnte Cookies/Formulardaten an einen fremden Host
	 * tragen.
	 *
	 * @param list<string> $headers
	 * @return array{0:int,1:string,2:string} HTTP-Status, rohe Response-Header, Body
	 */
	private function safeRequest(string $method, string $url, array $headers, ?string $body): array {
		$parsed = parse_url($url);
		$host   = $parsed['host'] ?? '';
		$port   = $parsed['port'] ?? (($parsed['scheme'] ?? 'https') === 'https' ? 443 : 80);

		try {
			$ips = $this->assertPublicHostAndResolve($url);
		} catch (\Exception $e) {
			throw new LoginFailedException('Login-Request abgelehnt: ' . $e->getMessage(), SiteCredential::STATUS_LOGIN_FLOW_BROKEN);
		}
		$pins = $this->buildResolvePin($host, (int) $port, $ips);

		$ch   = curl_init($url);
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HEADER         => true,
			CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => array_merge($headers, ['User-Agent: ' . self::USER_AGENT]),
			CURLOPT_RESOLVE        => $pins,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		];
		if ($body !== null) {
			$opts[CURLOPT_POSTFIELDS] = $body;
		}
		curl_setopt_array($ch, $opts);

		$response = curl_exec($ch);
		if ($response === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new LoginFailedException('Login-Request fehlgeschlagen: ' . $error, SiteCredential::STATUS_LOGIN_FLOW_BROKEN);
		}

		$status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		curl_close($ch);

		$rawHeaders = substr((string) $response, 0, $headerSize);
		$rawBody    = substr((string) $response, $headerSize);

		return [$status, $rawHeaders, $rawBody];
	}

	private function originOf(string $url): string {
		$parsed = parse_url($url);
		$scheme = $parsed['scheme'] ?? 'https';
		$host   = $parsed['host'] ?? '';
		return $scheme . '://' . $host;
	}

	/**
	 * @return array<string,array{value:string,expiresAt:?\DateTime}>
	 */
	private function parseSetCookies(string $rawHeaders): array {
		$cookies = [];
		foreach (explode("\r\n", $rawHeaders) as $line) {
			if (stripos($line, 'Set-Cookie:') !== 0) {
				continue;
			}
			$value = trim(substr($line, strlen('Set-Cookie:')));
			$parts = explode(';', $value);
			$nameValue = array_shift($parts) ?? '';
			if (!str_contains($nameValue, '=')) {
				continue;
			}
			[$name, $val] = explode('=', $nameValue, 2);
			$name = trim($name);
			$val  = trim($val);

			$expiresAt = null;
			foreach ($parts as $attr) {
				$attr = trim($attr);
				if (stripos($attr, 'Max-Age=') === 0) {
					$seconds = (int) substr($attr, strlen('Max-Age='));
					$expiresAt = (new \DateTime())->modify('+' . $seconds . ' seconds');
				} elseif (stripos($attr, 'Expires=') === 0 && $expiresAt === null) {
					$parsed = \DateTime::createFromFormat(\DateTimeInterface::RFC7231, substr($attr, strlen('Expires=')));
					if ($parsed !== false) {
						$expiresAt = $parsed;
					}
				}
			}

			$cookies[$name] = ['value' => $val, 'expiresAt' => $expiresAt];
		}
		return $cookies;
	}
}
