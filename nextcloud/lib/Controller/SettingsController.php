<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\SettingsApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Leseeinstellungen des Nutzers: dünner Controller über
 * Merlin\Core\Api\SettingsApi (Routen in core/src/Api/routes.php).
 */
class SettingsController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(): Response {
		return $this->respond($this->api->get($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(): Response {
		return $this->respond($this->api->update($this->apiRequest()));
	}
}
