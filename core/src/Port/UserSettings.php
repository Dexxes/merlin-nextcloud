<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Einstellungen eines Nutzers als Zeichenketten, gruppiert nach Bereich
 * (z. B. "reader" für das Erscheinungsbild des Readers).
 *
 * Nextcloud: IConfig::getUserValue()/setUserValue() mit $section als appid.
 */
interface UserSettings {
	public function get(string $userId, string $section, string $key, string $default = ''): string;

	public function set(string $userId, string $section, string $key, string $value): void;
}
