<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Service\RetentionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Admin-API der Löschfrist (Verwaltungseinstellungen, RetentionAdmin.vue).
 *
 * Wie ContentFilterController bewusst ohne NoAdminRequired/NoCSRFRequired:
 * nur Admins, und nur aus der Web-Oberfläche mit requesttoken.
 */
class RetentionAdminController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private RetentionService $retentionService,
	) {
		parent::__construct($appName, $request);
	}

	public function show(): DataResponse {
		return new DataResponse($this->retentionService->getAdminLimits());
	}

	public function update(int $days = 0, int $favoritesDays = 0): DataResponse {
		$this->retentionService->setAdminLimits($days, $favoritesDays);
		return new DataResponse($this->retentionService->getAdminLimits());
	}

	/**
	 * Wie viele archivierte Artikel der nächste Lauf mit diesen Werten löschen
	 * würde – die Oberfläche fragt vor dem Speichern einer Verkürzung nach.
	 */
	public function preview(int $days = 0, int $favoritesDays = 0): DataResponse {
		return new DataResponse([
			'count' => $this->retentionService->preview($days, $favoritesDays),
		]);
	}
}
