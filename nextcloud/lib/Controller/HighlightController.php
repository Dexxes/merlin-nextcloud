<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\HighlightApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Markierungen des Besitzers: dünner Controller über Merlin\Core\Api\HighlightApi
 * (Routen in core/src/Api/routes.php, CSRF siehe CoreApiController).
 */
class HighlightController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private HighlightApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): Response {
		return $this->respond($this->api->index($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): Response {
		return $this->respond($this->api->create($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(): Response {
		return $this->respond($this->api->update($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(): Response {
		return $this->respond($this->api->destroy($this->apiRequest()));
	}
}
