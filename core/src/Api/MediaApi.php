<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Extraction\Media\MediaResolverService;
use Merlin\Core\Extraction\Media\MediaResult;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\UrlGenerator;

/**
 * Audio- und Videoquellen eines Artikels und die YouTube-Seite für die
 * nativen Reader. Antworten wie bisher in MediaController,
 * VideoStreamController und YoutubeEmbedController.
 *
 * Die Quellen werden je Request aufgelöst und nicht gespeichert (siehe
 * MediaResolverService zur Provider-Kette und den Mediathek-Streams).
 */
final class MediaApi {
	/**
	 * 11 Zeichen ist YouTubes Standardlänge; a-z, A-Z, 0-9, - und _ ist der
	 * volle Base64url-Zeichensatz der Video-IDs.
	 */
	private const VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

	/**
	 * Eigene Policy der YouTube-Seite: nur der eingebettete Player, inline
	 * CSS, und frame-ancestors '*'. Der Reader mit file://-Origin kann nie
	 * SAMEORIGIN zur Instanz sein; frame-ancestors überschreibt laut CSP
	 * Level 2 das ältere X-Frame-Options. Unbedenklich: die Seite zeigt nur
	 * eine öffentliche Video-ID ohne Nutzerdaten.
	 */
	private const EMBED_POLICY = "default-src 'none'; style-src 'unsafe-inline'; "
		. 'frame-src https://www.youtube-nocookie.com; frame-ancestors *';

	public function __construct(
		private ArticleStore $articles,
		private MediaResolverService $resolver,
		private UrlGenerator $urls,
	) {
	}

	/**
	 * GET /api/articles/{id}/media: {available: false} oder
	 * {available: true, kind, delivery, variants[], defaultIndex}.
	 */
	public function resolve(ApiRequest $request): ApiResult {
		$resolved = $this->resolveFor($request);
		if ($resolved instanceof ApiResult) {
			return $resolved;
		}
		return ApiResult::json($resolved === null ? ['available' => false] : ['available' => true] + $resolved->toArray());
	}

	/**
	 * GET /api/articles/{id}/video-stream: veraltet, nur für bestehende
	 * Clients. Altes Format und nur HLS-Video, also genau das, was der
	 * Endpunkt vor den Medien-Providern abdeckte (ARD Mediathek, ZDF, Arte).
	 */
	public function videoStream(ApiRequest $request): ApiResult {
		$resolved = $this->resolveFor($request);
		if ($resolved instanceof ApiResult) {
			return $resolved;
		}
		if ($resolved === null
			|| $resolved->kind !== MediaResult::KIND_VIDEO
			|| $resolved->delivery !== MediaResult::DELIVERY_HLS) {
			return ApiResult::json(['available' => false]);
		}
		return ApiResult::json([
			'available'    => true,
			'type'         => 'hls',
			'variants'     => $resolved->variants,
			'defaultIndex' => $resolved->defaultIndex,
		]);
	}

	/**
	 * GET /api/youtube-embed?v=…&t=…: winzige HTML-Seite mit nur einem
	 * YouTube-iframe, ohne Anmeldung.
	 *
	 * Warum ein eigener Endpunkt? Der native Reader (iOS/iPad) lädt den
	 * Artikel per WKWebView.loadFileURL (file://-Origin), damit er die lokal
	 * gecachten Bilder lesen darf. YouTubes Player prüft Origin und Referrer
	 * des einbettenden Dokuments und bricht bei file:// mit „Error 153“ ab.
	 * Diese Seite hat die https-Origin der Instanz. Der Reader öffnet sie
	 * per Top-Level-Navigation in einer eigenen WKWebView (YouTubePlayerView
	 * in merlin-ios/merlin-ipad), weil WKWebView frame-ancestors für eine
	 * file://-Elternseite nicht zuverlässig auswertet und verschachtelt nur
	 * eine leere Fläche zeigt.
	 */
	public function youtubeEmbed(ApiRequest $request): ApiResult {
		$videoId = (string) $request->string('v', '');
		if (!preg_match(self::VIDEO_ID_PATTERN, $videoId)) {
			return ApiResult::body('Invalid video id', 'text/plain; charset=utf-8', 400);
		}

		// Startzeit nur als Sekunden; alles andere wird verworfen.
		$start = (string) $request->string('t', '');

		$embedParams = [
			'controls'       => '1',
			'modestbranding' => '1',
			'playsinline'    => '1',
			'rel'            => '0',
			// Muss zur Origin dieser Seite passen, sonst verweigert YouTube
			// den Embed wie beim file://-Problem, nur eine Ebene tiefer.
			'origin'         => $this->urls->origin(),
		];
		if (ctype_digit($start)) {
			$embedParams['start'] = $start;
		}

		$videoIdEscaped = htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8');
		$embedUrl = htmlspecialchars('https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId)
			. '?' . http_build_query($embedParams), ENT_QUOTES, 'UTF-8');

		$html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="referrer" content="strict-origin-when-cross-origin">
<title>YouTube: {$videoIdEscaped}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { width: 100%; height: 100%; overflow: hidden; background: #000; }
  iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
</style>
</head>
<body>
<iframe
  src="{$embedUrl}"
  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
  allowfullscreen
  referrerpolicy="strict-origin-when-cross-origin"
></iframe>
</body>
</html>
HTML;

		return ApiResult::body($html, 'text/html; charset=utf-8', 200, ['Content-Security-Policy' => self::EMBED_POLICY]);
	}

	private function resolveFor(ApiRequest $request): MediaResult|ApiResult|null {
		$userId = $request->userId();
		$article = $this->articles->find((int) $request->int('id'), $userId);
		if ($article === null) {
			return ApiResult::error('Article not found', 404);
		}
		return $this->resolver->resolveOnRequest((string) $article->getUrl(), (string) $article->getContent(), $userId);
	}
}
