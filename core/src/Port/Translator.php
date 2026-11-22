<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Übersetzungen der Oberflächentexte in einer festen Sprache.
 *
 * Nextcloud: IL10N der App in der Sprache des Nutzers.
 */
interface Translator {
	/**
	 * @param array<int|string, mixed> $parameters Platzhalter wie bei sprintf
	 */
	public function t(string $text, array $parameters = []): string;
}
