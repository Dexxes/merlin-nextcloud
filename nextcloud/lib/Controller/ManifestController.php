<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\PwaApi;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * GET /manifest.webmanifest ohne Anmeldung (Merlin\Core\Api\PwaApi, gemeinsam mit dem
 * Standalone-Server): PWA für „Zum Startbildschirm“.
 */
class ManifestController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private PwaApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/** GET /manifest.webmanifest */
	#[PublicPage]
	#[NoCSRFRequired]
	public function index(): Response {
		return $this->respond($this->api->manifest($this->apiRequest()));
	}
}
