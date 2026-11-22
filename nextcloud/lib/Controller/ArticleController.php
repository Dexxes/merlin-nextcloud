<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ArticleApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Artikel-API: die Logik steht in Merlin\Core\Api\ArticleApi (gemeinsam mit
 * dem Standalone-Server), Routen in core/src/Api/routes.php.
 */
class ArticleController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ArticleApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/** GET /api/articles/counts */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function counts(): Response {
		return $this->respond($this->api->counts($this->apiRequest()));
	}

	/** GET /api/articles */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): Response {
		return $this->respond($this->api->index($this->apiRequest()));
	}

	/** GET /api/articles/search */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function search(): Response {
		return $this->respond($this->api->search($this->apiRequest()));
	}

	/** GET /api/articles/{id} */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(): Response {
		return $this->respond($this->api->show($this->apiRequest()));
	}

	/** POST /api/articles */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): Response {
		return $this->respond($this->api->create($this->apiRequest()));
	}

	/** PUT /api/articles/{id} */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(): Response {
		return $this->respond($this->api->update($this->apiRequest()));
	}

	/** DELETE /api/articles/{id} */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(): Response {
		return $this->respond($this->api->destroy($this->apiRequest()));
	}

	/** PUT /api/articles/{id}/read */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function toggleRead(): Response {
		return $this->respond($this->api->toggleRead($this->apiRequest()));
	}

	/** PUT /api/articles/{id}/favorite */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function toggleFavorite(): Response {
		return $this->respond($this->api->toggleFavorite($this->apiRequest()));
	}

	/** PUT /api/articles/{id}/archive */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function toggleArchive(): Response {
		return $this->respond($this->api->toggleArchive($this->apiRequest()));
	}

	/** PUT /api/articles/{id}/progress */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateProgress(): Response {
		return $this->respond($this->api->updateProgress($this->apiRequest()));
	}

	/** POST /api/articles/{id}/retry-extraction */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function retryExtraction(): Response {
		return $this->respond($this->api->retryExtraction($this->apiRequest()));
	}

	/** GET /api/articles/{id}/export/html */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportHtml(): Response {
		return $this->respond($this->api->exportHtml($this->apiRequest()));
	}

	/** GET /api/events (Server-Sent Events) */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stream(): Response {
		return $this->respond($this->api->stream($this->apiRequest()));
	}
}
