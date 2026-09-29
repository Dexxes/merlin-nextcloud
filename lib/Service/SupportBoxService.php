<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Db\Article;
use OCP\IConfig;

/**
 * Daten für die Support-Infobox ("Dir gefällt der Artikel von …? Überlege ein
 * Abo abzuschließen oder zu spenden"), die die Clients beim Rendern zwischen
 * zwei Absätze setzen.
 *
 * Warum ein Datenfeld und kein HTML im gespeicherten Content: ein eingefügter
 * Absatz würde Highlight-XPaths verschieben, vom TTS mitgelesen, in Export und
 * Share-Link mitgeliefert und wäre nach dem späteren Hinterlegen von
 * Zugangsdaten nicht mehr entfernbar.
 *
 * Quellen in der (gemergten) Domain-Config:
 *   <paywall><subscribe url="…"/></paywall>   – Abo-Seite
 *   <metadata><donations url="…"/></metadata> – Spenden-Seite
 */
class SupportBoxService {
	public const DEFAULT_ACCENT = '#FF3B30';

	public function __construct(
		private ContentFilterRepository $filters,
		private SiteCredentialService $siteCredentials,
		private IConfig $config,
	) {
	}

	/**
	 * Box für den eingeloggten Leser: entfällt, wenn er für die Seite einen
	 * aktiven Abo-Login hinterlegt hat.
	 *
	 * @return array{siteName:string,subscribeUrl:?string,donationsUrl:?string,accentColor:string}|null
	 */
	public function forReader(Article $article, string $userId): ?array {
		return $this->build($article, $userId, true);
	}

	/**
	 * Box für die öffentliche Share-Ansicht: immer, unabhängig vom Login des
	 * Erstellers; Akzentfarbe des Erstellers ($ownerUserId).
	 *
	 * @return array{siteName:string,subscribeUrl:?string,donationsUrl:?string,accentColor:string}|null
	 */
	public function forShare(Article $article, string $ownerUserId): ?array {
		return $this->build($article, $ownerUserId, false);
	}

	/**
	 * @return array{siteName:string,subscribeUrl:?string,donationsUrl:?string,accentColor:string}|null
	 */
	private function build(Article $article, string $userId, bool $hideWithLogin): ?array {
		$url    = (string) $article->getUrl();
		$domain = $this->filters->normalizeUrlDomain($url);
		if ($domain === '') {
			return null;
		}

		$config = $this->filters->getMerged($domain, $userId);
		if ($config === null) {
			return null;
		}

		$subscribeUrl = $this->firstUrl($config->xpath('paywall/subscribe') ?: []);
		$donationsUrl = $this->firstUrl($config->xpath('metadata/donations') ?: []);
		if ($subscribeUrl === null && $donationsUrl === null) {
			return null;
		}

		if ($hideWithLogin && $this->siteCredentials->hasActiveLogin($userId, $domain)) {
			return null;
		}

		$siteName = trim((string) $article->getSiteName());

		return [
			'siteName'     => $siteName !== '' ? $siteName : $domain,
			'subscribeUrl' => $subscribeUrl,
			'donationsUrl' => $donationsUrl,
			'accentColor'  => $this->accentColor($userId),
		];
	}

	/**
	 * Erste gültige absolute http(s)-URL aus den url-Attributen. Der Validator
	 * prüft das schon beim Speichern; hier nochmal, weil die URL in ein
	 * href-Attribut der Clients wandert (kein javascript:/data:).
	 *
	 * @param iterable<\SimpleXMLElement> $rules
	 */
	private function firstUrl(iterable $rules): ?string {
		foreach ($rules as $rule) {
			$candidate = trim((string) ($rule['url'] ?? ''));
			if ($candidate === '') {
				continue;
			}
			$scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
			if (($scheme === 'http' || $scheme === 'https') && filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
				return $candidate;
			}
		}
		return null;
	}

	private function accentColor(string $userId): string {
		$value = $this->config->getUserValue($userId, 'reader', 'accentColor', self::DEFAULT_ACCENT);
		return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : self::DEFAULT_ACCENT;
	}
}
