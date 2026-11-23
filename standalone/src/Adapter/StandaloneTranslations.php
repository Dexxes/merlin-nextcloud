<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Translations;
use Merlin\Core\Port\Translator;
use Merlin\Core\Port\UserSettings;
use Merlin\Standalone\I18n\AppTranslations;
use Merlin\Standalone\I18n\LocaleResolver;

/**
 * Translations-Port: Texte des Kerns in der Sprache eines Nutzers (z. B.
 * Datei-Einträge, die ohne Request im Hintergrund entstehen). Sprache aus
 * den Nutzereinstellungen core/lang wie LocaleResolver, Texte aus
 * I18n\AppTranslations (nextcloud/l10n).
 */
final class StandaloneTranslations implements Translations {
	public function __construct(private UserSettings $settings) {
	}

	public function forUser(string $userId): Translator {
		$locale = $this->settings->get($userId, LocaleResolver::SETTINGS_SECTION, LocaleResolver::SETTINGS_KEY);
		if (!in_array($locale, LocaleResolver::SUPPORTED, true)) {
			$locale = LocaleResolver::DEFAULT;
		}
		$messages = array_filter(AppTranslations::load($locale) ?? [], 'is_string');
		return new StandaloneTranslator($messages);
	}
}
