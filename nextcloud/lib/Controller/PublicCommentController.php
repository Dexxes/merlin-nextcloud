<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\PublicCommentApi;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Markieren und Kommentieren als Gast hinter einem Share-Link, ohne Login:
 * dünner Controller über Merlin\Core\Api\PublicCommentApi (Routen in
 * core/src/Api/routes.php). Das Rate-Limit je IP steht dort als 'rateLimit'
 * und hier als #[AnonRateLimit]; tools/test-route-parity.php prüft, dass
 * beides übereinstimmt.
 */
class PublicCommentController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private PublicCommentApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function index(): Response {
		return $this->respond($this->api->index($this->apiRequest()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function guest(): Response {
		return $this->respond($this->api->guest($this->apiRequest()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function create(): Response {
		return $this->respond($this->api->create($this->apiRequest()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function update(): Response {
		return $this->respond($this->api->update($this->apiRequest()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function destroy(): Response {
		return $this->respond($this->api->destroy($this->apiRequest()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function createHighlight(): Response {
		return $this->respond($this->api->createHighlight($this->apiRequest()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function destroyHighlight(): Response {
		return $this->respond($this->api->destroyHighlight($this->apiRequest()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function events(): Response {
		return $this->respond($this->api->events($this->apiRequest()));
	}
}
