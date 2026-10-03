<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media\Provider;

use OCA\Merlin\Service\Media\MediaContext;
use OCA\Merlin\Service\Media\MediaHttpClient;
use OCA\Merlin\Service\Media\MediaResult;
use OCA\Merlin\Service\Media\MediaSourceProviderInterface;
use OCA\Merlin\Service\Media\VariantHelper;

/**
 * type="3sat": HLS-Stream über die ZDF-Player-API von 3sat (api.3sat.de) –
 * siehe MediaResolverService-Docblock zur bewussten Produktentscheidung.
 *
 * Gleicher Weg wie der Player auf der 3sat-Seite selbst: die Artikelseite
 * trägt im Attribut data-zdfplayer-jsb ein JSON mit der Content-URL und
 * einem Api-Token. Die Content-Antwort verweist auf ein PTMD-Template, die
 * PTMD-Antwort hat dasselbe Format wie bei zdf.de
 * (ZdfProvider::findFirstHlsUrlInPtmd()).
 *
 * Die Seite wird bei jedem Öffnen neu geladen, weil das Token nicht in der
 * URL steht. Abgerufen werden nur https-URLs auf 3sat.de bzw. api.3sat.de.
 */
class DreiSatProvider implements MediaSourceProviderInterface {
	private const API_HOST = 'api.3sat.de';

	/** Player-Profil des 3sat-Webplayers; liefert u. a. eine m3u8. */
	private const PLAYER_ID = 'ngplayer_2_4';

	private const PTMD_TEMPLATE_KEY = 'http://zdf.de/rels/streams/ptmd-template';

	public function __construct(
		private MediaHttpClient $http,
	) {
	}

	public function type(): string {
		return '3sat';
	}

	public function resolvesPerRequest(): bool {
		return true;
	}

	public function resolve(MediaContext $context): ?MediaResult {
		if (!VariantHelper::isHttpsUrlOnDomain($context->articleUrl, ['3sat.de'])) {
			return null;
		}
		$html = $this->http->getText($context->articleUrl);
		if ($html === null) {
			return null;
		}

		$player = self::parsePlayerConfig($html);
		if ($player === null) {
			return null;
		}
		$auth = ['Api-Auth: Bearer ' . $player['apiToken']];

		$content = $this->http->getJson($player['content'], $auth);
		if ($content === null) {
			return null;
		}
		$template = self::findPtmdTemplate($content['mainVideoContent'] ?? null) ?? self::findPtmdTemplate($content);
		if ($template === null || !str_starts_with($template, '/')) {
			return null;
		}

		$ptmdUrl = 'https://' . self::API_HOST . str_replace('{playerId}', self::PLAYER_ID, $template);
		if (!VariantHelper::isHttpsUrlOnHost($ptmdUrl, [self::API_HOST])) {
			return null;
		}
		$ptmd = $this->http->getJson($ptmdUrl, $auth);
		$url = $ptmd === null ? null : ZdfProvider::findFirstHlsUrlInPtmd($ptmd);
		if ($url === null) {
			return null;
		}

		return VariantHelper::buildHlsResult($context->kind(), [['label' => 'Standard', 'url' => $url]]);
	}

	/**
	 * Content-URL und Api-Token aus data-zdfplayer-jsb, oder null, wenn eins
	 * fehlt oder die Content-URL nicht auf api.3sat.de zeigt.
	 *
	 * @return array{content: string, apiToken: string}|null
	 */
	public static function parsePlayerConfig(string $html): ?array {
		if (preg_match('/data-zdfplayer-jsb=(["\'])(.*?)\1/s', $html, $m) !== 1) {
			return null;
		}
		$jsb = json_decode(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
		$content = $jsb['content'] ?? null;
		$token = $jsb['apiToken'] ?? null;
		if (!is_string($content) || !is_string($token) || trim($token) === ''
			|| !VariantHelper::isHttpsUrlOnHost($content, [self::API_HOST])) {
			return null;
		}
		return ['content' => $content, 'apiToken' => trim($token)];
	}

	/**
	 * Erstes PTMD-Template in einem (Teil-)Baum der Content-Antwort.
	 */
	private static function findPtmdTemplate(mixed $node): ?string {
		if (!is_array($node)) {
			return null;
		}
		$value = $node[self::PTMD_TEMPLATE_KEY] ?? null;
		if (is_string($value)) {
			return $value;
		}
		foreach ($node as $child) {
			$found = self::findPtmdTemplate($child);
			if ($found !== null) {
				return $found;
			}
		}
		return null;
	}
}
