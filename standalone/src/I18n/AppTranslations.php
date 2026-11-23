<?php

declare(strict_types=1);

namespace Merlin\Standalone\I18n;

/**
 * Übersetzungen der App und des Kerns aus nextcloud/l10n/{locale}.json
 * (dieselben wie in Nextcloud); Englisch ist der Quelltext. Ein
 * Release-Paket ohne nextcloud/ legt sie nach standalone/l10n/.
 * Genutzt von AppController (Startwerte der Vue-App) und
 * Adapter\StandaloneTranslations (Texte des Kerns, z. B. Datei-Einträge).
 */
final class AppTranslations {
	/** @var array<string, array<string, string|list<string>>|null> */
	private static array $cache = [];

	/** @return array<string, string|list<string>>|null null für Englisch oder ohne Datei */
	public static function load(string $locale): ?array {
		if ($locale === 'en' || !preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale)) {
			return null;
		}
		if (array_key_exists($locale, self::$cache)) {
			return self::$cache[$locale];
		}
		$root = dirname(__DIR__, 2);
		foreach ([$root . '/l10n/' . $locale . '.json', dirname($root) . '/nextcloud/l10n/' . $locale . '.json'] as $file) {
			if (is_file($file)) {
				$bundle = json_decode((string) file_get_contents($file), true);
				return self::$cache[$locale] = is_array($bundle['translations'] ?? null) ? $bundle['translations'] : null;
			}
		}
		return self::$cache[$locale] = null;
	}
}
