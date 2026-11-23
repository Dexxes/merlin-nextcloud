<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ReportApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Proxy zum merlin-reports-Meldungsbackend: dünner Controller über
 * Merlin\Core\Api\ReportApi.
 */
class ReportController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ReportApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	public function ping(): Response {
		return $this->respond($this->api->ping($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function report(): Response {
		return $this->respond($this->api->report($this->apiRequest()));
	}
}
