<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Lesezugriff auf die Content-Filter, wie ihn die Extraktion braucht:
 * verschmolzene Domain-Config (Bundle < Admin-Custom < User-Custom) und die
 * Sonderdateien $unsupported.xml und $caption-credits.xml.
 *
 * Nextcloud: OCA\Merlin\Service\ContentFilterRepository (Bundle aus
 * content-filters/, Custom-Ebenen aus der Tabelle merlin_cfilter).
 */
interface ContentFilterSource {
	/**
	 * Verschmolzene Config für $domain oder null, wenn keine Ebene einen
	 * Filter hat. $userId === null überspringt die User-Ebene.
	 */
	public function getMerged(string $domain, ?string $userId = null): ?\SimpleXMLElement;

	/**
	 * Host einer URL als Filter-Domain: kleingeschrieben, ohne "www.".
	 */
	public function normalizeUrlDomain(string $url): string;

	/**
	 * Ob $urlDomain (oder eine Elterndomain über einen Wildcard-Eintrag) in
	 * $unsupported.xml steht.
	 */
	public function isUnsupportedDomain(string $urlDomain): bool;

	/**
	 * Signalwörter für die Bildquelle in Captions aus $caption-credits.xml,
	 * leer, wenn die Datei fehlt.
	 *
	 * @return list<string>
	 */
	public function getCaptionCreditPrefixes(): array;
}
