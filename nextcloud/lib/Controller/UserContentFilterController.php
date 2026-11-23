<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ContentFilterApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Persönliche Content-Filter-Overrides jedes Nutzers: dünner Controller
 * über Merlin\Core\Api\ContentFilterApi (der Nutzer kommt immer aus der
 * Anmeldung, nie aus einem Request-Parameter).
 *
 * Bewusst OHNE #[NoCSRFRequired] ('csrf' in der Routentabelle): bedient nur
 * die Personal-Settings-Weboberfläche, der requesttoken-Schutz des
 * AppFramework soll greifen.
 */
class UserContentFilterController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ContentFilterApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[NoAdminRequired]
	public function index(): Response {
		return $this->respond($this->api->userIndex($this->apiRequest()));
	}

	#[NoAdminRequired]
	public function show(): Response {
		return $this->respond($this->api->userShow($this->apiRequest()));
	}

	#[NoAdminRequired]
	public function update(): Response {
		return $this->respond($this->api->userUpdate($this->apiRequest()));
	}

	#[NoAdminRequired]
	public function destroy(): Response {
		return $this->respond($this->api->userDestroy($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function test(): Response {
		return $this->respond($this->api->userTest($this->apiRequest()));
	}
}
