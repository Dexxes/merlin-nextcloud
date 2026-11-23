<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Port\UrlGenerator;

/**
 * Web-App-Manifest und Service Worker für „Zum Startbildschirm“ (PWA), ohne
 * Anmeldung abrufbar: Browser holen das Manifest ohne Cookies.
 *
 * start_url und scope sind relativ ("./") und lösen sich gegen die URL des
 * Manifests auf: in Nextcloud /index.php/apps/merlin/, im Standalone-Server
 * der Basis-Pfad. Der Service Worker reicht nur durch (kein Offline-Cache).
 */
final class PwaApi {
	public const SERVICE_WORKER = <<<'JS'
// Merlin Service Worker — minimal pass-through (no offline caching)
self.addEventListener('fetch', (event) => {
  // Navigation requests (mode 'navigate') can't be re-fetched via
  // event.request directly – fetch() throws a TypeError on a
  // navigate-mode Request. Passing the plain URL instead makes an
  // equivalent same-origin request without that restriction. Requests
  // outside this worker's scope never reach this handler at all.
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request.url));
    return;
  }
  event.respondWith(fetch(event.request));
});
JS;

	public function __construct(private UrlGenerator $urls) {
	}

	/** GET /manifest.webmanifest */
	public function manifest(ApiRequest $request): ApiResult {
		$manifest = [
			'name' => 'Merlin',
			'short_name' => 'Merlin',
			'description' => 'Save and read web articles',
			'start_url' => './',
			'scope' => './',
			'display' => 'standalone',
			'background_color' => '#ffffff',
			'theme_color' => '#0082c9',
			'icons' => [
				['src' => $this->urls->imagePath('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
				['src' => $this->urls->imagePath('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
				['src' => $this->urls->imagePath('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
			],
		];
		return ApiResult::body(
			json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
			'application/manifest+json',
		);
	}

	/** GET /sw.js */
	public function serviceWorker(ApiRequest $request): ApiResult {
		return ApiResult::body(self::SERVICE_WORKER, 'application/javascript', 200, [
			'Cache-Control' => 'no-cache, no-store, must-revalidate',
		]);
	}
}
