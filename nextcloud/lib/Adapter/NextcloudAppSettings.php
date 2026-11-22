<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\AppSettings;
use OCA\Merlin\AppInfo\Application;
use OCP\IAppConfig;

/**
 * AppSettings-Port des Kerns über IAppConfig (App-ID "merlin").
 */
class NextcloudAppSettings implements AppSettings {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getInt(string $key, int $default = 0): int {
		return $this->appConfig->getValueInt(Application::APP_ID, $key, $default);
	}

	public function setInt(string $key, int $value): void {
		$this->appConfig->setValueInt(Application::APP_ID, $key, $value);
	}
}
