<?php

declare(strict_types=1);

namespace OCA\Merlin\BackgroundJob;

use OCA\Merlin\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Löscht einmal täglich archivierte Artikel, deren Löschfrist abgelaufen ist,
 * sowie lange abgelaufene Share-Links. Registriert in appinfo/info.xml
 * (<background-jobs>); die Logik steht in RetentionService.
 */
class RetentionCleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private RetentionService $retentionService,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->retentionService->run();
	}
}
