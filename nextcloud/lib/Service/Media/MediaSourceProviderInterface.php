<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media;

/**
 * Ein Provider kennt genau einen Quellen-Typ (<source type="…">) und löst
 * daraus eine abspielbare Quelle auf.
 *
 * Neuer Sender:
 *   1. Reicht ein generischer Typ (xpath, json-ld)? Dann nur die
 *      content-filters/{domain}.xml um <media><source …/></media> ergänzen.
 *   2. Sonst eine Klasse unter Provider/ anlegen, ihren Typ in
 *      ContentFilterSchema::MEDIA_SOURCE_TYPES eintragen und sie in
 *      MediaProviderRegistry registrieren.
 *
 * Vertrag: fail-closed. Jeder unerwartete Zustand liefert null statt einer
 * Exception – ein nicht auflösbares Medium darf den Reader nie stören, es
 * fehlt dann einfach der Player. (MediaResolverService fängt trotzdem jede
 * Exception ab, als zweite Sicherung.)
 */
interface MediaSourceProviderInterface {
	/** Wert des type-Attributs, den dieser Provider bedient. */
	public function type(): string;

	/**
	 * true für Provider, deren Ergebnis kurzlebig ist (signierte
	 * Mediathek-Streams über eine Sender-API) und deshalb bei JEDEM Öffnen
	 * neu aufgelöst werden muss. Beim Speichern wird für sie nur ein Marker
	 * ohne URL geschrieben – der Sender-API-Aufruf passiert erst im Reader.
	 *
	 * false: das Ergebnis ist stabil und wird beim Speichern (mit dem rohen
	 * HTML) aufgelöst und als Marker samt URL im Content abgelegt.
	 */
	public function resolvesPerRequest(): bool;

	public function resolve(MediaContext $context): ?MediaResult;
}
