<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Translator;

/**
 * Translator-Port (in Nextcloud: IL10N). Vorerst ohne Übersetzungstabellen:
 * gibt den englischen Text zurück und setzt Parameter wie IL10N::t() ein
 * (%s, %1$s). Die Texte des Kerns kommen später aus merlin-translations.
 *
 * @param array<string, string> $messages optionale Übersetzungen Englisch => Ziel
 */
final class StandaloneTranslator implements Translator {
	/** @param array<string, string> $messages */
	public function __construct(private array $messages = []) {
	}

	public function t(string $text, array $parameters = []): string {
		$text = $this->messages[$text] ?? $text;
		if ($parameters === []) {
			return $text;
		}
		try {
			return vsprintf($text, array_values($parameters));
		} catch (\ValueError) {
			return $text;
		}
	}
}
