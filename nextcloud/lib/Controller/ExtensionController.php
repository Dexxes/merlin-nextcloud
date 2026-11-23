<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ExtensionApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Pocket-kompatible API der Browser-Erweiterungen: dünner Controller über
 * Merlin\Core\Api\ExtensionApi. NoCSRFRequired: nur Basic Auth, kein
 * requesttoken möglich.
 */
class ExtensionController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ExtensionApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function add(): Response {
		return $this->respond($this->api->add($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(): Response {
		return $this->respond($this->api->get($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function modify(): Response {
		return $this->respond($this->api->modify($this->apiRequest()));
	}
}
