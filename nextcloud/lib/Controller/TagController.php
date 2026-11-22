<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\TagApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Schlagwort-API: die Logik steht in Merlin\Core\Api\TagApi (gemeinsam mit
 * dem Standalone-Server), Routen in core/src/Api/routes.php.
 */
class TagController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private TagApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/** GET /api/tags */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): Response {
		return $this->respond($this->api->index($this->apiRequest()));
	}

	/** POST /api/tags */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): Response {
		return $this->respond($this->api->create($this->apiRequest()));
	}

	/** PUT /api/tags/{id} */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(): Response {
		return $this->respond($this->api->update($this->apiRequest()));
	}

	/** DELETE /api/tags/{id} */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(): Response {
		return $this->respond($this->api->destroy($this->apiRequest()));
	}

	/** POST /api/articles/{articleId}/tags/{tagId} */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function addToArticle(): Response {
		return $this->respond($this->api->addToArticle($this->apiRequest()));
	}

	/** DELETE /api/articles/{articleId}/tags/{tagId} */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function removeFromArticle(): Response {
		return $this->respond($this->api->removeFromArticle($this->apiRequest()));
	}
}
