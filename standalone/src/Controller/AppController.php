<?php

declare(strict_types=1);

namespace Merlin\Standalone\Controller;

use Merlin\Core\Port\UserSettings;
use Merlin\Standalone\Auth\SessionService;
use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;
use Merlin\Standalone\I18n\Translator;
use Merlin\Standalone\Version;

/**
 * Die Vue-App im Standalone-Server (Plan „gemeinsames Repo“, Etappe 5):
 * der Reader unter GET / und die öffentliche Share-Ansicht unter
 * GET /s/{token}, die Gegenstücke zu Nextclouds page#index und
 * public_share#show. Beide Seiten sind nur eine Hülle; das Frontend
 * (npm run build:standalone, Ausgabe public/app/) holt alles Weitere über
 * die gemeinsame API. Startwerte, die in Nextcloud IInitialState liefert,
 * stehen hier als JSON in <script id="merlin-state">
 * (frontend/src/platform/standalone.js).
 */
final class AppController {
	public const BUILD_DIR = __DIR__ . '/../../public/app';

	public function __construct(
		private readonly SessionService $sessions,
		private readonly UserSettings $userSettings,
	) {
	}

	public function reader(Request $request): Response {
		$userId = $this->sessions->currentUserId();
		if ($userId === null) {
			return Response::redirect('/login');
		}
		return $this->render($request, 'main', 'merlin-app', [
			'appInfo' => Version::appInfo(),
			// PWA (Service Worker, Manifest) folgt mit Etappe 6.
			'swUrl' => null,
		], (string) $userId);
	}

	/** Kein Login nötig: der Token ist die einzige Berechtigung (wie in Nextcloud). */
	public function publicShare(Request $request): Response {
		return $this->render($request, 'public', 'merlin-public-app', [
			'shareToken' => (string) $request->routeParam('token'),
		], null);
	}

	/**
	 * Übersetzungen der App aus nextcloud/l10n/{locale}.json (dieselben wie in
	 * Nextcloud); Englisch ist der Quelltext. Ein Release-Paket ohne
	 * nextcloud/ legt sie nach standalone/l10n/.
	 *
	 * @return array<string, string|list<string>>|null
	 */
	private static function translations(string $locale): ?array {
		if ($locale === 'en' || !preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale)) {
			return null;
		}
		$root = dirname(__DIR__, 2);
		foreach ([$root . '/l10n/' . $locale . '.json', dirname($root) . '/nextcloud/l10n/' . $locale . '.json'] as $file) {
			if (is_file($file)) {
				$bundle = json_decode((string) file_get_contents($file), true);
				return is_array($bundle['translations'] ?? null) ? $bundle['translations'] : null;
			}
		}
		return null;
	}

	/**
	 * @param string $entry Name des Vite-Einstiegs (merlin-{entry}.mjs/.css)
	 * @param array<string, mixed> $values Startwerte für loadState()
	 */
	private function render(Request $request, string $entry, string $mountId, array $values, ?string $userId): Response {
		$basePath = rtrim(Response::getBasePath(), '/');
		$t = Translator::forRequest($request, $this->sessions, $this->userSettings, $userId);
		$state = [
			'base' => $basePath,
			'loginUrl' => $userId === null ? null : $basePath . '/login',
			'l10n' => self::translations($t->locale()),
			'values' => $values,
		];
		$built = is_file(self::BUILD_DIR . '/js/merlin-' . $entry . '.mjs');
		$vars = [
			't' => $t,
			'basePath' => $basePath,
			'entry' => $entry,
			'mountId' => $mountId,
			'built' => $built,
			// JSON im <script>: "</script>" und "<!--" dürfen nicht entstehen.
			'stateJson' => json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
		];
		extract($vars);
		ob_start();
		include __DIR__ . '/../../templates/app.php';
		return Response::html((string) ob_get_clean(), $built ? 200 : 503, [
			// Artikelbilder und -videos kommen von beliebigen Domains (wie
			// PageController/PublicShareController in Nextcloud).
			'Content-Security-Policy' => "default-src 'self'; img-src * data: blob:; media-src * data: blob:; "
				. "style-src 'self' 'unsafe-inline'; font-src 'self' data:; connect-src 'self'; "
				. "worker-src 'self' blob:; frame-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'",
			'Referrer-Policy' => 'no-referrer',
		]);
	}
}
