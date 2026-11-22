<?php

declare(strict_types=1);

/**
 * Testharness für Service\SiteCredentialService (Paywall-Logins je Nutzer).
 *
 * Aufruf: php core/tests/test-site-credentials.php
 *
 * Ohne Composer und ohne Nextcloud: SiteCredentialStore, Crypto und
 * ContentFilterSource sind In-Memory-Stubs. Geprüft werden Cookie-Cache,
 * Login-Status, Login-Konfiguration aus dem Bundle (echte Datei
 * content-filters/tagesspiegel.de.xml) und das Löschen. Ein echter Login
 * (HTTP) wird hier nicht ausgeführt.
 */

namespace Psr\Log {
	if (!interface_exists(LoggerInterface::class)) {
		interface LoggerInterface {
			public function emergency(string|\Stringable $message, array $context = []): void;
			public function alert(string|\Stringable $message, array $context = []): void;
			public function critical(string|\Stringable $message, array $context = []): void;
			public function error(string|\Stringable $message, array $context = []): void;
			public function warning(string|\Stringable $message, array $context = []): void;
			public function notice(string|\Stringable $message, array $context = []): void;
			public function info(string|\Stringable $message, array $context = []): void;
			public function debug(string|\Stringable $message, array $context = []): void;
			public function log($level, string|\Stringable $message, array $context = []): void;
		}
	}
}

namespace Merlin\SiteCredentialTest {
	require_once __DIR__ . '/autoload.php';

	use Merlin\Core\Domain\SiteCredential;
	use Merlin\Core\Port\ContentFilterSource;
	use Merlin\Core\Port\Crypto;
	use Merlin\Core\Port\SiteCredentialStore;
	use Psr\Log\LoggerInterface;

	final class Store implements SiteCredentialStore {
		/** @var array<string, SiteCredential> */
		public array $rows = [];
		private int $nextId = 1;
		public function find(string $userId, string $domain): ?SiteCredential { return $this->rows["$userId/$domain"] ?? null; }
		public function findAllForUser(string $userId): array {
			return array_values(array_filter($this->rows, static fn (SiteCredential $c) => $c->getUserId() === $userId));
		}
		public function save(SiteCredential $credential): void {
			if ($credential->getId() === null) {
				$credential->setId($this->nextId++);
			}
			$this->rows[$credential->getUserId() . '/' . $credential->getDomain()] = $credential;
		}
		public function deleteByUserAndDomain(string $userId, string $domain): void { unset($this->rows["$userId/$domain"]); }
	}
	final class FakeCrypto implements Crypto {
		public function encrypt(string $plaintext): string { return 'enc:' . base64_encode($plaintext); }
		public function decrypt(string $ciphertext): string {
			if (!str_starts_with($ciphertext, 'enc:')) {
				throw new \RuntimeException('HMAC does not match');
			}
			return base64_decode(substr($ciphertext, 4));
		}
	}
	final class Filters implements ContentFilterSource {
		public function normalizeUrlDomain(string $url): string { return ''; }
		public function getMerged(string $domain, ?string $userId = null): ?\SimpleXMLElement { return null; }
		public function isUnsupportedDomain(string $urlDomain): bool { return false; }
		public function getCaptionCreditPrefixes(): array { return []; }
		public function readBundle(string $domain): ?string {
			$file = dirname(__DIR__, 2) . '/content-filters/' . basename($domain) . '.xml';
			return is_file($file) ? (string) file_get_contents($file) : null;
		}
		public function listFilters(): array {
			return [
				['domain' => 'tagesspiegel.de', 'hasBundle' => true],
				['domain' => 'zeit.de', 'hasBundle' => true],
				['domain' => 'nur-custom.example', 'hasBundle' => false],
			];
		}
	}
	final class Logger implements LoggerInterface {
		/** @var list<string> */
		public array $lines = [];
		public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
		public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message, $context); }
		public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message, $context); }
		public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message, $context); }
		public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message, $context); }
		public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message, $context); }
		public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message, $context); }
		public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message, $context); }
		public function log($level, string|\Stringable $message, array $context = []): void { $this->lines[] = "$level: $message"; }
	}
}

namespace {
	use Merlin\Core\Domain\SiteCredential;
	use Merlin\Core\Extraction\Login\LoginStatus;
	use Merlin\Core\Service\SiteCredentialService;
	use Merlin\SiteCredentialTest\FakeCrypto;
	use Merlin\SiteCredentialTest\Filters;
	use Merlin\SiteCredentialTest\Logger;
	use Merlin\SiteCredentialTest\Store;

	$failed = 0;
	$check = static function (bool $ok, string $label) use (&$failed): void {
		echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
		if (!$ok) {
			$failed++;
		}
	};

	$crypto = new FakeCrypto();
	$store = new Store();
	$logger = new Logger();
	$service = new SiteCredentialService($store, new Filters(), $crypto, $logger);

