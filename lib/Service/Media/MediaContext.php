<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media;

/**
 * Eingabe für einen Provider: die Artikel-URL, die <source>-Regel aus dem
 * Content-Filter (mit providerspezifischen Attributen) und – nur beim
 * Speichern eines Artikels – das rohe Seiten-HTML.
 *
 * $rawHtml ist beim Öffnen eines bereits gespeicherten Artikels null: dann
 * wird die Seite bewusst nicht erneut geladen. Provider, die das HTML
 * brauchen (xpath, json-ld), liefern in dem Fall null – ihr Ergebnis steht
 * ohnehin schon als Marker im gespeicherten Content (siehe
 * MediaResolverService::buildMarkerHtml()).
 */
final class MediaContext {
	public function __construct(
		public readonly string $articleUrl,
		public readonly \SimpleXMLElement $source,
		public readonly ?string $rawHtml = null,
	) {
	}

	/** Attributwert der <source>-Regel oder null, wenn nicht gesetzt/leer. */
	public function attribute(string $name): ?string {
		$value = isset($this->source[$name]) ? trim((string) $this->source[$name]) : '';
		return $value === '' ? null : $value;
	}

	/** Die in der Regel deklarierte Medienart (video/audio). */
	public function kind(): string {
		return $this->attribute('kind') ?? MediaResult::KIND_VIDEO;
	}
}
