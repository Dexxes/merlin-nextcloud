<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Login;

use OCA\Merlin\Db\SiteCredential;
use Psr\Log\LoggerInterface;

/**
 * Login-Provider für Zeitungsportale, die über einen Keycloak-Identity-
 * Provider mit OpenID-Connect-Implicit-Flow und response_mode=form_post
 * einloggen (type="keycloak-form-post" in der <login>-Sektion, siehe
 * content-filters/morgenpost.de.xml - Funke-Titel wie die Berliner
 * Morgenpost laufen alle über login.funkemedien.de).
 *
 * Ablauf (per HAR-Aufzeichnung des Morgenpost-Logins reverse-engineered):
 *
 *   1. GET  {authorize-endpoint}?client_id&response_type=id_token token
 *           &response_mode=form_post&nonce&redirect_uri
 *        – Keycloak liefert die Login-Seite (<form id="kc-form-login">) und
 *          per Set-Cookie seine Auth-Session (AUTH_SESSION_ID,
 *          KC_AUTH_SESSION_HASH, KC_RESTART). Die action-URL des Formulars
 *          trägt Einmal-Parameter (session_code, execution, tab_id) und wird
 *          deshalb jedes Mal aus dem HTML gelesen.
 *   2. POST {form action} (x-www-form-urlencoded: username, password,
 *           rememberMe, credentialId)
 *        – Erfolg: HTTP 200 mit einer selbst-absendenden "OIDC Form_Post
 *          Response"-Seite, deren Formular access_token/id_token/… an die
 *          redirect_uri schickt.
 *        – Falsche Zugangsdaten: ebenfalls HTTP 200, aber wieder die
 *          Login-Seite (kc-form-login) mit Fehlermeldung - erkannt wird das
 *          am Formular, nicht am Status.
 *   3. POST {redirect-endpoint} mit genau den Feldern aus Schritt 2
 *        – die Zeitung prüft die Tokens und setzt ihre eigenen Session-Cookies
 *          (bei Morgenpost sfautht = eigenes JWT mit den Abo-Rechten,
 *          sfauthp = id_token), Antwort ist ein 302 zurück zum Artikel.
 *
 * Ablauf der Cookies: Max-Age ist bei Morgenpost ~1 Jahr, die JWTs darin
 * laufen aber nach 24 Stunden ab. LoginResult::$expiresAt richtet sich daher
 * nach dem frühesten exp-Claim eines persistierten JWT-Cookies, sonst würde
 * SiteCredentialService ein Jahr lang abgelaufene Tokens mitschicken, statt
 * neu einzuloggen.
 *
 * Sicherheit: Die Formular-Ziele aus den Antworten werden nicht blind
 * übernommen. Das Login-Formular muss auf dem Host des
 * authorize-endpoints liegen, das form_post-Ziel muss exakt der
 * konfigurierte redirect-endpoint sein - sonst könnte eine manipulierte
 * Antwort das Passwort bzw. die Tokens an einen fremden Host lenken.
 *
 * WARTUNG: Ändert der Anbieter Feldnamen im Login-Formular oder schaltet
 * Keycloak eine Zwischenseite vor (AGB bestätigen, Passwort ändern), bricht
 * der Login mit STATUS_LOGIN_FLOW_BROKEN ab. Erster Blick dann: frische
 * HAR-Aufzeichnung eines Logins im Browser, Diff gegen submitLoginForm().
 */
class KeycloakFormPostLoginProvider implements LoginProviderInterface {
	use LoginHttpClient;

