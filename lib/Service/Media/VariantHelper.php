<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media;

/**
 * Gemeinsame, zustandslose Helfer der Provider.
 */
final class VariantHelper {
	/**
	 * Substrings (kleingeschrieben), die eine Variante als "nicht der
	 * Standard" markieren - z. B. Gebärdensprache oder Audiodeskription.
	 * Solche Varianten sind für Sehende/Hörende meist unpraktisch als
	 * Voreinstellung (eingeblendete/r Dolmetscher:in, zusätzliche
	 * Beschreibungs-Tonspur), bleiben aber über das Dropdown wählbar.
	 */
	private const SPECIAL_VARIANT_KEYWORDS = [
		'dgs', 'gebärden', 'gebarden', 'sign',
		'audiodeskription', 'hörfilm', 'horfilm', 'audio description',
	];

	public static function looksLikeHlsUrl(string $url): bool {
		$path = (string) parse_url($url, PHP_URL_PATH);
		return str_starts_with($url, 'https://') && str_ends_with(strtolower($path), '.m3u8');
	}

	/**
	 * true, wenn $url eine https-URL ist, deren Host exakt in $allowedHosts
	 * steht.
	 *
	 * @param list<string> $allowedHosts
	 */
	public static function isHttpsUrlOnHost(string $url, array $allowedHosts): bool {
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}
		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}
		return in_array(strtolower($parts['host']), $allowedHosts, true);
	}

	/**
	 * true, wenn $url eine https-URL ist, deren Host einer der Domains in
	 * $domainSuffixes entspricht oder eine Subdomain davon ist. Eine leere
	 * Liste erlaubt jeden https-Host.
	 *
	 * @param list<string> $domainSuffixes
	 */
	public static function isHttpsUrlOnDomain(string $url, array $domainSuffixes): bool {
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}
		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}
		if ($domainSuffixes === []) {
			return true;
		}
		$host = strtolower($parts['host']);
		foreach ($domainSuffixes as $domain) {
			if ($host === $domain || str_ends_with($host, '.' . $domain)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Auslieferungsart anhand der URL: ein .m3u8-Manifest ist HLS, alles
	 * andere wird als direkte Mediendatei behandelt.
	 */
	public static function deliveryForUrl(string $url): string {
		return self::looksLikeHlsUrl($url) ? MediaResult::DELIVERY_HLS : MediaResult::DELIVERY_FILE;
	}

	/**
	 * host-allow="a.de, b.de" → ['a.de', 'b.de'].
	 *
	 * @return list<string>
	 */
	public static function parseHostList(?string $value): array {
		if ($value === null) {
			return [];
		}
		$hosts = [];
		foreach (preg_split('/[\s,]+/', strtolower($value)) ?: [] as $host) {
			$host = trim($host, " .\t\n\r");
			if ($host !== '') {
				$hosts[] = $host;
			}
		}
		return $hosts;
	}

	/**
	 * Erster nicht-leerer String aus einer Liste von Kandidatenwerten
	 * (bereits getrimmt) - für "nimm das erste vorhandene Label-Feld".
	 *
	 * @param list<mixed> $candidates
	 */
	public static function firstNonEmptyString(array $candidates): ?string {
		foreach ($candidates as $candidate) {
			if (is_string($candidate) && trim($candidate) !== '') {
				return trim($candidate);
			}
		}
		return null;
	}

	/**
	 * true, wenn $label auf eine Variante hindeutet, die als Voreinstellung
	 * unpraktisch wäre (Gebärdensprache, Audiodeskription, …) - siehe
	 * SPECIAL_VARIANT_KEYWORDS.
	 */
	public static function isSpecialVariant(string $label): bool {
		$lower = mb_strtolower($label);
		foreach (self::SPECIAL_VARIANT_KEYWORDS as $keyword) {
			if (str_contains($lower, $keyword)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Baut aus den pro Sender über dessen API gesammelten HLS-Kandidaten das
	 * finale (transiente, siehe MediaResult::isPersistable()) MediaResult: dedupliziert nach URL (erste Nennung gewinnt) und wählt
	 * als defaultIndex die erste NICHT-spezielle Variante, damit
	 * Gebärdensprache/Audiodeskription nie stillschweigend die Vorauswahl
	 * ist - bleibt aber im Dropdown wählbar.
	 *
	 * @param list<array{label: string, url: string, subtitleLanguage?: string|null}> $variants
	 */
	public static function buildHlsResult(string $kind, array $variants): ?MediaResult {
		$seenUrls = [];
		$deduped = [];
		foreach ($variants as $variant) {
			if (isset($seenUrls[$variant['url']])) {
				continue;
			}
			$seenUrls[$variant['url']] = true;
			$deduped[] = $variant;
		}
		if ($deduped === []) {
			return null;
		}

		$defaultIndex = 0;
		foreach ($deduped as $i => $variant) {
			if (!self::isSpecialVariant($variant['label'])) {
				$defaultIndex = $i;
				break;
			}
		}

		return new MediaResult($kind, MediaResult::DELIVERY_HLS, $deduped, $defaultIndex, true);
	}
}
