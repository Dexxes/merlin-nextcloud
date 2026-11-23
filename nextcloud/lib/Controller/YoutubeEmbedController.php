<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\MediaApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * YouTube-Seite für die nativen Reader (Begründung bei
 * Merlin\Core\Api\MediaApi::youtubeEmbed()), ohne Anmeldung: sie trägt
 * nur eine öffentliche Video-ID.
 */
class YoutubeEmbedController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private MediaApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/** GET /api/youtube-embed?v=…&t=… */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(): Response {
		return $this->respond($this->api->youtubeEmbed($this->apiRequest()));
	}
}