	private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Merlin-Content-Extractor';
	private const TIMEOUT_SECONDS = 15;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function login(string $username, string $password, LoginConfig $config): LoginResult {
		$authorizeUrl = $this->buildAuthorizeUrl($config);

		// Schritt 1: Login-Seite + Keycloak-Auth-Session.
		[$status, $headers, $body] = $this->safeRequest('GET', $authorizeUrl, ['Accept: text/html'], null);
		if ($status !== 200) {
			throw new LoginFailedException(
				'Login-Seite lieferte HTTP ' . $status . ' - Login-Ablauf hat sich vermutlich geändert',
				SiteCredential::STATUS_LOGIN_FLOW_BROKEN
			);
		}
		$keycloakCookies = $this->cookieValues($this->parseSetCookies($headers));

		$loginForm = $this->findForm($body, '//form[@id="kc-form-login"]');
		if ($loginForm === null || !$this->sameOrigin($loginForm['action'], $config->authorizeUrl)) {
			throw new LoginFailedException(
				'Kein (gültiges) Keycloak-Login-Formular gefunden - Login-Ablauf hat sich vermutlich geändert',
				SiteCredential::STATUS_LOGIN_FLOW_BROKEN
			);
		}

		// Schritt 2: Zugangsdaten an Keycloak.
		$tokenForm = $this->submitLoginForm($loginForm['action'], $authorizeUrl, $keycloakCookies, $username, $password);
		if ($tokenForm['action'] !== $config->redirectUrl) {
			throw new LoginFailedException(
				'Keycloak schickt die Tokens an ein anderes Ziel als den konfigurierten redirect-endpoint - Login-Ablauf hat sich vermutlich geändert',
				SiteCredential::STATUS_LOGIN_FLOW_BROKEN
			);
		}

		// Schritt 3: Tokens an die Zeitung, die daraus ihre Session-Cookies macht.
		$responseCookies = $this->postTokensToRedirect($config, $tokenForm['fields']);

		$cookies   = [];
		$expiresAt = null;
		foreach ($config->persistCookieNames as $name) {
			if (!isset($responseCookies[$name])) {
				continue;
			}
			$cookies[$name] = $responseCookies[$name]['value'];
			foreach ([$responseCookies[$name]['expiresAt'], $this->jwtExpiry($responseCookies[$name]['value'])] as $candidate) {
				if ($candidate !== null && ($expiresAt === null || $candidate < $expiresAt)) {
					$expiresAt = $candidate;
				}
			}
		}

		if ($cookies === []) {
			throw new LoginFailedException(
				'Redirect-Endpoint setzte keinen der erwarteten Session-Cookies - Login-Ablauf hat sich vermutlich geändert',
				SiteCredential::STATUS_LOGIN_FLOW_BROKEN
			);
		}

		return new LoginResult($cookies, $expiresAt);
	}

	private function buildAuthorizeUrl(LoginConfig $config): string {
		$query = http_build_query([
			'client_id'     => $config->clientId,
			'scope'         => 'openid profile email',
			'response_type' => 'id_token token',
			'response_mode' => 'form_post',
			'nonce'         => $this->uuid4(),
			'redirect_uri'  => $config->redirectUrl,
		], '', '&', PHP_QUERY_RFC3986);

		return $config->authorizeUrl . (str_contains($config->authorizeUrl, '?') ? '&' : '?') . $query;
	}

	/**
	 * Schritt 2. Liefert das form_post-Formular, das Keycloak nach
	 * erfolgreichem Login an den Browser zurückgibt.
	 *
	 * @param array<string,string> $keycloakCookies
	 * @return array{action:string,fields:array<string,string>}
	 */
	private function submitLoginForm(string $action, string $referer, array $keycloakCookies, string $username, string $password): array {
		$headers = [
			'Content-Type: application/x-www-form-urlencoded',
			'Accept: text/html',
			'Origin: ' . $this->originOf($action),
			'Referer: ' . $referer,
		];
		if ($keycloakCookies !== []) {
			$headers[] = 'Cookie: ' . $this->cookieHeader($keycloakCookies);
		}

		$body = http_build_query([
			'username'     => $username,
			'password'     => $password,
			'rememberMe'   => 'on',
			'credentialId' => '',
		], '', '&');

		[$status, , $responseBody] = $this->safeRequest('POST', $action, $headers, $body);

		$tokenForm = $status === 200 ? $this->findForm($responseBody, '//form[.//input[@name="access_token"]]') : null;
		if ($tokenForm !== null) {
			return $tokenForm;
		}

		if ($this->findForm($responseBody, '//form[@id="kc-form-login"]') !== null) {
			throw new LoginFailedException(
				'Login abgelehnt (Keycloak zeigt erneut das Login-Formular) - Zugangsdaten vermutlich falsch',
				SiteCredential::STATUS_INVALID_CREDENTIALS
			);
		}

		throw new LoginFailedException(
			'Keycloak lieferte nach dem Login weder Tokens noch das Login-Formular (HTTP ' . $status . ') - Login-Ablauf hat sich vermutlich geändert',
			SiteCredential::STATUS_LOGIN_FLOW_BROKEN
		);
	}

