<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

/**
 * $domain steht in content-filters/$unsupported.xml: eine Seite, bei der
 * Merlin jeden Scrape-Versuch von vornherein ablehnt, z. B. weil der
 * Artikeltext serverseitig gar nicht als HTML ausgeliefert wird (reine
 * JS-SPA oder Bild/Canvas-Viewer wie PressReader) und Readability dort
 * grundsätzlich nichts extrahieren könnte - ein Fetch würde nur unnötigen
 * Traffic beim Zielserver erzeugen und mit einem rohen, für Nutzer
 * unverständlichen ParseException fehlschlagen.
 *
 * Wird von ArticleController/ExtensionController in ein eindeutiges,
 * maschinenlesbares Feld auf dem Article übersetzt (unsupportedSiteDomain),
 * analog zu Login\PaywallLoginRequiredException::$domain -> requiresLoginDomain.
 */
class UnsupportedSiteException extends \Exception {
	public function __construct(
		public readonly string $domain,
	) {
		parent::__construct('Diese Seite wird von Merlin nicht unterstützt und wird nicht abgerufen: ' . $domain);
	}
}
