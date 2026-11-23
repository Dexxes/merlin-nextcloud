<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ShareApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Share-Links eines Artikels verwalten: dünner Controller über
 * Merlin\Core\Api\ShareApi (Routen in core/src/Api/routes.php).
 */
class ShareController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ShareApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(): Response {
		return $this->respond($this->api->show($this->apiRequest()));
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
	public function regenerate(): Response {
		return $this->respond($this->api->regenerate($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(): Response {
		return $this->respond($this->api->destroy($this->apiRequest()));
	}
}
