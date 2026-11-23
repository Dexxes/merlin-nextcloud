<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\FileApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * „Merlin Dateien“ (Merlin\Core\Api\FileApi, gemeinsam mit dem
 * Standalone-Server): Upload-Ziel für WebDAV, Eintrag anlegen, umbenennen,
 * Datei über signierten Link. Ordner und Upload: Adapter\NextcloudFileStorage.
 *
 * NoCSRFRequired wie bei ArticleController (native Clients mit Basic-Auth).
 */
class FileController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private FileApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/** POST /api/files/target */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function target(): Response {
		return $this->respond($this->api->target($this->apiRequest()));
	}

	/** POST /api/files */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function register(): Response {
		return $this->respond($this->api->register($this->apiRequest()));
	}

	/** PUT /api/articles/{id}/file-name */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rename(): Response {
		return $this->respond($this->api->rename($this->apiRequest()));
	}

	/** GET /api/articles/{id}/file: ohne Login, nur mit gültigem Token t. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function content(): Response {
		return $this->respond($this->api->content($this->apiRequest()));
	}
}
