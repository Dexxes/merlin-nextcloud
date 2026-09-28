<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media\Provider;

use OCA\Merlin\Service\Media\MediaContext;
use OCA\Merlin\Service\Media\MediaResult;
use OCA\Merlin\Service\Media\MediaSourceProviderInterface;
use OCA\Merlin\Service\Media\VariantHelper;

/**
 * type="xpath": generisch – die Medien-URL steht im Seiten-HTML und wird per
 * XPath gelesen (Attribut oder Textinhalt). Deckt alle Sender ab, die ihre
 * Audio-/Videodatei direkt im Markup ausliefern, z. B. Deutschlandfunk:
 *
 *   <source type="xpath" kind="audio"
 *           xpath="(//*[@data-audio and not(contains(@class,'b-btn-live'))]/@data-audio)[1]"
 *           host-allow="dradio.de" />
 *
 * host-allow (Domain-Suffixe, komma-/leerzeichengetrennt) begrenzt, auf
 * welche Hosts die gefundene URL zeigen darf – ohne host-allow ist jede
 * https-URL erlaubt. Eine .m3u8-URL wird als HLS, alles andere als direkte
 * Datei ausgeliefert.
 *
 * Braucht das rohe HTML, wirkt also nur beim Speichern (siehe MediaContext).
 */
class XPathMediaProvider implements MediaSourceProviderInterface {
	public function type(): string {
		return 'xpath';
	}

	public function resolvesPerRequest(): bool {
		return false;
	}

	public function resolve(MediaContext $context): ?MediaResult {
		$expression = $context->attribute('xpath');
		if ($context->rawHtml === null || $expression === null) {
			return null;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?>' . $context->rawHtml, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$nodes = @(new \DOMXPath($dom))->query($expression);
		if ($nodes === false) {
			return null;
		}

		$allowed = VariantHelper::parseHostList($context->attribute('host-allow'));
		foreach ($nodes as $node) {
			$url = $this->absoluteUrl(trim((string) $node->nodeValue), $context->articleUrl);
			if ($url !== null && VariantHelper::isHttpsUrlOnDomain($url, $allowed)) {
				return MediaResult::single($context->kind(), VariantHelper::deliveryForUrl($url), $url);
			}
		}

		return null;
	}

	/** Protokoll-relative ("//host/…") und host-relative ("/…") URLs auflösen. */
	private function absoluteUrl(string $value, string $baseUrl): ?string {
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($value === '') {
			return null;
		}
		if (str_starts_with($value, '//')) {
			return 'https:' . $value;
		}
		if (str_starts_with($value, '/')) {
			$host = parse_url($baseUrl, PHP_URL_HOST);
			return is_string($host) ? 'https://' . $host . $value : null;
		}
		return $value;
	}
}