	/**
	 * Schritt 3. Das Ziel ist IMMER der konfigurierte redirect-endpoint; die
	 * action aus Keycloaks Antwort wird nur dagegen geprüft.
	 *
	 * @param array<string,string> $fields
	 * @return array<string,array{value:string,expiresAt:?\DateTime}>
	 */
	private function postTokensToRedirect(LoginConfig $config, array $fields): array {
		$headers = [
			'Content-Type: application/x-www-form-urlencoded',
			'Accept: text/html',
			// Keycloak sendet Referrer-Policy: no-referrer, der Browser schickt
			// den form_post daher mit "Origin: null" - hier genauso.
			'Origin: null',
		];
		if ($config->redirectCookies !== []) {
			$headers[] = 'Cookie: ' . $this->cookieHeader($config->redirectCookies);
		}

		[$status, $responseHeaders] = $this->safeRequest('POST', $config->redirectUrl, $headers, http_build_query($fields, '', '&'));

		if ($status < 200 || $status >= 400) {
			throw new LoginFailedException(
				'Redirect-Endpoint lieferte HTTP ' . $status . ' - Login-Ablauf hat sich vermutlich geändert',
				SiteCredential::STATUS_LOGIN_FLOW_BROKEN
			);
		}

		return $this->parseSetCookies($responseHeaders);
	}

	/**
	 * Erstes Formular im HTML, das $formXpath trifft, mit absoluter action
	 * und allen benannten <input>-Werten.
	 *
	 * @return array{action:string,fields:array<string,string>}|null
	 */
	private function findForm(string $html, string $formXpath): ?array {
		if (trim($html) === '') {
			return null;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath = new \DOMXPath($dom);
		$form  = $xpath->query($formXpath)->item(0);
		if (!$form instanceof \DOMElement) {
			return null;
		}

		$action = trim($form->getAttribute('action'));
		if (filter_var($action, FILTER_VALIDATE_URL) === false) {
			return null;
		}

		$fields = [];
		foreach ($xpath->query('.//input[@name]', $form) as $input) {
			/** @var \DOMElement $input */
			$type = strtolower($input->getAttribute('type'));
			if ($type === 'submit' || $type === 'button') {
				continue;
			}
			$fields[$input->getAttribute('name')] = $input->getAttribute('value');
		}

		return ['action' => $action, 'fields' => $fields];
	}

	private function sameOrigin(string $url, string $reference): bool {
		return strtolower($this->originOf($url)) === strtolower($this->originOf($reference))
			&& strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
	}

	/**
	 * exp-Claim eines JWT-förmigen Cookie-Werts, sonst null. Die Signatur wird
	 * nicht geprüft - es geht nur darum, wann neu eingeloggt werden muss.
	 */
	private function jwtExpiry(string $value): ?\DateTime {
		$parts = explode('.', rawurldecode($value));
		if (count($parts) !== 3) {
			return null;
		}

		$payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
		if ($payload === false) {
			return null;
		}

		try {
			$claims = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return null;
		}

		if (!is_array($claims) || !is_int($claims['exp'] ?? null)) {
			return null;
		}

		return (new \DateTime())->setTimestamp($claims['exp']);
	}

	/**
	 * @param array<string,array{value:string,expiresAt:?\DateTime}> $cookies
	 * @return array<string,string>
	 */
	private function cookieValues(array $cookies): array {
		return array_map(static fn (array $cookie): string => $cookie['value'], $cookies);
	}

	/**
	 * @param array<string,string> $cookies
	 */
	private function cookieHeader(array $cookies): string {
		return implode('; ', array_map(
			static fn (string $name, string $value): string => $name . '=' . $value,
			array_keys($cookies),
			array_values($cookies)
		));
	}

	private function uuid4(): string {
		$bytes    = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}
}
