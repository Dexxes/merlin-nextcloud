<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media\Provider;

use OCA\Merlin\Service\Media\DescriptionProviderInterface;
use OCA\Merlin\Service\Media\MediaContext;
use OCA\Merlin\Service\Media\MediaHttpClient;
use OCA\Merlin\Service\Media\MediaResult;
use OCA\Merlin\Service\Media\MediaSourceProviderInterface;
use OCA\Merlin\Service\Media\VariantHelper;

/**
 * type="zdf": HLS-Stream über ZDFs interne GraphQL-/PTMD-API – siehe
 * MediaResolverService-Docblock zur bewussten Produktentscheidung.
 *
 * Liefert zusätzlich Teaser/Detailbeschreibung aus dem Next.js-RSC-Payload
 * (DescriptionProviderInterface), weil zdf.de sie nicht als <meta>-Tag
 * ausliefert.
 */
class ZdfProvider implements MediaSourceProviderInterface, DescriptionProviderInterface {
	public function __construct(
		private MediaHttpClient $http,
	) {
	}

	public function type(): string {
		return 'zdf';
	}

	public function resolvesPerRequest(): bool {
		return true;
	}

	/**
	 * ZDF braucht ein kurzlebiges Bootstrap-Token (Api-Auth-Header) für den
	 * eigentlichen GraphQL-/PTMD-Aufruf. Wird bewusst pro Request frisch
	 * geholt statt gecacht: PHP läuft hier stateless pro Request, ein
	 * Datei-/DB-Cache für ein einzelnes zusätzliches, kurzes HTTP-Roundtrip
	 * wäre mehr Komplexität als der Aufruf selbst kostet.
	 *
	 * Anhand des öffentlichen ZDF-Extractors in yt-dlp
	 * (yt_dlp/extractor/zdf.py) verifiziert: Root-Feld ist videoByCanonical()
	 * mit Variable $canonical, ptmdTemplate hängt direkt unter
	 * currentMedia.nodes, und der Wert ist ein SERVER-RELATIVER Pfad
	 * ("/tmd/2/{playerId}/...") - erst nach dem Voranstellen von
	 * "https://api.zdf.de" ist er eine gültige URL. Trotzdem defensiv: jede
	 * Abweichung von der erwarteten Struktur → null statt Exception.
	 */
	public function resolve(MediaContext $context): ?MediaResult {
		$id = $this->extractZdfId($context->articleUrl);
		if ($id === null) {
			return null;
		}

		$tokenResponse = $this->http->getJson('https://zdf-prod-futura.zdf.de/mediathekV2/token');
		$tokenType  = $tokenResponse['type'] ?? null;
		$tokenValue = $tokenResponse['token'] ?? null;
		if (!is_string($tokenType) || !is_string($tokenValue) || $tokenValue === '') {
			return null;
		}
		$authHeader = trim($tokenType . ' ' . $tokenValue);

		// label/vodMediaType (z. B. "Normal"/"DEFAULT" vs. "DGS"/"DGS" für
		// Deutsche Gebärdensprache) leben nur auf VodMedia - LiveMedia hat
		// diese Felder nicht, deshalb der Inline-Fragment statt sie direkt
		// auf dem Interface abzufragen.
		$graphqlQuery = <<<'GRAPHQL'
			query VideoByCanonical($canonical: String!) {
				videoByCanonical(canonical: $canonical) {
					canonical
					currentMedia {
						nodes {
							ptmdTemplate
							... on VodMedia {
								label
								vodMediaType
							}
						}
					}
				}
			}
			GRAPHQL;

		$graphqlResponse = $this->http->postJson(
			'https://api.zdf.de/graphql',
			[
				'operationName' => 'VideoByCanonical',
				'query'         => $graphqlQuery,
				'variables'     => ['canonical' => $id],
			],
			['Api-Auth: ' . $authHeader],
		);
		if ($graphqlResponse === null) {
			return null;
		}

		// currentMedia.nodes enthält typischerweise 1-3 Einträge - eine
		// Standardvariante ("DEFAULT") und optional weitere wie "DGS"
		// (Gebärdensprache) oder eine Hörfilm-/Audiodeskriptionsfassung.
		// Für jede wird separat die PTMD abgefragt, damit alle als Variante
		// im Frontend-Dropdown zur Auswahl stehen.
		$nodes = $graphqlResponse['data']['videoByCanonical']['currentMedia']['nodes'] ?? null;
		if (!is_array($nodes)) {
			return null;
		}

		$variants = [];
		foreach ($nodes as $node) {
			if (!is_array($node)) {
				continue;
			}
			$ptmdTemplate = $node['ptmdTemplate'] ?? null;
			// Nur ein Pfad-Präfix mit erwartetem Schema erlauben, bevor die
			// feste api.zdf.de-Basis vorangestellt wird - verhindert, dass
			// eine unerwartete absolute URL im Template (z. B. durch eine
			// künftige API-Änderung) versehentlich auf einen fremden Host
			// zeigen könnte.
			if (!is_string($ptmdTemplate) || !str_starts_with($ptmdTemplate, '/')) {
				continue;
			}
			$ptmdUrl = 'https://api.zdf.de' . str_replace('{playerId}', 'android_native_6', $ptmdTemplate);
			if (!VariantHelper::isHttpsUrlOnHost($ptmdUrl, ['api.zdf.de'])) {
				continue;
			}

			$ptmd = $this->http->getJson($ptmdUrl, ['Api-Auth: ' . $authHeader]);
			if ($ptmd === null) {
				continue;
			}
			$url = self::findFirstHlsUrlInPtmd($ptmd);
			if ($url === null) {
				continue;
			}

			$label = VariantHelper::firstNonEmptyString([$node['label'] ?? null, $node['vodMediaType'] ?? null]) ?? 'Standard';
			$variants[] = ['label' => $label, 'url' => $url];
		}

		return VariantHelper::buildHlsResult($context->kind(), $variants);
	}

