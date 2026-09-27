<?php

declare(strict_types=1);

/**
 * Testharness für die Duplikat-Behandlung in
 * ContentExtractorService::extractDomainMetadata(): mehrere identische
 * XPath-/JSON-Treffer für dasselbe Feld dürfen nicht mehr per implode(', ')
 * zu einem doppelten String zusammengefügt werden.
 *
 * Aufruf (auf dem Server, im App-Verzeichnis):
 *   php tools/test-extract-domain-metadata-dedup.php
 *
 * Hintergrund: nd-aktuell.de setzt og:description UND twitter:description
 * mit identischem Text. Der automatische OG_FALLBACK_XPATHS-Union-XPath für
 * "excerpt" liefert dadurch zwei Treffer für denselben String, die vor
 * diesem Fix unconditional zu "Text, Text" zusammengefügt wurden - im
 * Nextcloud-Client sichtbar als doppelter Teaser, durch die anschließende
 * 300-Zeichen-Kürzung (Zeile ~312) mitten im zweiten Text abgeschnitten.
 * array_unique() vor der implode()-Entscheidung entfernt nur EXAKTE
 * Duplikate - echte Mehrfachwerte (unterschiedliche og:/twitter:description,
 * mehrere <author xpath="…">-Regeln mit unterschiedlichen Namen) bleiben wie
 * bisher per ", " zusammengeführt.
 *
 * Der Service wird ohne Konstruktor instanziiert (newInstanceWithoutConstructor);
 * die getestete Methode braucht aber $this->contentFilters (für
 * loadDomainConfig()) - dafür wird ein Stub-ContentFilterRepository per
 * Reflection injiziert, der getMerged() ohne DB/Nextcloud-Bootstrap bedient.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\ContentExtractorService;
use OCA\Merlin\Service\ContentFilterRepository;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';
require_once __DIR__ . '/../lib/Service/ContentFilterRepository.php';

/**
 * Stub, der getMerged() ohne DB bedient: liefert die per Konstruktor
 * übergebene (oder keine) Domain-Config, unabhängig vom angefragten Domain-
 * Namen. Reicht für diese Tests, da pro Testfall höchstens eine Domain
 * abgefragt wird.
 */
$repoStubClass = new class extends ContentFilterRepository {
	private ?\SimpleXMLElement $config;

	public function __construct(?\SimpleXMLElement $config = null) {
		$this->config = $config;
	}

	public function getMerged(string $domain, ?string $userId = null): ?\SimpleXMLElement {
		return $this->config;
	}
};

$service = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();

$contentFiltersProp = new ReflectionProperty(ContentExtractorService::class, 'contentFilters');
$contentFiltersProp->setAccessible(true);

$extractDomainMetadata = new ReflectionMethod(ContentExtractorService::class, 'extractDomainMetadata');
$extractDomainMetadata->setAccessible(true);

$passed   = 0;
$failures = [];

/**
 * Prüft extractDomainMetadata() für ein HTML-Fragment. $domainConfigXml ist
 * der Inhalt eines <domain>...</domain>-Blocks (wie in content-filters/*.xml)
 * oder null, wenn kein domain-spezifisches <metadata> greifen soll (reiner
 * OG-Fallback-Pfad).
 */
$check = function (
	string $label,
	string $html,
	?string $domainConfigXml,
	string $field,
	?string $expected
) use ($service, $contentFiltersProp, $extractDomainMetadata, $repoStubClass, &$passed, &$failures): void {
	$config = $domainConfigXml !== null ? new \SimpleXMLElement($domainConfigXml) : null;
	$contentFiltersProp->setValue($service, new ($repoStubClass::class)($config));

	$result = $extractDomainMetadata->invoke($service, $html, 'example.com', null);
	$actual = $result[$field] ?? null;

	if ($actual === $expected) {
		$passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
		return;
	}
	$failures[] = $label;
	echo "  \033[31m✗ " . $label . "\033[0m\n";
	echo '      erwartet: ' . var_export($expected, true) . "\n";
	echo '      erhalten: ' . var_export($actual, true) . "\n";
};

echo "\n\033[1mOG-Fallback \"excerpt\": og:description | twitter:description Union\033[0m\n";

$check(
	'nd-aktuell.de-Fall: og:description und twitter:description identisch -> nur einmal im excerpt',
	'<html><head>'
		. '<meta property="og:description" content="Der Linke-Landesparteitag gibt Rückenwind.">'
		. '<meta name="twitter:description" content="Der Linke-Landesparteitag gibt Rückenwind.">'
		. '</head><body></body></html>',
	null,
	'excerpt',
	'Der Linke-Landesparteitag gibt Rückenwind.'
);

$check(
	'og:description und twitter:description unterschiedlich -> beide bleiben per ", " zusammengeführt',
	'<html><head>'
		. '<meta property="og:description" content="Teaser A.">'
		. '<meta name="twitter:description" content="Teaser B.">'
		. '</head><body></body></html>',
	null,
	'excerpt',
	'Teaser A., Teaser B.'
);

$check(
	'Nur twitter:description vorhanden (kein og:description) -> zweite Alternative der Union greift',
	'<html><head>'
		. '<meta name="twitter:description" content="Nur per Twitter-Tag gesetzt.">'
		. '</head><body></body></html>',
	null,
	'excerpt',
	'Nur per Twitter-Tag gesetzt.'
);

$check(
	'Kein Treffer für excerpt -> Feld fehlt im Ergebnis',
	'<html><head></head><body></body></html>',
	null,
	'excerpt',
	null
);

echo "\n\033[1mDomain-spezifische <author>-Regeln mit mehreren Treffern\033[0m\n";

$authorDomainConfig = <<<'XML'
<domain name="example.com">
  <metadata>
    <author xpath="//span[@class='author-name']" />
  </metadata>
</domain>
XML;

$check(
	'Zwei <span class="author-name"> mit unterschiedlichen Namen -> weiterhin per ", " zusammengeführt (Co-Autoren-Fall)',
	'<html><body>'
		. '<span class="author-name">Anna Autorin</span>'
		. '<span class="author-name">Ben Beispiel</span>'
		. '</body></html>',
	$authorDomainConfig,
	'author',
	'Anna Autorin, Ben Beispiel'
);

$check(
	'Zwei <span class="author-name"> mit demselben Namen (z. B. Regel matcht Element doppelt) -> nur einmal im Ergebnis',
	'<html><body>'
		. '<span class="author-name">Anna Autorin</span>'
		. '<span class="author-name">Anna Autorin</span>'
		. '</body></html>',
	$authorDomainConfig,
	'author',
	'Anna Autorin'
);

echo "\n" . str_repeat('─', 72) . "\n";
if ($failures === []) {
	echo "\033[32mAlle " . $passed . " Prüfungen bestanden.\033[0m\n";
	exit(0);
}
echo "\033[31m" . count($failures) . ' von ' . ($passed + count($failures)) . " Prüfungen fehlgeschlagen:\033[0m\n";
foreach ($failures as $failure) {
	echo '  · ' . $failure . "\n";
}
exit(1);
