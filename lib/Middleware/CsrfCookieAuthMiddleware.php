<?php

declare(strict_types=1);

namespace OCA\Merlin\Middleware;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;

/**
 * Zustandsabhängiger CSRF-Schutz für die Merlin-API.
 *
 * Hintergrund: Alle API-Controller tragen #[NoCSRFRequired], weil dieselben
 * Endpunkte sowohl die Vue-Web-UI (Session-Cookie) als auch native Clients
 * (iOS, Android, Browser-Extensions) via HTTP Basic Auth bedienen. Native
 * Clients können keinen Nextcloud-requesttoken liefern; entfernte man
 * #[NoCSRFRequired], würde Nextclouds eigene CSRF-Prüfung diese Requests
 * abweisen (reguläre AppFramework-Routen akzeptieren Basic Auth nur mit
 * NoCSRFRequired oder als OCS-Route).
 *
 * Statt die Routen zu duplizieren, schließt diese Middleware die Lücke für den
 * Web-UI-Pfad gezielt: Sie erzwingt einen gültigen requesttoken NUR dann, wenn
 * der Request cookie-authentifiziert ist (Browser) UND eine zustandsändernde
 * Methode nutzt. Basic-/Bearer-authentifizierte Requests (native Clients) sowie
 * sichere Methoden (GET/HEAD/OPTIONS) und öffentliche Seiten bleiben unberührt.
 *
 * Ergebnis:
 *   - Web-UI-Writes: @nextcloud/axios sendet den requesttoken automatisch → OK.
 *   - Native Writes (Basic/Bearer): übersprungen → weiterhin funktionsfähig.
 *   - Cross-Site-Write mit geklautem Cookie, aber ohne Token: abgelehnt (412).
 */
class CsrfCookieAuthMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
		private IControllerMethodReflector $reflector,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		// Öffentliche Seiten (Share-Ansicht) bringen ihren eigenen Schutz mit
		// (Token + IThrottler) und sind von Nextclouds CSRF-Prüfung ohnehin
		// ausgenommen – nicht anfassen.
		if ($this->reflector->hasAnnotation('PublicPage')) {
			return;
		}

		// CSRF ist nur für zustandsändernde Methoden relevant. Sichere Methoden
		// auslassen – u. a. weil der SSE-Endpunkt (/api/events) per EventSource
		// läuft und technisch gar keinen requesttoken-Header senden KANN.
		$method = strtoupper($this->request->getMethod());
		if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
			return;
		}

		// Native Clients authentifizieren per HTTP Basic Auth bzw. Bearer/App-Token.
		// Solche Requests sind nicht CSRF-anfällig, da ein Browser diese Header
		// nicht automatisch cross-site mitschickt → überspringen.
		$auth = $this->request->getHeader('Authorization');
		if ($auth !== '' && (stripos($auth, 'basic ') === 0 || stripos($auth, 'bearer ') === 0)) {
			return;
		}

		// Verbleibender Fall: cookie-authentifizierter Browser-Request (Web-UI).
		// Hier MUSS ein gültiger requesttoken vorliegen. Fehlt/ungültig → als
		// Cross-Site-Request behandeln und ablehnen.
		if (!$this->request->passesCSRFCheck()) {
			throw new CsrfCheckFailedException();
		}
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if ($exception instanceof CsrfCheckFailedException) {
			return new JSONResponse(
				['error' => 'CSRF check failed'],
				Http::STATUS_PRECONDITION_FAILED,
			);
		}

		// Nicht von uns – an die nächste Middleware/den Framework-Handler weiterreichen.
		throw $exception;
	}
}
