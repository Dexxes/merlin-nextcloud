<?php

declare(strict_types=1);

namespace Merlin\Standalone\Db;

use Merlin\Core\Port\Connection;
use Merlin\Standalone\Adapter\StandaloneAppSettings;

/**
 * Schalter der Konto-Verwaltung in der Tabelle settings (z. B. ob sich
 * jede*r selbst registrieren darf). Aus merlin-standalone-server
 * (Db/SettingsRepository.php, fe8ed23), jetzt über den Connection-Port.
 */
final class ServerSettings {
	public const ALLOW_SELF_REGISTRATION = 'allow_self_registration';

	public function __construct(private Connection $db) {
	}

	public function get(string $key, string $default = ''): string {
		$value = $this->db->fetchValue('SELECT value FROM *PREFIX*settings WHERE name = :name', ['name' => $key]);
		return $value === null ? $default : (string) $value;
	}

	public function getBool(string $key, bool $default = false): bool {
		return $this->get($key, $default ? '1' : '0') === '1';
	}

	public function set(string $key, string $value): void {
		StandaloneAppSettings::upsert($this->db, 'settings', ['name' => $key], ['value' => $value]);
	}
}