	/**
	 * Liest Teaser und Detailbeschreibung einer zdf.de-Videoseite aus dem
	 * rohen HTML. Beides steht dort NICHT als <meta>-Tag (og:description ist
	 * ein generischer SEO-Text, weder Teaser noch Detailbeschreibung),
	 * sondern eingebettet im Next.js-RSC-Payload (self.__next_f.push(…)) -
	 * einem vollständigen JSON-Dokument, das als JS-String-Literal
	 * eingebettet ist und daher einfach escaped ist (jedes strukturelle
	 * Anführungszeichen als \", jedes literale Anführungszeichen INNERHALB
	 * eines Textwerts als \\\", weil dort schon die JSON-eigene Escaping-Regel
	 * für Textwerte griff, bevor die JS-String-Einbettung nochmal escapte).
	 * Der Payload ist selbst kein eigenständiges, isoliert parsbares JSON
	 * (durchsetzt mit RSC-Steuersyntax wie "$b6:props:…"-Referenzen), ein
	 * echter json_decode() scheitert deshalb - Extraktion daher per Regex auf
	 * bekannte Nachbar-Feldnamen.
	 *
	 * Muss auf dem UNVERÄNDERTEN HTML laufen, bevor der Extractor die
	 * <script>-Tags entfernt - danach ist der Payload weg.
	 *
	 * @return array{teaser: ?string, paragraphs: list<string>}
	 */
	public function extractDescription(string $rawHtml): array {
		$teaser = null;
		if (preg_match(
			'/\\\\"teaser\\\\":\{\\\\"title\\\\":\\\\"(?:[^"\\\\]|\\\\.)*?\\\\",\\\\"description\\\\":\\\\"(.*?)\\\\",\\\\"imageWithoutLogo\\\\"/s',
			$rawHtml,
			$m
		)) {
			$decoded = trim($this->unescapeFlightString($m[1]));
			$teaser  = $decoded !== '' ? $decoded : null;
		}

		$paragraphs = [];
		if (preg_match(
			'/\\\\"longInfoText\\\\":\{\\\\"items\\\\":\[(.*?)\\\\"editorialDate\\\\"/s',
			$rawHtml,
			$blockMatch
		)) {
			if (preg_match_all('/\\\\"text\\\\":\\\\"(.*?)\\\\",\\\\"style\\\\"/s', $blockMatch[1], $textMatches)) {
				foreach ($textMatches[1] as $rawText) {
					$text = trim($this->unescapeFlightString($rawText));
					if ($text !== '') {
						$paragraphs[] = $text;
					}
				}
			}
		}

		return ['teaser' => $teaser, 'paragraphs' => $paragraphs];
	}

