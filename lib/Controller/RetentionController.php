<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Service\RetentionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Nutzer-API der Löschfrist: Bestätigung des einmaligen Hinweises (Web-Popup
 * und iOS). Die Fristen selbst liest und schreibt der Client über
 * GET/PUT /api/settings (SettingsController).
 *
 * NoCSRFRequired aus demselben Grund wie in SettingsController (native
 * Clients); den Web-Pfad schützt CsrfCookieAuthMiddleware.
 */
class RetentionController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private RetentionService $retentionService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function acknowledgeNotice(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		$this->retentionService->acknowledgeNotice($this->userId);
		return new DataResponse($this->retentionService->getSettingsInfo($this->userId));
	}
}
