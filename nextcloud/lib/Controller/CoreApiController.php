<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ApiRequest;
use Merlin\Core\Api\ApiResult;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Basis der dünnen Controller für Routen aus core/src/Api/routes.php: jede
 * Methode reicht den Request an ihren Kern-Handler weiter und gibt dessen
 * ApiResult als Nextcloud-Response zurück. Zugriff (NoAdminRequired,
 * PublicPage, NoCSRFRequired) steht als Attribut an der Methode;
 * tools/test-route-parity.php prüft, dass er zur Routentabelle passt.
 *
 * Zu NoCSRFRequired: dieselben Routen bedienen die Web-Oberfläche (Cookie)
 * und native Clients mit Basic Auth, die keinen requesttoken haben. Den
 * CSRF-Schutz für Cookie-Anfragen erzwingt CsrfCookieAuthMiddleware.
 */
abstract class CoreApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/** Parameter wie bei Nextclouds Controller-Argumenten: Query, Body, Pfad. */
	protected function apiRequest(): ApiRequest {
		$headers = [];
		foreach (ApiRequest::HEADERS as $name) {
			$headers[$name] = $this->request->getHeader($name);
		}
		return new ApiRequest(
			$this->userId,
			$this->request->getParams(),
			static fn (): string => (string) file_get_contents('php://input'),
			$headers,
			$this->request->getRemoteAddress(),
		);
	}

	protected function respond(ApiResult $result): Response {
		switch ($result->kind) {
			case ApiResult::STREAM:
				$this->stream($result);
				// stream() endet mit exit; nur für statische Analyse:
				return new DataResponse(null);
			case ApiResult::BODY:
				$response = new DataDisplayResponse((string) $result->data, $result->status);
				foreach ($result->headers as $name => $value) {
					$response->addHeader($name, $value);
				}
				return $response;
			default:
				return new DataResponse($result->data, $result->status);
		}
	}

	/**
	 * Server-Sent Events an Nextclouds Response-Verarbeitung vorbei: Puffer
	 * leeren, Header sofort setzen (auch ein Fehler danach bleibt ein
	 * SSE-Stream), Session freigeben, Emitter laufen lassen, beenden.
	 */
	private function stream(ApiResult $result): never {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		foreach ($result->headers as $name => $value) {
			header("{$name}: {$value}");
		}
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		@ini_set('output_buffering', 'Off');
		@ini_set('implicit_flush', '1');
		ob_implicit_flush(true);
		($result->emitter)();
		exit(0);
	}
}