	/**
	 * Löst die doppelte Escaping-Ebene aus extractDescription() auf: das
	 * erfasste Fragment ist ein Wert aus einem JSON-Dokument, das seinerseits
	 * als JS-String-Literal escaped wurde - zwei Escaping-Ebenen, zwei
	 * Unescape-Durchläufe. Bewusst kein stripcslashes(): das kennt \uXXXX
	 * nicht und würde solche Sequenzen falsch behandeln; die hier
	 * vorkommenden Escapes sind auf \\, \", \n, \t, \r, \/ beschränkt,
	 * alles andere bleibt unverändert stehen.
	 */
	private function unescapeFlightString(string $s): string {
		$unescapeOnce = static function (string $s): string {
			return preg_replace_callback('/\\\\(.)/', static function (array $m): string {
				return match ($m[1]) {
					'n'     => "\n",
					't'     => "\t",
					'r'     => "\r",
					'"'     => '"',
					'\\'    => '\\',
					'/'     => '/',
					default => $m[1],
				};
			}, $s) ?? $s;
		};
		return $unescapeOnce($unescapeOnce($s));
	}

	/**
	 * Durchsucht eine ZDF-PTMD-Antwort nach der ersten m3u8-URL
	 * (priorityList[].formitaeten[].qualities[].audio.tracks[].uri). 3sat
	 * liefert dasselbe Format (DreiSatProvider).
	 */
	public static function findFirstHlsUrlInPtmd(array $ptmd): ?string {
		$priorityList = $ptmd['priorityList'] ?? null;
		if (!is_array($priorityList)) {
			return null;
		}
		foreach ($priorityList as $priority) {
			$formitaeten = $priority['formitaeten'] ?? null;
			if (!is_array($formitaeten)) {
				continue;
			}
			foreach ($formitaeten as $formitaet) {
				$qualities = $formitaet['qualities'] ?? null;
				if (!is_array($qualities)) {
					continue;
				}
				foreach ($qualities as $quality) {
					$tracks = $quality['audio']['tracks'] ?? null;
					if (!is_array($tracks)) {
						continue;
					}
					foreach ($tracks as $track) {
						$uri = $track['uri'] ?? null;
						if (is_string($uri) && VariantHelper::looksLikeHlsUrl($uri)) {
							return $uri;
						}
					}
				}
			}
		}
		return null;
	}

	private function extractZdfId(string $articleUrl): ?string {
		$path = (string) parse_url($articleUrl, PHP_URL_PATH);
		if (preg_match('#/(?:video|play)/(?:[^/]+/)*([^/]+?)(?:\.html)?/?$#', $path, $m) === 1
			&& preg_match('/^[A-Za-z0-9_-]{3,120}$/', $m[1]) === 1) {
			return $m[1];
		}
		if (preg_match('#/([^/]+)\.html$#', $path, $m) === 1
			&& preg_match('/^[A-Za-z0-9_-]{3,120}$/', $m[1]) === 1) {
			return $m[1];
		}

		// Sammelseiten wie "/kurzfassungen/<slug>-100" referenzieren das
		// tatsächlich gemeinte Video nicht über den Pfad, sondern über einen
		// URL-Fragment-Anker "#focus=<video-slug>-100" (per Klick auf einen
		// einzelnen Clip innerhalb der Seite gesetzt). Fragmente werden vom
		// Browser nie an den Server geschickt, stehen aber in der beim
		// Speichern erfassten article.url, also hier zusätzlich auswerten.
		$fragment = (string) parse_url($articleUrl, PHP_URL_FRAGMENT);
		if (preg_match('/^focus=([A-Za-z0-9_-]{3,120})$/', $fragment, $m) === 1) {
			return $m[1];
		}

		return null;
	}
}
