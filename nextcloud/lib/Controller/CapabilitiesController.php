<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\CapabilitiesApi;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * GET /api/capabilities ohne Anmeldung (Merlin\Core\Api\CapabilitiesApi):
 * Version, API-Version und Funktionen dieser Instanz.
 */
class CapabilitiesController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CapabilitiesApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/** GET /api/capabilities */
	#[PublicPage]
	#[NoCSRFRequired]
	public function show(): Response {
		return $this->respond($this->api->show($this->apiRequest()));
	}
}
