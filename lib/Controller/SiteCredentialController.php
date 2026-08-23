<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Service\Login\LoginFailedException;
use OCA\Merlin\Service\SiteCredentialService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Personal-API für Paywall-Abo-Zugangsdaten (z. B. Tagesspiegel Plus): jeder
 * eingeloggte Nutzer verwaltet seine eigenen, privaten Zugangsdaten je Domain
 * - analog UserContentFilterController (userId kommt serverseitig aus der
 * Session, nie aus einem Request-Parameter).
 *
 * Passwörter werden NIE zurückgegeben - weder im Klartext noch verschlüsselt.
 * Die Liste (index()) zeigt nur Domain + Login-Status, damit die
 * Personal-Settings-UI einen "verbunden"/"Zugangsdaten prüfen"-Zustand
 * anzeigen kann.
 */
class SiteCredentialController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SiteCredentialService $service,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/** Alle eigenen Paywall-Zugangsdaten (Domain + Status, kein Passwort). */
	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->listForUser((string) $this->userId));
	}

	/**
	 * Legt Zugangsdaten für $domain an/überschreibt sie und führt sofort
	 * einen Login-Versuch aus, damit die UI direkt Rückmeldung geben kann
	 * ("Zugangsdaten gespeichert" vs. "Login fehlgeschlagen: falsches
	 * Passwort"). Rate-Limit wie UserContentFilterController::test() - ein
	 * Login-Versuch geht gegen die externe Paywall-Seite, nicht gegen Merlin.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function update(string $domain): DataResponse {
		$username = trim((string) ($this->request->getParam('username') ?? ''));
		$password = (string) ($this->request->getParam('password') ?? '');

		if ($username === '' || $password === '') {
			return new DataResponse(['message' => 'Benutzername und Passwort sind erforderlich.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->service->saveAndLogin((string) $this->userId, $domain, $username, $password);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (LoginFailedException $e) {
			// Zugangsdaten sind trotzdem gespeichert (siehe
			// SiteCredentialService::saveAndLogin()), damit ein späterer
			// automatischer Retry ohne erneute Eingabe funktioniert, falls
			// der Fehlschlag nur vorübergehend war (z. B. Seite kurz down).
			return new DataResponse(['message' => $e->getMessage(), 'reason' => $e->reason], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse(['domain' => $domain, 'status' => 'ok']);
	}

	#[NoAdminRequired]
	public function destroy(string $domain): DataResponse {
		$this->service->delete((string) $this->userId, $domain);
		return new DataResponse(['domain' => $domain, 'deleted' => true]);
	}
}
