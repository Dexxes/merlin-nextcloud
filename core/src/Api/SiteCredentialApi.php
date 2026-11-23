<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Extraction\Login\LoginFailedException;
use Merlin\Core\Service\SiteCredentialService;

/**
 * Paywall-Abo-Zugangsdaten (z. B. Tagesspiegel Plus) für beide Varianten:
 * jeder Nutzer verwaltet seine eigenen Zugangsdaten je Domain, der Nutzer
 * kommt immer aus der Anmeldung. Passwörter werden nie zurückgegeben, weder im
 * Klartext noch verschlüsselt. Antworten wie bisher in SiteCredentialController.
 */
final class SiteCredentialApi {
	public function __construct(private SiteCredentialService $service) {
	}

	/**
	 * GET /api/user/site-credentials: eigene Zugangsdaten (Domain + Status)
	 * und alle Domains mit Paywall-Login (Auswahl „Abo hinzufügen“).
	 */
	public function index(ApiRequest $request): ApiResult {
		return ApiResult::json([
			'credentials' => $this->service->listForUser($request->userId()),
			'availableDomains' => $this->service->listLoginCapableDomains(),
		]);
	}

	/**
	 * PUT /api/user/site-credentials/{domain}: username, password. Speichert
	 * und meldet sich sofort an, damit die UI gleich Rückmeldung geben kann.
	 * Scheitert die Anmeldung, bleiben die Zugangsdaten trotzdem gespeichert
	 * (spätere automatische Versuche ohne neue Eingabe), Antwort 401 mit reason.
	 */
	public function update(ApiRequest $request): ApiResult {
		$domain = (string) ($request->string('domain') ?? '');
		$username = trim((string) ($request->string('username') ?? ''));
		$password = (string) ($request->string('password') ?? '');
		if ($username === '' || $password === '') {
			return ApiResult::json(['message' => 'Benutzername und Passwort sind erforderlich.'], 400);
		}
		try {
			$this->service->saveAndLogin($request->userId(), $domain, $username, $password);
		} catch (\InvalidArgumentException $e) {
			return ApiResult::json(['message' => $e->getMessage()], 400);
		} catch (LoginFailedException $e) {
			return ApiResult::json(['message' => $e->getMessage(), 'reason' => $e->reason], 401);
		}
		return ApiResult::json(['domain' => $domain, 'status' => 'ok']);
	}

	/** DELETE /api/user/site-credentials/{domain} */
	public function destroy(ApiRequest $request): ApiResult {
		$domain = (string) ($request->string('domain') ?? '');
		$this->service->delete($request->userId(), $domain);
		return ApiResult::json(['domain' => $domain, 'deleted' => true]);
	}
}
