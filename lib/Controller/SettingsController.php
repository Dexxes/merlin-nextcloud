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
 * native clients. CSRF protection for the cookie-authenticated web-UI path is
 * instead enforced centrally by CsrfCookieAuthMiddleware, which demands a
 * valid requesttoken for state-changing browser requests while skipping
 * Basic/Bearer-authenticated (native) requests and safe methods. SameSite=Lax
 * session cookies remain as an additional layer of defense.
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

	// IConfig kann nur Strings persistieren. Ohne diese Typ-Tabelle liefert get()
	// immer Strings zurück, während der Web-Client nach dem Speichern optimistisch
	// die eigenen (Number/Boolean-)Typen in den Store committet — der nächste
	// 15-s-Poll sieht dann garantiert ein anderes JSON.stringify-Ergebnis und löst
	// einen unnötigen SET_SETTINGS-Zyklus (inkl. Report-Backend-Ping) aus. Zusätzlich
	// wäre ein gespeicherter String "false" in JS truthy. castForResponse()/
	// castForStorage() sorgen dafür, dass get() und update() dieselben, korrekt
	// typisierten Werte liefern.
	private const SETTINGS_TYPES = [
		'theme' => 'string',
		'fontSize' => 'int',
		'fontFamily' => 'string',
		'lineHeight' => 'float',
		'defaultView' => 'string',
		'saveProgress' => 'bool',
		'resumeOnOpen' => 'bool',
		'progressEdge' => 'string',
		'reportBackendUrl' => 'string',
		'accentColor' => 'string',
		'excludedTagIds' => 'string', // bleibt JSON-Array-String; wird clientseitig geparst
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
			$raw = $this->config->getUserValue(
				$this->userId,
				'reader',
				$key,
				$defaultValue
			);
			$settings[$key] = $this->castForResponse($key, $raw);
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

		// Die kanonisch typisierten, tatsächlich gespeicherten Werte werden zurückgegeben,
		// damit der Web-Client sie 1:1 in den Store committen kann statt die rohen
		// Client-Typen zu übernehmen (siehe SETTINGS_TYPES-Kommentar oben).
		$saved = [];
		foreach ($settings as $key => $value) {
			if (array_key_exists($key, self::DEFAULT_SETTINGS)) {
				$stored = $this->castForStorage($key, $value);
				$this->config->setUserValue($this->userId, 'reader', $key, $stored);
				$saved[$key] = $this->castForResponse($key, $stored);
			}
		}

		return new DataResponse(['success' => true, 'settings' => $saved]);
	}

	/**
	 * Wandelt den intern als String persistierten Rohwert in den korrekt
	 * typisierten JSON-Wert um, den der Client erwartet (bool/int/float/string).
	 */
	private function castForResponse(string $key, string $raw): string|int|float|bool {
		switch (self::SETTINGS_TYPES[$key] ?? 'string') {
			case 'bool':
				return $raw === '1';
			case 'int':
				return (int) $raw;
			case 'float':
				return (float) $raw;
			default:
				return $raw;
		}
	}

	/**
	 * Normalisiert einen eingehenden Client-Wert (Number/Boolean/String, je nach
	 * Plattform) auf die String-Repräsentation, die IConfig persistiert. Für
	 * Booleans wird bewusst nicht blind (string) gecastet: PHPs (string) false
	 * ergibt "" statt "0", und ein String-Wert wie "false" wäre sonst truthy.
	 */
	private function castForStorage(string $key, mixed $value): string {
		switch (self::SETTINGS_TYPES[$key] ?? 'string') {
			case 'bool':
				$bool = is_string($value)
					? in_array(strtolower($value), ['1', 'true'], true)
					: (bool) $value;
				return $bool ? '1' : '0';
			case 'int':
				return (string) (int) $value;
			case 'float':
				return (string) (float) $value;
			default:
				return (string) $value;
		}
	}
}
