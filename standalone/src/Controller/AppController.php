<?php

declare(strict_types=1);

namespace Merlin\Standalone\Controller;

use Merlin\Core\Api\ApiRequest;
use Merlin\Core\Api\ContentFilterApi;
use Merlin\Core\Api\RetentionApi;
use Merlin\Core\Port\UserSettings;
use Merlin\Standalone\Auth\SessionService;
use Merlin\Standalone\Cron\DailyJobs;
use Merlin\Standalone\Db\UserRepository;
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
 * die gemeinsame API. Dazu die Einstellungsseiten, die in Nextcloud unter
 * Einstellungen › Merlin stehen: GET /settings (persönliche Content-Filter,
 * wie Settings\PersonalSettings) und GET /settings/admin (Löschfrist und
 * Content-Filter, wie Settings\RetentionAdminSettings und AdminSettings).
 * Startwerte, die in Nextcloud IInitialState liefert,
 * stehen hier als JSON in <script id="merlin-state">
 * (frontend/src/platform/standalone.js).
 */
final class AppController {
	public const BUILD_DIR = __DIR__ . '/../../public/app';

	public function __construct(
		private readonly SessionService $sessions,
		private readonly UserSettings $userSettings,
		private readonly UserRepository $users,
		private readonly ContentFilterApi $contentFilters,
		private readonly RetentionApi $retention,
		private readonly DailyJobs $dailyJobs,
	) {
	}

	public function reader(Request $request): Response {
		$user = $this->currentUser();
		if ($user === null) {
			return Response::redirect('/login');
		}
		// Ohne System-Cron: Löschfrist hier nachholen (siehe DailyJobs).
		$this->dailyJobs->scheduleFromWebRequest();
		return $this->render($request, 'main', ['merlin-app'], [
			'appInfo' => Version::appInfo(),
			// PWA (Service Worker, Manifest) folgt mit Etappe 6.
			'swUrl' => null,
		], $user);
	}

	/** Kein Login nötig: der Token ist die einzige Berechtigung (wie in Nextcloud). */
	public function publicShare(Request $request): Response {
		return $this->render($request, 'public', ['merlin-public-app'], [
			'shareToken' => (string) $request->routeParam('token'),
		], null);
	}

	/** Persönliche Content-Filter (Startwert wie Settings\PersonalSettings). */
	public function personalSettings(Request $request): Response {
		$user = $this->currentUser();
		if ($user === null) {
			return Response::redirect('/login?return=' . rawurlencode('/settings'));
		}
		$isAdmin = $user['role'] === 'admin';
		$data = $this->contentFilters->userIndex($this->apiRequest($user))->data;
		return $this->render($request, 'personal', ['merlin-personal-settings'], [
			'userContentFilters' => $data + [
				'isAdmin' => $isAdmin,
				'adminSettingsUrl' => $isAdmin ? rtrim(Response::getBasePath(), '/') . '/settings/admin' : null,
			],
		], $user, 'app.personalSettings');
	}

	/** Löschfrist und Content-Filter der Instanz, nur für Admins. */
	public function adminSettings(Request $request): Response {
		$user = $this->currentUser();
		if ($user === null) {
			return Response::redirect('/login?return=' . rawurlencode('/settings/admin'));
		}
		if ($user['role'] !== 'admin') {
			return Response::redirect('/settings');
		}
		$apiRequest = $this->apiRequest($user);
		return $this->render($request, 'admin', ['merlin-admin-retention', 'merlin-admin-settings'], [
			'retentionAdmin' => $this->retention->adminShow($apiRequest)->data,
			'contentFilters' => $this->contentFilters->index($apiRequest)->data,
		], $user, 'app.adminSettings');
	}

	/** @return array<string, mixed>|null aktive users-Zeile der Session */
	private function currentUser(): ?array {
		$userId = $this->sessions->currentUserId();
		$user = $userId === null ? null : $this->users->findById($userId);
		return $user !== null && (bool) $user['is_active'] ? $user : null;
	}

	private function apiRequest(array $user): ApiRequest {
		return new ApiRequest((string) $user['id'], [], static fn (): string => '', [], '');
	}

	/**
	 * Konto-Links für die Seitenleiste der App (platform accountLinks()).
	 *
	 * @return list<array{id: string, href: string}>
	 */
	private static function links(string $basePath, array $user): array {
		$links = [
			['id' => 'account', 'href' => $basePath . '/account'],
			['id' => 'contentFilters', 'href' => $basePath . '/settings'],
		];
		if ($user['role'] === 'admin') {
			$links[] = ['id' => 'admin', 'href' => $basePath . '/settings/admin'];
		}
		$links[] = ['id' => 'logout', 'href' => $basePath . '/logout'];
		return $links;
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
	 * @param list<string> $mountIds Container, in die der Einstieg seine App(s) setzt
	 * @param array<string, mixed> $values Startwerte für loadState()
	 * @param array<string, mixed>|null $user angemeldeter Nutzer (null: öffentliche Seite)
	 * @param string|null $pageTitle Übersetzungs-Key: Seite mit Navigationsleiste (Einstellungen)
	 */
	private function render(Request $request, string $entry, array $mountIds, array $values, ?array $user, ?string $pageTitle = null): Response {
		$basePath = rtrim(Response::getBasePath(), '/');
		$t = Translator::forRequest($request, $this->sessions, $this->userSettings, $user === null ? null : (string) $user['id']);
		$state = [
			'base' => $basePath,
			'loginUrl' => $user === null ? null : $basePath . '/login',
			'links' => $user === null ? [] : self::links($basePath, $user),
			'l10n' => self::translations($t->locale()),
			'values' => $values,
		];
		$built = is_file(self::BUILD_DIR . '/js/merlin-' . $entry . '.mjs');
		$vars = [
			't' => $t,
			'basePath' => $basePath,
			'entry' => $entry,
			'mountIds' => $mountIds,
			'pageTitle' => $pageTitle,
			'isAdmin' => $user !== null && $user['role'] === 'admin',
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
