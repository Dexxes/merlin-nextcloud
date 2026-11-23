<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\CommentApi;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Kommentare des Besitzers samt Push-Kanal: dünner Controller über
 * Merlin\Core\Api\CommentApi (Routen in core/src/Api/routes.php).
 */
class CommentController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CommentApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): Response {
		return $this->respond($this->api->index($this->apiRequest()));
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
	public function destroy(): Response {
		return $this->respond($this->api->destroy($this->apiRequest()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stream(): Response {
		return $this->respond($this->api->stream($this->apiRequest()));
	}
}
