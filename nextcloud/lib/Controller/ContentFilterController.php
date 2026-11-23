<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ContentFilterApi;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Verwaltung der Content-Filter durch Admins: dünner Controller über
 * Merlin\Core\Api\ContentFilterApi.
 *
 * Bewusst OHNE #[NoAdminRequired] und OHNE #[NoCSRFRequired]: bedient nur die
 * Admin-Weboberfläche, nie einen nativen Client. Damit greifen beide
 * Standardschutzmechanismen des AppFramework (Adminrechte, requesttoken);
 * ein Filter gilt instanzweit für alle Nutzer.
 */
class ContentFilterController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ContentFilterApi $api,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	public function index(): Response {
		return $this->respond($this->api->index($this->apiRequest()));
	}

	public function show(): Response {
		return $this->respond($this->api->show($this->apiRequest()));
	}

	public function update(): Response {
		return $this->respond($this->api->update($this->apiRequest()));
	}

	public function destroy(): Response {
		return $this->respond($this->api->destroy($this->apiRequest()));
	}

	public function import(): Response {
		return $this->respond($this->api->import($this->apiRequest()));
	}

	public function export(): Response {
		return $this->respond($this->api->export($this->apiRequest()));
	}

	#[UserRateLimit(limit: 20, period: 300)]
	public function test(): Response {
		return $this->respond($this->api->test($this->apiRequest()));
	}
}
