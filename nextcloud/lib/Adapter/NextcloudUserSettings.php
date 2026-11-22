<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\UserSettings;
use OCP\IConfig;

/**
 * UserSettings-Port des Kerns über Nextclouds IConfig (Tabelle
 * oc_preferences, $section als appid).
 */
class NextcloudUserSettings implements UserSettings {
	public function __construct(
		private IConfig $config,
	) {
	}

	public function get(string $userId, string $section, string $key, string $default = ''): string {
		return (string) $this->config->getUserValue($userId, $section, $key, $default);
	}
}
