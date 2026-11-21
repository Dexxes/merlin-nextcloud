<?php

declare(strict_types=1);

/**
 * Testharness für Service\SupportBoxService (Abo-/Spendenlink-Infobox).
 *
 * Aufruf: php tools/test-support-box.php
 *
 * Wie test-content-filter-merge.php ohne Composer/Nextcloud: Article,
 * ContentFilterRepository, SiteCredentialService und IConfig sind Stubs, geprüft
 * wird nur die Entscheidungslogik des Service (URL-Auswahl, Login-Ausblendung,
 * Share-Sonderfall, Akzentfarbe).
 */

namespace OCP {
	interface IConfig {
		public function getUserValue($userId, $appName, $key, $default = '');
	}
}

namespace OCA\Merlin\Db {
	class Article {
		public function __construct(private string $url, private ?string $siteName, private ?string $siteIconUrl = null) {}
		public function getUrl(): string { return $this->url; }
		public function getSiteName(): ?string { return $this->siteName; }
		public function getSiteIconUrl(): ?string { return $this->siteIconUrl; }
	}
}

namespace OCA\Merlin\Service {
	class ContentFilterRepository {
		public function __construct(public ?\SimpleXMLElement $config) {}
		public function normalizeUrlDomain(string $url): string {
			return (string) preg_replace('/^www\./i', '', strtolower(parse_url($url, PHP_URL_HOST) ?? ''));
		}
		public function getMerged(string $domain, ?string $userId = null): ?\SimpleXMLElement {
			return $this->config;
		}
	}
	class SiteCredentialService {
		public function __construct(public bool $active) {}
		public function hasActiveLogin(string $userId, string $domain): bool { return $this->active; }
	}
}

namespace {
	require_once __DIR__ . '/../lib/Service/SupportBoxService.php';

	use OCA\Merlin\Db\Article;
	use OCA\Merlin\Service\ContentFilterRepository;
	use OCA\Merlin\Service\SiteCredentialService;
	use OCA\Merlin\Service\SupportBoxService;

	$failed = 0;
	$check = static function (bool $ok, string $label) use (&$failed): void {
		echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
		if (!$ok) {
			$failed++;
		}
	};

	$config = static fn (string $body): \SimpleXMLElement => new \SimpleXMLElement('<domain name="example.com">' . $body . '</domain>');
	$userConfig = new class(['accentColor' => '#00AAFF']) implements \OCP\IConfig {
		public function __construct(private array $values) {}
		public function getUserValue($userId, $appName, $key, $default = '') { return $this->values[$key] ?? $default; }
	};
	$service = static fn (?\SimpleXMLElement $cfg, bool $login, ?\OCP\IConfig $ic = null) =>
		new SupportBoxService(new ContentFilterRepository($cfg), new SiteCredentialService($login), $ic ?? $userConfig);
	$article = new Article('https://www.example.com/a/1', 'Example Times');

	$both = $config('<paywall><subscribe url="https://example.com/abo" /></paywall><metadata><donations url="https://example.com/spenden" /></metadata>');

	echo "SupportBoxService\n";

	$box = $service($both, false)->forReader($article, 'alice');
	$check($box === ['siteName' => 'Example Times', 'subscribeUrl' => 'https://example.com/abo', 'donationsUrl' => 'https://example.com/spenden', 'accentColor' => '#00AAFF', 'iconUrl' => 'https://www.example.com/favicon.ico'],
		'Reader ohne Login: beide Links, Akzentfarbe des Nutzers, Altartikel-Icon = /favicon.ico der Origin');

	$check($service($both, true)->forReader($article, 'alice') === null, 'Reader mit aktivem Login: keine Box');

	$share = $service($both, true)->forShare($article, 'alice');
	$check($share !== null && $share['subscribeUrl'] !== null && $share['donationsUrl'] !== null,
		'Share: Box auch bei aktivem Login des Erstellers, mit beiden Links');

	$onlyDonations = $service($config('<metadata><donations url="https://example.com/spenden" /></metadata>'), false)->forReader($article, 'alice');
	$check($onlyDonations !== null && $onlyDonations['subscribeUrl'] === null && $onlyDonations['donationsUrl'] !== null,
		'nur Spende: subscribeUrl = null');

	$onlySub = $service($config('<paywall><subscribe url="https://example.com/abo" /></paywall>'), false)->forReader($article, 'alice');
	$check($onlySub !== null && $onlySub['donationsUrl'] === null && $onlySub['subscribeUrl'] !== null,
		'nur Abo: donationsUrl = null');

	$check($service($config('<metadata><title xpath="//h1" /></metadata>'), false)->forReader($article, 'alice') === null,
		'weder Abo noch Spende: keine Box');
	$check($service(null, false)->forReader($article, 'alice') === null, 'ohne Domain-Config: keine Box');

	$bad = $service($config('<metadata><donations url="javascript:alert(1)" /></metadata>'), false)->forReader($article, 'alice');
	$check($bad === null, 'javascript:-URL wird verworfen (Box entfällt, wenn nichts übrig bleibt)');

	$mixed = $service($config('<paywall><subscribe url="data:text/html,x" /></paywall><metadata><donations url="https://example.com/spenden" /></metadata>'), false)->forReader($article, 'alice');
	$check($mixed !== null && $mixed['subscribeUrl'] === null && $mixed['donationsUrl'] !== null, 'ungültige Abo-URL fällt weg, gültige Spende bleibt');

	$noName = $service($both, false)->forReader(new Article('https://example.com/x', ''), 'alice');
	$check($noName !== null && $noName['siteName'] === 'example.com', 'ohne siteName: Domain als Name');

	$badAccent = new class implements \OCP\IConfig {
		public function getUserValue($userId, $appName, $key, $default = '') { return 'red; background:url(x)'; }
	};
	$fallback = $service($both, false, $badAccent)->forReader($article, 'alice');
	$check($fallback !== null && $fallback['accentColor'] === '#FF3B30', 'ungültige Akzentfarbe: Fallback auf Standard');

	$withIcon = $service($both, false)->forReader(new Article('https://www.example.com/a/1', 'Example Times', 'https://cdn.example.com/apple-touch-icon.png'), 'alice');
	$check($withIcon !== null && $withIcon['iconUrl'] === 'https://cdn.example.com/apple-touch-icon.png', 'gespeichertes Seiten-Icon wird als iconUrl ausgeliefert');

	$badIcon = $service($both, false)->forReader(new Article('http://example.com:8080/a', 'X', 'javascript:alert(1)'), 'alice');
	$check($badIcon !== null && $badIcon['iconUrl'] === 'http://example.com:8080/favicon.ico', 'unsicheres gespeichertes Icon wird verworfen, Fallback /favicon.ico (inkl. Port)');

	$dataIcon = $service($both, false)->forReader(new Article('https://example.com/a', 'X', 'data:image/svg+xml,<svg/>'), 'alice');
	$check($dataIcon !== null && $dataIcon['iconUrl'] === 'https://example.com/favicon.ico', 'data:-Icon wird verworfen');

	$shareIcon = $service($both, true)->forShare(new Article('https://example.com/a', 'X', 'https://example.com/i.png'), 'alice');
	$check($shareIcon !== null && $shareIcon['iconUrl'] === 'https://example.com/i.png', 'Share liefert das Seiten-Icon ebenfalls');

	echo $failed === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$failed Prüfung(en) fehlgeschlagen.\n";
	exit($failed === 0 ? 0 : 1);
}
