<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\RetentionApi;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Admin-Maximum der Löschfrist: dünner Controller über
 * Merlin\Core\Api\RetentionApi. Ohne NoAdminRequired/NoCSRFRequired: nur
 * Admins, nur aus der Weboberfläche mit requesttoken.
 */
class RetentionAdminController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private RetentionApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	public function show(): Response {
		return $this->respond($this->api->adminShow($this->apiRequest()));
	}

	public function update(): Response {
		return $this->respond($this->api->adminUpdate($this->apiRequest()));
	}

	public function preview(): Response {
		return $this->respond($this->api->adminPreview($this->apiRequest()));
	}
}
