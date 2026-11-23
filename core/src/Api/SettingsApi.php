<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Port\UserSettings;
use Merlin\Core\Service\RetentionPolicy;
use Merlin\Core\Service\RetentionService;

/**
 * Leseeinstellungen des Nutzers (GET/PUT /api/settings) für beide Varianten.
 * Antworten wie bisher in OCA\Merlin\Controller\SettingsController.
 *
 * Gespeichert wird über den Port UserSettings im Abschnitt 'reader'
 * (Nextcloud: IConfig-User-Values der App „reader“, Standalone:
 * user_settings), jeder Wert als String.
 */
final class SettingsApi {
	public const SECTION = 'reader';

	public const DEFAULT_SETTINGS = [
		'theme' => 'auto', // 'light', 'dark', 'auto', 'sepia'
		'fontSize' => '17', // numerischer Wert in px (z.B. 13, 15, 17, 19, 21, 24)
		'fontFamily' => 'default', // 'default', 'serif', 'sans-serif', 'monospace'
		'lineHeight' => '1.6',
		'defaultView' => 'pages-unread', // 'pages-unread', 'pages-favorites', 'pages-archived', 'videos-…', 'audio-…', 'files-…'
		'saveProgress' => '1', // boolean: remember scroll position per article
		'resumeOnOpen' => '1', // boolean: restore scroll position when reopening
		// progressEdge ersetzt showProgress: 'left', 'right', 'top', 'bottom', 'off'
		// 'off' bedeutet keinen Fortschrittsbalken; im Web-Reader wird immer bottom verwendet.
		'progressEdge' => 'left',
		'reportBackendUrl' => '', // URL des merlin-reports-Backends (z.B. https://cloud.example.com/merlin-reports/)
		'accentColor' => '#FF3B30', // Akzentfarbe für Fortschrittsbalken (iOS/Android), siehe PreferencesStore
		'excludedTagIds' => '[]', // JSON-Array von Tag-IDs, die aus der Artikelliste ausgeblendet werden (Pendant zu iOS/Android TagFilterSheet)
		// Löschfrist archivierter Artikel in Tagen, 0 = so lange wie der Admin erlaubt
		// (siehe RetentionService). Der Admin-Wert begrenzt nach oben; ein höherer
		// Nutzerwert wird gespeichert, wirkt aber als Admin-Maximum.
		'retentionDays' => '0',
		'retentionFavoritesDays' => '0',
	];

	// Gespeichert wird nur als String. Ohne diese Typ-Tabelle lieferte get()
	// immer Strings zurück, während der Web-Client nach dem Speichern optimistisch
	// die eigenen (Number/Boolean-)Typen in den Store committet — der nächste
	// 15-s-Poll sähe dann garantiert ein anderes JSON.stringify-Ergebnis und löste
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
		'retentionDays' => 'int',
		'retentionFavoritesDays' => 'int',
	];

	/** Tage-Werte, die auf den gültigen Bereich der Löschfrist begrenzt werden. */
	private const RETENTION_KEYS = ['retentionDays', 'retentionFavoritesDays'];

	public function __construct(
		private UserSettings $settings,
		private RetentionService $retention,
	) {
	}

	/**
	 * GET /api/settings: alle Einstellungen, dazu nur lesend Admin-Maximum,
	 * effektive Fristen und ob der einmalige Löschfrist-Hinweis fällig ist
	 * (werden beim PUT ignoriert, da sie nicht in DEFAULT_SETTINGS stehen).
	 */
	public function get(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$settings = [];
		foreach (self::DEFAULT_SETTINGS as $key => $default) {
			$settings[$key] = self::castForResponse($key, $this->settings->get($userId, self::SECTION, $key, $default));
		}
		$settings += $this->retention->getSettingsInfo($userId);
		return ApiResult::json($settings);
	}

	/**
	 * PUT /api/settings: die Clients schicken die Einstellungen als flaches
	 * JSON-Objekt; unbekannte Schlüssel werden ignoriert. Zurück kommen die
	 * kanonisch typisierten, tatsächlich gespeicherten Werte, damit der
	 * Web-Client sie 1:1 übernehmen kann, plus die effektiven Fristen.
	 */
	public function update(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$saved = [];
		foreach ($request->all() as $key => $value) {
			if (array_key_exists($key, self::DEFAULT_SETTINGS)) {
				$stored = self::castForStorage($key, $value);
				$this->settings->set($userId, self::SECTION, $key, $stored);
				$saved[$key] = self::castForResponse($key, $stored);
			}
		}
		$saved += $this->retention->getSettingsInfo($userId);
		return ApiResult::json(['success' => true, 'settings' => $saved]);
	}

	/** Gespeicherter String → JSON-Wert, den der Client erwartet (bool/int/float/string). */
	private static function castForResponse(string $key, string $raw): string|int|float|bool {
		return match (self::SETTINGS_TYPES[$key] ?? 'string') {
			'bool' => $raw === '1',
			'int' => (int) $raw,
			'float' => (float) $raw,
			default => $raw,
		};
	}

	/**
	 * Client-Wert (Number/Boolean/String, je nach Plattform) → gespeicherter
	 * String. Booleans bewusst nicht blind (string): (string) false ergibt ""
	 * statt "0", und ein String "false" wäre sonst truthy.
	 */
	private static function castForStorage(string $key, mixed $value): string {
		switch (self::SETTINGS_TYPES[$key] ?? 'string') {
			case 'bool':
				$bool = is_string($value)
					? in_array(strtolower($value), ['1', 'true'], true)
					: (bool) $value;
				return $bool ? '1' : '0';
			case 'int':
				$int = is_scalar($value) ? (int) $value : 0;
				if (in_array($key, self::RETENTION_KEYS, true)) {
					$int = RetentionPolicy::normalizeDays($int);
				}
				return (string) $int;
			case 'float':
				return (string) (is_scalar($value) ? (float) $value : 0.0);
			default:
				return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
		}
	}
}
