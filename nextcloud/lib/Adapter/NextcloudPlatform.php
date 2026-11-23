<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\Platform;
use OCA\Merlin\AppInfo\Application;
use OCP\App\IAppManager;

/**
 * Platform-Port für /api/capabilities: Version der installierten App und
 * die Funktionen, die (noch) nur die Nextcloud-App anbietet.
 */
class NextcloudPlatform implements Platform {
	/**
	 * Funktionen außerhalb der Kern-API (Api\CapabilitiesApi::CORE_FEATURES).
	 * Zieht eine davon in den Kern um, wandert ihr Name dorthin.
	 */
	public const FEATURES = ['files', 'pwa'];

	public function __construct(
		private IAppManager $appManager,
	) {
	}

	public function backend(): string {
		return 'nextcloud';
	}

	public function version(): string {
		return $this->appManager->getAppVersion(Application::APP_ID);
	}

	public function features(): array {
		return self::FEATURES;
	}
}
