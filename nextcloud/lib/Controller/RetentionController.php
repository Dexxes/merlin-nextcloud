<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\RetentionApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Löschfrist: Bestätigung des einmaligen Hinweises, dünner Controller über
 * Merlin\Core\Api\RetentionApi.
 */
class RetentionController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private RetentionApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function acknowledgeNotice(): Response {
		return $this->respond($this->api->acknowledgeNotice($this->apiRequest()));
	}
}