	$credential = static function (string $user, string $domain, string $status, ?string $cookiesEnc, ?\DateTimeInterface $expires) use ($crypto, $store): SiteCredential {
		$c = new SiteCredential();
		$c->setUserId($user);
		$c->setDomain($domain);
		$c->setUsernameEnc($crypto->encrypt('leser@example.com'));
		$c->setPasswordEnc($crypto->encrypt('geheim'));
		$c->setSessionCookiesEnc($cookiesEnc);
		$c->setCookieExpiresAt($expires);
		$c->setLastLoginStatus($status);
		$c->setLastLoginAt(new \DateTime('2026-11-01T10:00:00+00:00'));
		$c->setCreatedAt(new \DateTime('2026-10-01'));
		$store->save($c);
		return $c;
	};
	$cookies = ['session' => 'abc', 'piano' => 'xyz'];

	echo "Login-Konfiguration\n";
	$config = $service->loadLoginConfig('tagesspiegel.de');
	$check($config !== null && $config->isValid(), 'tagesspiegel.de: gültige <login>-Sektion aus dem Bundle');
	$check($service->loadLoginConfig('zeit.de') === null, 'Domain ohne <login>: null');
	$check($service->loadLoginConfig('gibt-es-nicht.example') === null, 'Domain ohne Bundle: null');
	$check($service->listLoginCapableDomains() === ['tagesspiegel.de'], 'nur Bundle-Domains mit Login sind wählbar');

	echo "Cookie-Cache und Status\n";
	$credential('alice', 'tagesspiegel.de', LoginStatus::OK, $crypto->encrypt(json_encode($cookies)), new \DateTime('+1 day'));
	$check($service->getCachedCookies('alice', 'tagesspiegel.de') === $cookies, 'gültige Cookies werden entschlüsselt geliefert');
	$check($service->ensureValidCookies('alice', 'tagesspiegel.de', $config) === $cookies, 'ensureValidCookies nutzt den Cache ohne Login');
	$check($service->hasActiveLogin('alice', 'tagesspiegel.de') === true, 'Status ok: aktiver Login');

	$credential('bob', 'tagesspiegel.de', LoginStatus::OK, $crypto->encrypt(json_encode($cookies)), new \DateTime('-1 hour'));
	$check($service->getCachedCookies('bob', 'tagesspiegel.de') === null, 'abgelaufene Cookies: null');
	$check($service->hasActiveLogin('bob', 'tagesspiegel.de') === true, 'abgelaufene Cookies zählen weiter als aktiver Login');

	$credential('carol', 'tagesspiegel.de', LoginStatus::INVALID_CREDENTIALS, $crypto->encrypt(json_encode($cookies)), null);
	$check($service->getCachedCookies('carol', 'tagesspiegel.de') === null, 'fehlgeschlagener Login: keine Cookies');
	$check($service->hasActiveLogin('carol', 'tagesspiegel.de') === false, 'fehlgeschlagener Login: kein aktiver Login');

	$credential('dave', 'tagesspiegel.de', LoginStatus::OK, 'kaputt', null);
	$check($service->getCachedCookies('dave', 'tagesspiegel.de') === null, 'nicht entschlüsselbare Cookies: null');
	$check(in_array('error: site-credentials: gespeicherte Session-Cookies konnten nicht entschlüsselt werden', $logger->lines, true), '... und ein Fehler im Log');

	$eve = $credential('eve', 'tagesspiegel.de', LoginStatus::OK, null, null);
	$eve->setUsernameEnc('kaputt');
	$check($service->ensureValidCookies('eve', 'tagesspiegel.de', $config) === null, 'nicht entschlüsselbare Zugangsdaten: kein Login-Versuch, null');
	$check($service->ensureValidCookies('nobody', 'tagesspiegel.de', $config) === null, 'ohne Zugangsdaten: null');
	$check($service->hasActiveLogin('nobody', 'tagesspiegel.de') === false, 'ohne Zugangsdaten: kein aktiver Login');

	echo "Liste und Löschen\n";
	$credential('alice', 'morgenpost.de', LoginStatus::PENDING, null, null);
	$list = $service->listForUser('alice');
	$check($list === [
		['domain' => 'tagesspiegel.de', 'status' => 'ok', 'lastLoginAt' => '2026-11-01T10:00:00+00:00'],
		['domain' => 'morgenpost.de', 'status' => 'pending', 'lastLoginAt' => '2026-11-01T10:00:00+00:00'],
	], 'listForUser: Domain, Status, Zeitpunkt (ISO 8601)');
	$service->delete('alice', 'tagesspiegel.de');
	$check($service->hasActiveLogin('alice', 'tagesspiegel.de') === false && count($service->listForUser('alice')) === 1, 'delete entfernt nur diese Domain');

	$threw = false;
	try {
		$service->saveAndLogin('alice', 'zeit.de', 'u', 'p');
	} catch (\InvalidArgumentException $e) {
		$threw = true;
	}
	$check($threw && $store->find('alice', 'zeit.de') === null, 'saveAndLogin ohne <login>-Config: Fehler, nichts gespeichert');

	echo $failed === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$failed Prüfung(en) fehlgeschlagen.\n";
	exit($failed === 0 ? 0 : 1);
}
