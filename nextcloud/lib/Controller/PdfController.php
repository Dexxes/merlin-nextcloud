<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ArticleStreamApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * PDF eines PDF-Artikels: dünner Controller über Merlin\Core\Api\ArticleStreamApi
 * (Durchreichung in PdfProxyService, Dateien aus „Merlin Dateien“ über den
 * Port ArticleFiles).
 */
class PdfController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ArticleStreamApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/** GET /api/articles/{id}/pdf */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(): Response {
		return $this->respond($this->api->show($this->apiRequest()));
	}
}
