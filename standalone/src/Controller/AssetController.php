<?php

declare(strict_types=1);

namespace Merlin\Standalone\Controller;

use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;

/**
 * GET /img/{file}: die Bilder, auf die der Kern verweist (Port UrlGenerator,
 * z. B. Vorschaubilder für Social-Posts). Es gibt sie nur einmal, in
 * nextcloud/img/; ein Release-Paket ohne nextcloud/ legt sie nach
 * standalone/public/img/, dort liefert der Webserver sie direkt aus.
 */
final class AssetController {
	private const TYPES = ['png' => 'image/png', 'svg' => 'image/svg+xml', 'jpg' => 'image/jpeg', 'ico' => 'image/x-icon'];

	public function image(Request $request): Response {
		$file = (string) $request->routeParam('file');
		$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $file) || str_starts_with($file, '.') || !isset(self::TYPES[$ext])) {
			return Response::json(['error' => 'Not found'], 404);
		}
		$root = dirname(__DIR__, 2);
		foreach ([$root . '/public/img/' . $file, dirname($root) . '/nextcloud/img/' . $file] as $path) {
			if (is_file($path)) {
				return Response::file((string) file_get_contents($path), self::TYPES[$ext], ['Cache-Control' => 'public, max-age=86400']);
			}
		}
		return Response::json(['error' => 'Not found'], 404);
	}
}
