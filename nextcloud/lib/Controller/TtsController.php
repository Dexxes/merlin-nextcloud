<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ArticleStreamApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Vorlesen: dünner Controller über Merlin\Core\Api\ArticleStreamApi (Proxy
 * zum lokalen Piper-Daemon in Merlin\Core\Service\TtsStreamService).
 */
class TtsController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ArticleStreamApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/** GET /api/articles/{id}/tts?lang=de&speaker=… */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function synthesize(): Response {
		return $this->respond($this->api->synthesize($this->apiRequest()));
	}
}
