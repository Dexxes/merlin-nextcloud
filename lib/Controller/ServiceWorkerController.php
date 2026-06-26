<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class ServiceWorkerController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): DataDisplayResponse {
		$js = <<<'JS'
// Merlin Service Worker — minimal pass-through (no offline caching)
self.addEventListener('fetch', (event) => {
  event.respondWith(fetch(event.request));
});
JS;

		$response = new DataDisplayResponse($js, 200, [
			'Content-Type'  => 'application/javascript',
			'Cache-Control' => 'no-cache, no-store, must-revalidate',
		]);
		return $response;
	}
}
