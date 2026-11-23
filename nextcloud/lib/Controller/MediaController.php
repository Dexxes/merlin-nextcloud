<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\MediaApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Audio-/Videoquelle eines Artikels: dünner Controller über
 * Merlin\Core\Api\MediaApi.
 */
class MediaController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private MediaApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/** GET /api/articles/{id}/media */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function resolve(): Response {
		return $this->respond($this->api->resolve($this->apiRequest()));
	}
}
