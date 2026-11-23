<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Service\RetentionService;

/**
 * Löschfrist archivierter Artikel: Bestätigung des einmaligen Hinweises
 * (Nutzer, Web-Popup und iOS) und das Admin-Maximum (Verwaltung,
 * RetentionAdmin.vue). Die Fristen des Nutzers selbst laufen über
 * GET/PUT /api/settings (SettingsApi). Antworten wie bisher in
 * RetentionController und RetentionAdminController.
 */
final class RetentionApi {
	public function __construct(private RetentionService $retention) {
	}

	/** POST /api/retention/notice */
	public function acknowledgeNotice(ApiRequest $request): ApiResult {
		if (!$request->hasUser()) {
			return ApiResult::error('Not authenticated', 401);
		}
		$this->retention->acknowledgeNotice($request->userId());
		return ApiResult::json($this->retention->getSettingsInfo($request->userId()));
	}

	/** GET /api/admin/retention */
	public function adminShow(ApiRequest $request): ApiResult {
		return ApiResult::json($this->retention->getAdminLimits());
	}

	/** PUT /api/admin/retention: days, favoritesDays (0 = unbegrenzt) */
	public function adminUpdate(ApiRequest $request): ApiResult {
		$this->retention->setAdminLimits((int) $request->int('days', 0), (int) $request->int('favoritesDays', 0));
		return ApiResult::json($this->retention->getAdminLimits());
	}

	/**
	 * GET /api/admin/retention/preview: wie viele archivierte Artikel der
	 * nächste Lauf mit diesen Werten löschen würde (die Oberfläche fragt vor
	 * dem Speichern einer Verkürzung nach).
	 */
	public function adminPreview(ApiRequest $request): ApiResult {
		return ApiResult::json([
			'count' => $this->retention->preview((int) $request->int('days', 0), (int) $request->int('favoritesDays', 0)),
		]);
	}
}
