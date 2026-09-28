<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media\Provider;

use OCA\Merlin\Service\Media\MediaContext;
use OCA\Merlin\Service\Media\MediaHttpClient;
use OCA\Merlin\Service\Media\MediaResult;
use OCA\Merlin\Service\Media\MediaSourceProviderInterface;
use OCA\Merlin\Service\Media\VariantHelper;

/**
 * type="arte": HLS-Stream über die Arte-Player-API (api.arte.tv/api/player/v2)
 * – siehe MediaResolverService-Docblock zur bewussten Produktentscheidung.
 */
class ArteProvider implements MediaSourceProviderInterface {
	public function __construct(
		private MediaHttpClient $http,
	) {
	}

	public function type(): string {
		return 'arte';
	}

	public function resolvesPerRequest(): bool {
		return true;
	}

	public function resolve(MediaContext $context): ?MediaResult {
		$articleUrl = $context->articleUrl;

		// Arte nutzt mehrere ID-Formate in freier Wildbahn: das klassische
		// "129847-001-A" (numerisch + Episoden-/A-F-Suffix) UND kürzere,
		// zweibuchstabig-präfixierte IDs wie "RC-027957" (z. B. Serien-
		// Kurzformate) - die Player-API akzeptiert beide gleichermaßen.
		if (preg_match('#(\d{6}-\d{3}-[AF]|[A-Z]{2}-\d+|LIVE)#', $articleUrl, $m) !== 1) {
			return null;
		}
		$videoId = $m[1];

		$lang = 'de';
		if (preg_match('#arte\.tv/([a-z]{2})/#i', $articleUrl, $langMatch) === 1) {
			$candidate = strtolower($langMatch[1]);
			if (preg_match('/^[a-z]{2}$/', $candidate) === 1) {
				$lang = $candidate;
			}
		}

		$json = $this->http->getJson(
			'https://api.arte.tv/api/player/v2/config/' . rawurlencode($lang) . '/' . rawurlencode($videoId),
			['x-validated-age: 18'],
		);
		if ($json === null || isset($json['error'])) {
			return null;
		}

		$streams = $json['data']['attributes']['streams'] ?? null;
		if (!is_array($streams)) {
			return null;
		}

		// Live verifiziert: Arte stellt dem eigentlichen Protokoll ein
		// "API_"-Präfix voran (z. B. "API_HLS_NG_MA" statt nur "HLS..."),
		// deshalb str_contains() statt str_starts_with().
		//
		// Die Sprach-/Untertitel-Kombinationen ("Originalfassung - UT
		// deutsch" etc.) sind eigene Stream-Einträge mit jeweils eigener
		// Manifest-URL. Jeder streams[]-Eintrag trägt ein .versions[]-Array
		// mit dem eigentlichen Label, während .mainQuality.label nur die
		// Bildqualität beschreibt (z. B. "720p") und deshalb NICHT zum
		// Beschriften taugt.
		$variants = [];
		foreach ($streams as $stream) {
			if (!is_array($stream)) {
				continue;
			}
			$protocol = strtoupper((string) ($stream['protocol'] ?? ''));
			$url = $stream['url'] ?? null;
			if (!str_contains($protocol, 'HLS') || !is_string($url) || !VariantHelper::looksLikeHlsUrl($url)) {
				continue;
			}
			$versions = $stream['versions'] ?? null;
			$firstVersion = is_array($versions) && is_array($versions[0] ?? null) ? $versions[0] : null;
			$label = VariantHelper::firstNonEmptyString([
				$firstVersion['label'] ?? null,
				$firstVersion['shortLabel'] ?? null,
			]) ?? ('Version ' . (count($variants) + 1));
			// Jedes Manifest bettet trotzdem mehrere Untertitel-Spuren ein
			// statt nur die zur Version passende - Browser/hls.js wählen sonst
			// per Systemsprache aus. subtitleLanguage kommt deshalb mit, damit
			// das Frontend die passende Spur nach dem Laden aktiv erzwingen
			// kann - "und" bedeutet laut Arte-API "keine Untertitel".
			$subtitleLanguage = is_string($firstVersion['subtitleLanguage'] ?? null)
				? $firstVersion['subtitleLanguage']
				: null;
			$variants[] = ['label' => $label, 'url' => $url, 'subtitleLanguage' => $subtitleLanguage];
		}

		$result = VariantHelper::buildHlsResult($context->kind(), $variants);
		if ($result === null) {
			return null;
		}

		// Kein Varianten-Dropdown für Arte: Die Tonspur ist über alle
		// Versionen hinweg identisch (immer die Originalfassung), die
		// einzige echte Auswahl ist die Untertitelsprache - und die lässt
		// sich bereits über die native CC-Schaltfläche steuern. Deshalb nur
		// die als Standard gewählte Variante zurückgeben - der Player blendet
		// das Dropdown bei variants.length <= 1 aus.
		return new MediaResult(
			$result->kind,
			$result->delivery,
			[$result->variants[$result->defaultIndex]],
			0,
			true,
		);
	}
}
