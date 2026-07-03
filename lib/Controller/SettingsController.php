<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * REST API for user settings.
 *
 * Security note – NoCSRFRequired:
 * All API routes carry #[NoCSRFRequired] because the same endpoints serve
 * both the Vue web-UI (session cookie) and native clients (iOS, Android,
 * browser extensions) that authenticate via HTTP Basic Auth and cannot
 * supply a Nextcloud requesttoken. Removing the attribute would break all
 * native clients; splitting routes into separate web/API prefixes is the
 * clean long-term fix (tracked). Residual CSRF risk for the web-UI path is
 * mitigated by Nextcloud's SameSite=Lax session cookie, which prevents
 * cross-site POST/PUT/DELETE in all modern browsers.
 */
class SettingsController extends Controller {
	private IConfig $config;
	private ?string $userId;

	private const DEFAULT_SETTINGS = [
		'theme' => 'auto', // 'light', 'dark', 'auto', 'sepia'
		'fontSize' => '17', // numerischer Wert in px (z.B. 13, 15, 17, 19, 21, 24)
		'fontFamily' => 'default', // 'default', 'serif', 'sans-serif', 'monospace'
		'lineHeight' => '1.6',
		'defaultView' => 'unread', // 'all', 'unread', 'favorites'
		'saveProgress' => '1', // boolean: remember scroll position per article
		'resumeOnOpen' => '1', // boolean: restore scroll position when reopening
		// progressEdge ersetzt showProgress: 'left', 'right', 'top', 'bottom', 'off'
		// 'off' bedeutet keinen Fortschrittsbalken; im Web-Reader wird immer bottom verwendet.
		'progressEdge' => 'left',
		'reportBackendUrl' => '', // URL des merlin-reports-Backends (z.B. https://cloud.example.com/merlin-reports/)
		'accentColor' => '#FF3B30', // Akzentfarbe für Fortschrittsbalken (iOS/Android), siehe PreferencesStore
		'excludedTagIds' => '[]', // JSON-Array von Tag-IDs, die aus der Artikelliste ausgeblendet werden (Pendant zu iOS/Android TagFilterSheet)
	];

	public function __construct(
		string $appName,
		IRequest $request,
		IConfig $config,
		?string $userId
	) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->userId = $userId;
	}

	/**
	 * Get all settings
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(): DataResponse {
		$settings = [];

		foreach (self::DEFAULT_SETTINGS as $key => $defaultValue) {
			$settings[$key] = $this->config->getUserValue(
				$this->userId,
				'reader',
				$key,
				$defaultValue
			);
		}

		return new DataResponse($settings);
	}

	/**
	 * Update settings
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(): DataResponse {
		// Die Clients (iOS/Android) schicken das Settings-Dict als flaches JSON-Objekt,
		// nicht unter einem "settings"-Schlüssel verschachtelt. Ein typisiertes
		// `array $settings`-Parameter würde von Nextclouds AppFramework nie befüllt,
		// weil dort exakt nach einem Top-Level-Key "settings" gesucht wird – der
		// nie existiert. Deshalb die rohen, bereits JSON-decodierten Top-Level-Params lesen.
		$settings = $this->request->getParams();

		foreach ($settings as $key => $value) {
			if (array_key_exists($key, self::DEFAULT_SETTINGS)) {
				$this->config->setUserValue($this->userId, 'reader', $key, (string) $value);
			}
		}

		return new DataResponse(['success' => true]);
	}
}
