<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Db\SiteCredential;
use OCA\Merlin\Db\SiteCredentialMapper;
use OCA\Merlin\Service\Login\LoginConfig;
use OCA\Merlin\Service\Login\LoginFailedException;
use OCA\Merlin\Service\Login\LoginProviderInterface;
use OCA\Merlin\Service\Login\PianoJsonFormLoginProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Verwaltet Paywall-Abo-Zugangsdaten (z. B. Tagesspiegel Plus) je Nutzer und
 * Domain: verschlüsselte Ablage, Login-Ausführung über den passenden
 * LoginProviderInterface, automatische Erneuerung bei abgelaufenem/fehlendem
 * Session-Cookie. Konsument ist ContentExtractorService (Cookie-Injektion
 * beim Artikel-Fetch) und ein noch zu bauender Controller für die
 * Personal-Settings-UI.
 *
 * Verschlüsselung über OCP\Security\ICrypto (Instanz-Secret-basiert, wie an
 * anderen Stellen im Nextcloud-Kern) statt eines eigenen Krypto-Schemas -
 * merlin-server hat dafür mangels ICrypto eine eigene CredentialCipher-Klasse
 * (sodium_crypto_secretbox).
 */
class SiteCredentialService {
	/** type-Attribut der <login>-Sektion => zuständiger Provider. */
	private const PROVIDERS = [
		'piano-json-form' => PianoJsonFormLoginProvider::class,
	];

