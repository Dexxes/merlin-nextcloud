<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Port\UserSettings;
use Merlin\Core\Service\ReportBackendRules;
use Merlin\Core\Service\ReportBackendService;

/**
 * Proxy zum merlin-reports-Meldungsbackend: Verbindungstest in den
 * Einstellungen und „Fehlerhaft dargestellten Artikel melden“. Läuft
 * serverseitig, damit am Backend kein CORS nötig ist und Zugangsdaten (Basic
 * Auth aus der URL) mitgehen. Antworten wie bisher in ReportController.
 */
final class ReportApi {
	public function __construct(
		private ReportBackendService $service,
		private UserSettings $settings,
	) {
	}

	/**
	 * GET /api/report-backend/ping?url=…: prüft die übergebene Backend-URL
	 * (die Einstellungen pingen den gerade eingetragenen Wert).
	 * Antwort: {status: "ok"|"auth"|"error", httpStatus: int|null}
	 */
	public function ping(ApiRequest $request): ApiResult {
		return ApiResult::json($this->service->ping((string) ($request->string('url') ?? '')));
	}

	/** POST /api/report-backend/report: url, comment; an das gespeicherte Backend. */
	public function report(ApiRequest $request): ApiResult {
		$backend = trim($this->settings->get($request->userId(), SettingsApi::SECTION, 'reportBackendUrl', ''));
		if ($backend === '') {
			return ApiResult::json(['status' => 'unconfigured'], 400);
		}
		$url = (string) ($request->string('url') ?? '');
		if ($url === '' || !preg_match('#^https?://#i', $url)) {
			return ApiResult::json(['status' => ReportBackendRules::STATUS_ERROR], 400);
		}
		$comment = mb_substr(trim((string) ($request->string('comment') ?? '')), 0, 5000);
		$result = $this->service->report($backend, $url, $comment);
		return ApiResult::json($result, $result['status'] === ReportBackendRules::STATUS_OK ? 200 : 502);
	}
}
