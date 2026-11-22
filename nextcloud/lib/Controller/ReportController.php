<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Service\ReportBackendRules;
use Merlin\Core\Service\ReportBackendService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Proxy zum merlin-reports-Meldungsbackend: Verbindungstest in den
 * Einstellungen und "Fehlerhaft dargestellten Artikel melden". Läuft
 * serverseitig, damit kein CORS am Backend nötig ist und Zugangsdaten
 * (HTTP Basic Auth aus der URL) mitgeschickt werden können.
 *
 * Security note – NoCSRFRequired: siehe SettingsController (gleiches Muster,
 * CSRF für den Cookie-Pfad übernimmt CsrfCookieAuthMiddleware).
 */
class ReportController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ReportBackendService $service,
		private IConfig $config,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Prüft die übergebene Backend-URL (die Einstellungen pingen den gerade
	 * eingetragenen Wert, nicht zwingend den schon gespeicherten).
	 * Antwort: {status: "ok"|"auth"|"error", httpStatus: int|null}
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	public function ping(string $url = ''): DataResponse {
		return new DataResponse($this->service->ping($url));
	}

	/**
	 * Meldet einen Artikel an das in den Einstellungen gespeicherte Backend.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function report(string $url = '', string $comment = ''): DataResponse {
		$backend = trim($this->config->getUserValue((string) $this->userId, 'reader', 'reportBackendUrl', ''));
		if ($backend === '') {
			return new DataResponse(['status' => 'unconfigured'], Http::STATUS_BAD_REQUEST);
		}
		if ($url === '' || !preg_match('#^https?://#i', $url)) {
			return new DataResponse(['status' => ReportBackendRules::STATUS_ERROR], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->service->report($backend, $url, mb_substr(trim($comment), 0, 5000));
		$code = $result['status'] === ReportBackendRules::STATUS_OK ? Http::STATUS_OK : Http::STATUS_BAD_GATEWAY;
		return new DataResponse($result, $code);
	}
}
