<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Übersetzungen in der Sprache eines Nutzers, für Texte, die gespeichert
 * werden (Ordnernamen, Inhalt von Datei-Einträgen), egal in welcher Sprache
 * das Gerät gerade fragt.
 *
 * Nextcloud: IL10N der App in der Nextcloud-Sprache des Nutzers.
 * Standalone: Übersetzungen der App (l10n/*.json) in der Kontosprache.
 */
interface Translations {
	public function forUser(string $userId): Translator;
}
