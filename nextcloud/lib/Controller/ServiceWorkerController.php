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
  // Navigation requests (mode 'navigate') can't be re-fetched via
  // event.request directly – fetch() throws a TypeError on a
  // navigate-mode Request. Passing the plain URL instead makes an
  // equivalent same-origin request without that restriction. Requests
  // outside this worker's scope never reach this handler at all, so
  // this only ever fires for pages under /apps/merlin/.
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request.url));
    return;
  }
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
