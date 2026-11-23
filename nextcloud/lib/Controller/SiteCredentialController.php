<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\SiteCredentialApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Paywall-Abo-Zugangsdaten des Nutzers: dünner Controller über
 * Merlin\Core\Api\SiteCredentialApi. NoCSRFRequired, weil auch native
 * Clients (Basic Auth) sie nutzen; den Cookie-Pfad schützt
 * CsrfCookieAuthMiddleware.
 */
class SiteCredentialController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SiteCredentialApi $api,
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
	#[UserRateLimit(limit: 10, period: 300)]
	public function update(): Response {
		return $this->respond($this->api->update($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(): Response {
		return $this->respond($this->api->destroy($this->apiRequest()));
	}
}
