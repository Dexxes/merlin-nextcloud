<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\StorageApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Speicherverbrauch des Nutzers in der Datenbank: dünner Controller über
 * Merlin\Core\Api\StorageApi.
 */
class StorageController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private StorageApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(): Response {
		return $this->respond($this->api->get($this->apiRequest()));
	}
}
