<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media;

/**
 * Optional: Provider, deren Seiten die Beschreibung nicht per XPath/JSON
 * erreichbar ausliefern (z. B. zdf.de im Next.js-RSC-Payload), lesen sie
 * selbst aus dem rohen HTML.
 *
 * Greift nur, wenn die Domain-Config KEIN <description> deklariert – eine
 * explizite Regel im Content-Filter hat immer Vorrang.
 */
interface DescriptionProviderInterface {
	/**
	 * @return array{teaser: ?string, paragraphs: list<string>}
	 */
	public function extractDescription(string $rawHtml): array;
}