	public function __construct(
		private SiteCredentialMapper $mapper,
		private ContentFilterRepository $filterRepository,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * <login>-Konfiguration der Bundle-Domain-Config, oder null wenn die
	 * Domain keine Paywall-Login-Unterstützung hat. BUNDLE-ONLY (siehe
	 * LoginConfig-Docblock) - liest bewusst readBundle() statt getMerged(),
	 * damit weder Admin- noch User-Custom-Filter einen Login-Endpoint
	 * definieren können.
	 */
	public function loadLoginConfig(string $domain): ?LoginConfig {
		$raw = $this->filterRepository->readBundle($domain);
		if ($raw === null) {
			return null;
		}

		try {
			$xml = new \SimpleXMLElement($raw, LIBXML_NONET | LIBXML_NOENT);
		} catch (\Throwable $e) {
			return null;
		}

		if (!isset($xml->login)) {
			return null;
		}

		$config = LoginConfig::fromXml($xml->login);
		return $config->isValid() ? $config : null;
	}

	/**
	 * Gültige, gecachte Session-Cookies für $userId/$domain, oder null wenn
	 * es keine gibt (nie hinterlegt, abgelaufen, oder letzter Login-Versuch
	 * fehlgeschlagen). Löst KEINEN Login aus - das übernimmt
	 * ensureValidCookies().
	 *
	 * @return array<string,string>|null
	 */
	public function getCachedCookies(string $userId, string $domain): ?array {
		$credential = $this->find($userId, $domain);
		if ($credential === null || $credential->getSessionCookiesEnc() === null) {
			return null;
		}
		if ($credential->getLastLoginStatus() !== SiteCredential::STATUS_OK) {
			return null;
		}
		$expiresAt = $credential->getCookieExpiresAt();
		if ($expiresAt !== null && $expiresAt < new \DateTime()) {
			return null;
		}

		try {
			$decrypted = $this->crypto->decrypt($credential->getSessionCookiesEnc());
			return json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
		} catch (\Throwable $e) {
			$this->logger->error('site-credentials: gespeicherte Session-Cookies konnten nicht entschlüsselt werden', [
				'domain'    => $domain,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Cookies aus dem Cache, oder - falls abgelaufen/fehlend und
	 * Zugangsdaten hinterlegt sind - ein frischer Login-Versuch. Wird pro
	 * Artikel-Fetch höchstens einmal aufgerufen (ContentExtractorService
	 * cached selbst nicht erneut), damit ein einzelner Extraktions-Request
	 * nie mehrfach gegen die Paywall-Seite einloggt.
	 *
	 * @return array<string,string>|null null, wenn keine (nutzbaren)
	 *         Zugangsdaten hinterlegt sind ODER der Login-Versuch fehlschlug
	 *         (Grund steht dann in last_login_status, siehe find()).
	 */
	public function ensureValidCookies(string $userId, string $domain, LoginConfig $config): ?array {
		$cached = $this->getCachedCookies($userId, $domain);
		if ($cached !== null) {
			return $cached;
		}

		$credential = $this->find($userId, $domain);
		if ($credential === null) {
			return null;
		}

		try {
			$username = $this->crypto->decrypt($credential->getUsernameEnc());
			$password = $this->crypto->decrypt($credential->getPasswordEnc());
		} catch (\Throwable $e) {
			$this->logger->error('site-credentials: Zugangsdaten konnten nicht entschlüsselt werden', [
				'domain'    => $domain,
				'exception' => $e,
			]);
			return null;
		}

		try {
			$result = $this->login($username, $password, $config);
		} catch (LoginFailedException $e) {
			$credential->setLastLoginStatus($e->reason);
			$credential->setLastLoginAt(new \DateTime());
			$this->mapper->update($credential);
			$this->logger->warning('site-credentials: Login fehlgeschlagen', [
				'domain' => $domain,
				'reason' => $e->reason,
			]);
			return null;
		}

		$credential->setSessionCookiesEnc($this->crypto->encrypt(json_encode($result->cookies, JSON_THROW_ON_ERROR)));
		$credential->setCookieExpiresAt($result->expiresAt);
		$credential->setLastLoginStatus(SiteCredential::STATUS_OK);
		$credential->setLastLoginAt(new \DateTime());
		$this->mapper->update($credential);

		return $result->cookies;
	}

	/**
	 * Legt Zugangsdaten für $userId/$domain an oder überschreibt sie, und
	 * führt sofort einen Login-Versuch aus (für die "Testen"-Aktion in der
	 * Personal-Settings-UI). $userId MUSS serverseitig aus der Session
	 * stammen, analog ContentFilterRepository::saveUserCustom().
	 *
	 * @throws \InvalidArgumentException wenn die Domain keine <login>-Config hat
	 * @throws LoginFailedException wenn der Login-Versuch fehlschlägt
	 */
	public function saveAndLogin(string $userId, string $domain, string $username, string $password): void {
		$config = $this->loadLoginConfig($domain);
		if ($config === null) {
			throw new \InvalidArgumentException('Domain unterstützt keinen Paywall-Login: ' . $domain);
		}

		$credential = $this->find($userId, $domain) ?? new SiteCredential();
		$credential->setUserId($userId);
		$credential->setDomain($domain);
		$credential->setUsernameEnc($this->crypto->encrypt($username));
		$credential->setPasswordEnc($this->crypto->encrypt($password));
		if ($credential->getId() === null) {
			$credential->setCreatedAt(new \DateTime());
			$credential->setLastLoginStatus(SiteCredential::STATUS_PENDING);
		}

		try {
			$result = $this->login($username, $password, $config);
			$credential->setSessionCookiesEnc($this->crypto->encrypt(json_encode($result->cookies, JSON_THROW_ON_ERROR)));
			$credential->setCookieExpiresAt($result->expiresAt);
			$credential->setLastLoginStatus(SiteCredential::STATUS_OK);
			$credential->setLastLoginAt(new \DateTime());
			$this->persist($credential);
		} catch (LoginFailedException $e) {
			$credential->setSessionCookiesEnc(null);
			$credential->setCookieExpiresAt(null);
			$credential->setLastLoginStatus($e->reason);
			$credential->setLastLoginAt(new \DateTime());
			$this->persist($credential);
			throw $e;
		}
	}

	public function delete(string $userId, string $domain): void {
		$this->mapper->deleteByUserAndDomain($userId, $domain);
	}

	/**
	 * @return list<array{domain:string,status:string,lastLoginAt:?string}>
	 */
	public function listForUser(string $userId): array {
		return array_map(
			static fn (SiteCredential $c): array => [
				'domain'      => $c->getDomain(),
				'status'      => $c->getLastLoginStatus(),
				'lastLoginAt' => $c->getLastLoginAt()?->format('c'),
			],
			$this->mapper->findAllForUser($userId)
		);
	}

	private function login(string $username, string $password, LoginConfig $config): \OCA\Merlin\Service\Login\LoginResult {
		$providerClass = self::PROVIDERS[$config->type] ?? null;
		if ($providerClass === null) {
			throw new LoginFailedException('Unbekannter Login-Typ: ' . $config->type, SiteCredential::STATUS_LOGIN_FLOW_BROKEN);
		}

		/** @var LoginProviderInterface $provider */
		$provider = new $providerClass($this->logger);
		return $provider->login($username, $password, $config);
	}

	private function find(string $userId, string $domain): ?SiteCredential {
		try {
			return $this->mapper->find($userId, $domain);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	private function persist(SiteCredential $credential): void {
		if ($credential->getId() === null) {
			$this->mapper->insert($credential);
		} else {
			$this->mapper->update($credential);
		}
	}
}
