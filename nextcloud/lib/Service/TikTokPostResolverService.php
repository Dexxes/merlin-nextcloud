<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Service\Http\SsrfSafeResolver;
use Psr\Log\LoggerInterface;

/**
 * Löst einen tiktok.com-Video-Link über TikToks offizielle, unauthentifizierte
 * oEmbed-API (https://www.tiktok.com/oembed) auf.
 *
 * Warum überhaupt ein Resolver, wo doch platform.twitter.com/widgets.js bzw.
 * www.instagram.com/embed.js rein client-seitig aus einem selbst gebauten,
 * leeren <blockquote> ein Embed rendern (siehe ContentExtractorService,
 * XPost-/InstagramPost-Zweig)? TikToks Widget-Skript (embed.js) liest die
 * Video-ID beim Rendern ausschließlich aus dem data-video-id-Attribut des
 * Blockquote-Elements (kein eigener API-Call, kein Parsing aus "cite") - in
 * der Praxis führte ein minimal selbst gebautes Blockquote trotz korrekt
 * gesetztem data-video-id zu einem fehlerhaften Embed
 * ("/embed/v2/null"-Fehler beim Aufruf). Die oEmbed-Antwort liefert
 * stattdessen das komplette, von TikTok selbst generierte und getestete
 * Embed-Markup im "html"-Feld (inkl. Caption/Autor-Link/Sound-Credit) - das
 * wird unverändert als Content-Basis übernommen und läuft trotzdem wie jeder
 * andere Content durch die normale sanitizeHtml()-Allowlist (Defense-in-
 * Depth, kein blindes Vertrauen in die Drittanbieter-Antwort).
 *
 * Anders als bei Bluesky/Mastodon kein Self-Thread-Walk: TikTok kennt kein
 * Konzept einer eigenen Thread-Fortsetzung, ein Video-Post wird für sich
 * allein aufgelöst.
 *
 * Fail-closed wie die anderen Resolver: jeder unerwartete Zustand (gelöschtes
 * Video, privates Konto, Rate-Limit, Netzwerkfehler, geändertes
 * Response-Format) liefert null statt einer Exception - ContentExtractorService
 * fällt dann auf den einfachen Link-Fallback zurück.
 */
class TikTokPostResolverService {
	use SsrfSafeResolver;

	private const HTTP_TIMEOUT_SECONDS = 8;
	private const OEMBED_ENDPOINT = 'https://www.tiktok.com/oembed';

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{html: string, authorName: ?string, authorUniqueId: ?string}|null
	 */
	public function resolve(string $permalinkUrl): ?array {
		try {
			$endpoint = self::OEMBED_ENDPOINT . '?url=' . rawurlencode($permalinkUrl);
			$json = $this->httpGetJson($endpoint);
			if ($json === null) {
				return null;
			}

			$html = $json['html'] ?? null;
			if (!is_string($html) || trim($html) === '') {
				return null;
			}

			$authorName     = is_string($json['author_name'] ?? null) ? trim($json['author_name']) : '';
			$authorUniqueId = is_string($json['author_unique_id'] ?? null) ? trim($json['author_unique_id']) : '';

			return [
				'html'           => $html,
				'authorName'     => $authorName !== '' ? $authorName : null,
				'authorUniqueId' => $authorUniqueId !== '' ? $authorUniqueId : null,
			];
		} catch (\Throwable $e) {
			$this->logger->info('TikTokPostResolverService: Auflösen fehlgeschlagen', [
				'url'       => $permalinkUrl,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Minimaler SSRF-abgesicherter JSON-GET - gleiches Muster wie
	 * BlueskyThreadResolverService::httpGetJson() (fester Host, siehe
	 * OEMBED_ENDPOINT oben).
	 *
	 * @return array<mixed>|null
	 */
	private function httpGetJson(string $url): ?array {
		$parsed = parse_url($url);
		$host   = $parsed['host'] ?? '';
		$scheme = strtolower($parsed['scheme'] ?? '');
		$port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

		$ips  = $this->assertPublicHostAndResolve($url);
		$pins = $this->buildResolvePin($host, $port, $ips);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
			CURLOPT_RESOLVE        => $pins,
			CURLOPT_HTTPHEADER     => ['Accept: application/json'],
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Merlin/1.0)',
		]);

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
