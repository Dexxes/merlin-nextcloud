<?php

declare(strict_types=1);

/**
 * Testharness für die domainübergreifende Erkennung von Autorennamen und
 * Autorenprofil-Links in ContentExtractorService::extractDomainMetadata()
 * (resolveAuthorMetadata()/extractGenericAuthor()/JSON-LD).
 *
 * Aufruf (im App-Verzeichnis):
 *   php tools/test-author-detection.php
 *
 * Aufbau wie tools/test-extract-domain-metadata-dedup.php: Service ohne
 * Konstruktor, Stub-ContentFilterRepository per Reflection.
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
 * Prüft Autor und Profil-Link aus extractDomainMetadata() für ein HTML-Fragment
 * (Artikel-URL: https://example.com/2026/10/artikel/). $domainConfigXml ist
 * der Inhalt eines <domain>...</domain>-Blocks (wie in content-filters/*.xml)
 * oder null, wenn kein domain-spezifisches <metadata> greifen soll (reiner
 * OG-Fallback-Pfad).
 */
$check = function (
	string $label,
	string $html,
	?string $domainConfigXml,
	array $expected
) use ($service, $contentFiltersProp, $extractDomainMetadata, $repoStubClass, &$passed, &$failures): void {
	$config = $domainConfigXml !== null ? new \SimpleXMLElement($domainConfigXml) : null;
	$contentFiltersProp->setValue($service, new ($repoStubClass::class)($config));

	$result = $extractDomainMetadata->invoke($service, $html, 'example.com', null, 'https://example.com/2026/10/artikel/');
	$actual = [
		'author'    => $result['author'] ?? null,
		'authorUrl' => $result['authorUrl'] ?? null,
	];
	// "authors" (Name + Link je Autor) nur prüfen, wo der Testfall es erwartet.
	if (array_key_exists('authors', $expected)) {
		$actual['authors'] = $result['authors'] ?? null;
	}

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

$a = fn (?string $author, ?string $url = null): array => ['author' => $author, 'authorUrl' => $url];

echo "\n\033[1mWordPress-Blöcke\033[0m\n";

$check(
	'wp-block-post-author-name mit __link -> Name und Profil-Link',
	'<html><body><article>'
		. '<div class="wp-block-post-author-name has-small-font-size"><a href="https://example.com/author/anna/" target="_self" class="wp-block-post-author-name__link">Anna Autorin</a></div>'
		. '<p>Text</p></article></body></html>',
	null,
	$a('Anna Autorin', 'https://example.com/author/anna/')
);

$check(
	'wp-block-post-author-name ohne Link -> nur Name',
	'<html><body><div class="wp-block-post-author-name">Anna Autorin</div></body></html>',
	null,
	$a('Anna Autorin')
);

$check(
	'Nur wp-block-post-author-name__link, relativer href -> absolut aufgelöst',
	'<html><body><a class="wp-block-post-author-name__link" href="/author/ben/">Ben Beispiel</a></body></html>',
	null,
	$a('Ben Beispiel', 'https://example.com/author/ben/')
);

$check(
	'Älterer Post-Author-Block (wp-block-post-author__name)',
	'<html><body><div class="wp-block-post-author"><div class="wp-block-post-author__content">'
		. '<p class="wp-block-post-author__name">Clara Chronistin</p></div></div></body></html>',
	null,
	$a('Clara Chronistin')
);

echo "\n\033[1mWeitere generische Signale\033[0m\n";

$check(
	'schema.org-Microdata: itemprop=author mit itemprop=name und itemprop=url',
	'<html><body><span itemprop="author" itemscope itemtype="https://schema.org/Person">'
		. '<a itemprop="url" href="https://example.com/team/dora"><span itemprop="name">Dora Dichterin</span></a></span></body></html>',
	null,
	$a('Dora Dichterin', 'https://example.com/team/dora')
);

$check(
	'hCard (klassisches WordPress-Theme): span.author.vcard > a.url.fn.n',
	'<html><body><span class="byline"><span class="author vcard"><a class="url fn n" href="https://example.com/author/emil/">Emil Erzähler</a></span></span></body></html>',
	null,
	$a('Emil Erzähler', 'https://example.com/author/emil/')
);

$check(
	'a[rel=author] mit "Von "-Präfix im Linktext',
	'<html><body><p><a rel="author" href="https://example.com/autoren/fritz">Von Fritz Feder</a></p></body></html>',
	null,
	$a('Fritz Feder', 'https://example.com/autoren/fritz')
);

$check(
	'Zwei verschiedene rel=author-Links -> Co-Autoren, je Autor ein Profil-Link, kein einzelner authorUrl',
	'<html><body><a rel="author" href="/a/g">Gina Glosse</a> und <a rel="author" href="/a/h">Hans Hintergrund</a></body></html>',
	null,
	$a('Gina Glosse, Hans Hintergrund') + ['authors' => [
		['name' => 'Gina Glosse', 'url' => 'https://example.com/a/g'],
		['name' => 'Hans Hintergrund', 'url' => 'https://example.com/a/h'],
	]]
);

$check(
	'Byline doppelt (Kopf + Autorenbox) -> nur einmal, Link aus dem ersten Treffer mit Link',
	'<html><body><div class="wp-block-post-author-name">Ida Idee</div>'
		. '<div class="wp-block-post-author-name"><a class="wp-block-post-author-name__link" href="/author/ida/">Ida Idee</a></div></body></html>',
	null,
	$a('Ida Idee', 'https://example.com/author/ida/')
);

$check(
	'javascript:-Link wird nicht als Profil übernommen',
	'<html><body><a rel="author" href="javascript:void(0)">Jana Journal</a></body></html>',
	null,
	$a('Jana Journal')
);

$check(
	'Keine Signale -> kein Autor',
	'<html><body><p>Nur Text.</p></body></html>',
	null,
	$a(null)
);

echo "\n\033[1marticle:author als URL\033[0m\n";

$check(
	'article:author ist eine Profil-URL, Name aus wp-block -> URL wird nicht zum Namen',
	'<html><head><meta property="article:author" content="https://www.facebook.com/kai.kolumne"></head>'
		. '<body><div class="wp-block-post-author-name"><a class="wp-block-post-author-name__link" href="https://example.com/author/kai/">Kai Kolumne</a></div></body></html>',
	null,
	$a('Kai Kolumne', 'https://example.com/author/kai/')
);

$check(
	'article:author ist eine URL, Name nur per Readability -> Name fehlt hier, URL bleibt für extract()',
	'<html><head><meta property="article:author" content="https://www.facebook.com/kai.kolumne"></head><body></body></html>',
	null,
	$a(null, 'https://www.facebook.com/kai.kolumne')
);

$check(
	'article:author ist ein Name -> wie bisher',
	'<html><head><meta property="article:author" content="Lena Leitartikel"></head><body></body></html>',
	null,
	$a('Lena Leitartikel')
);

echo "\n\033[1mJSON-LD\033[0m\n";

$check(
	'Yoast-@graph: author nur als @id-Verweis auf Person mit url',
	'<html><head><script type="application/ld+json">'
		. json_encode(['@context' => 'https://schema.org', '@graph' => [
			['@type' => 'Article', '@id' => 'https://example.com/2026/10/artikel/#article', 'headline' => 'X',
				'author' => ['@id' => 'https://example.com/#/schema/person/1']],
			['@type' => 'Person', '@id' => 'https://example.com/#/schema/person/1', 'name' => 'Mia Meinung',
				'url' => 'https://example.com/author/mia/'],
		]])
		. '</script></head><body></body></html>',
	null,
	$a('Mia Meinung', 'https://example.com/author/mia/')
);

$check(
	'JSON-LD mit zwei Autoren -> Namen, kein Profil-Link',
	'<html><head><script type="application/ld+json">'
		. json_encode(['@type' => 'NewsArticle', 'author' => [
			['@type' => 'Person', 'name' => 'Nina Notiz', 'url' => 'https://example.com/n'],
			['@type' => 'Person', 'name' => 'Olaf Online', 'url' => 'https://example.com/o'],
		]])
		. '</script></head><body></body></html>',
	null,
	$a('Nina Notiz, Olaf Online') + ['authors' => [
		['name' => 'Nina Notiz', 'url' => 'https://example.com/n'],
		['name' => 'Olaf Online', 'url' => 'https://example.com/o'],
	]]
);

echo "\n\033[1mDomain-Regeln\033[0m\n";

$check(
	'Domain-Regel trifft <a> -> dessen href wird Profil-Link',
	'<html><body><a class="m-from-author__name" href="/autor/paul">Paul Presse</a></body></html>',
	'<domain name="example.com"><metadata><author xpath="//a[@class=\'m-from-author__name\']" /></metadata></domain>',
	$a('Paul Presse', 'https://example.com/autor/paul')
);

$check(
	'Domain-Regel mit /text() -> Link über das Elternelement',
	'<html><body><p class="reader__meta-info"><a href="https://example.com/autor/quirin">Quirin Quelle</a></p></body></html>',
	'<domain name="example.com"><metadata><author xpath="//p[@class=\'reader__meta-info\']/a/text()" /></metadata></domain>',
	$a('Quirin Quelle', 'https://example.com/autor/quirin')
);

$check(
	'Domain-Regel ohne Link, gleicher Name per wp-block mit Link -> Link ergänzt',
	'<html><body><span class="author-name">Rita Reportage</span>'
		. '<div class="wp-block-post-author-name"><a class="wp-block-post-author-name__link" href="/author/rita/">Rita Reportage</a></div></body></html>',
	'<domain name="example.com"><metadata><author xpath="//span[@class=\'author-name\']" /></metadata></domain>',
	$a('Rita Reportage', 'https://example.com/author/rita/')
);

$check(
	'Domain-Regel-Treffer in Link auf den Artikel selbst -> kein Profil-Link',
	'<html><body><a href="https://example.com/2026/10/artikel/#top"><span class="author-name">Sven Seite</span></a></body></html>',
	'<domain name="example.com"><metadata><author xpath="//span[@class=\'author-name\']" /></metadata></domain>',
	$a('Sven Seite')
);

echo "\n\033[1m<author-link>-Regeln\033[0m\n";

$check(
	'<author-link> per Attribut-XPath hat Vorrang vor dem automatisch gefundenen Link',
	'<html><body><a class="wp-block-post-author-name__link" href="/author/tom/">Tom Text</a>'
		. '<a class="profil" href="/team/tom-text">Profil</a></body></html>',
	'<domain name="example.com"><metadata><author-link xpath="//a[@class=\'profil\']/@href" /></metadata></domain>',
	$a('Tom Text', 'https://example.com/team/tom-text')
);

$check(
	'<author-link> auf ein Element -> dessen href; Fallback-Kette über zwei Regeln',
	'<html><body><span class="author-name">Ute Umbruch</span><div class="box"><a href="https://example.com/ute">Mehr</a></div></body></html>',
	'<domain name="example.com"><metadata><author xpath="//span[@class=\'author-name\']" />'
		. '<author-link xpath="//a[@class=\'gibtsnicht\']" /><author-link xpath="//div[@class=\'box\']" /></metadata></domain>',
	$a('Ute Umbruch', 'https://example.com/ute')
);

$check(
	'<author-link> per JSON-Pfad',
	'<html><head><script type="application/ld+json">{"@type":"Person","profile":"https://example.com/vera"}</script></head>'
		. '<body><span class="author-name">Vera Vorspann</span></body></html>',
	'<domain name="example.com"><json id="ld" xpath="//script[@type=\'application/ld+json\']" />'
		. '<metadata><author xpath="//span[@class=\'author-name\']" /><author-link json="ld:$.profile" /></metadata></domain>',
	$a('Vera Vorspann', 'https://example.com/vera')
);

$check(
	'<author-link> mit javascript:-Wert wird ignoriert, automatischer Link bleibt',
	'<html><body><a rel="author" href="/autor/willi">Willi Wort</a><a id="x" href="javascript:alert(1)">x</a></body></html>',
	'<domain name="example.com"><metadata><author-link xpath="//a[@id=\'x\']/@href" /></metadata></domain>',
	$a('Willi Wort', 'https://example.com/autor/willi')
);

echo "\n\033[1mMehrere Autoren über [*]-JSON-Pfade und <author-link>\033[0m\n";

$ldTwo = '<script type="application/ld+json">' . json_encode(['@type' => 'Article', 'author' => [
	['@type' => 'Person', 'name' => 'Xaver Xylo', 'url' => 'https://example.com/x'],
	['@type' => 'Person', 'name' => 'Yvonne Yacht', 'url' => 'https://example.com/y'],
	['@type' => 'Person', 'name' => 'Zora Zeile', 'url' => 'https://example.com/z'],
]]) . '</script>';
$ldSource = '<json id="ld" xpath="//script[@type=\'application/ld+json\']" />';

$check(
	'author json="ld:$.author[*].name" + author-link json="ld:$.author[*].url" -> alle drei Autoren mit Link',
	'<html><head>' . $ldTwo . '</head><body></body></html>',
	'<domain name="example.com">' . $ldSource . '<metadata><author json="ld:$.author[*].name" />'
		. '<author-link json="ld:$.author[*].url" /></metadata></domain>',
	$a('Xaver Xylo, Yvonne Yacht, Zora Zeile') + ['authors' => [
		['name' => 'Xaver Xylo', 'url' => 'https://example.com/x'],
		['name' => 'Yvonne Yacht', 'url' => 'https://example.com/y'],
		['name' => 'Zora Zeile', 'url' => 'https://example.com/z'],
	]]
);

$check(
	'author json="ld:$.author[0].name" (Altform) -> weiterhin nur der erste Autor',
	'<html><head>' . $ldTwo . '</head><body></body></html>',
	'<domain name="example.com">' . $ldSource . '<metadata><author json="ld:$.author[0].name" />'
		. '<author-link json="ld:$.author[0].url" /></metadata></domain>',
	$a('Xaver Xylo', 'https://example.com/x')
);

$check(
	'[*] auf einem einzelnen author-Objekt (kein Array) -> wie ein Element',
	'<html><head><script type="application/ld+json">{"@type":"Article","author":{"@type":"Person","name":"Anton Abend","url":"https://example.com/anton"}}</script></head><body></body></html>',
	'<domain name="example.com">' . $ldSource . '<metadata><author json="ld:$.author[*].name" />'
		. '<author-link json="ld:$.author[*].url" /></metadata></domain>',
	$a('Anton Abend', 'https://example.com/anton')
);

$check(
	'Zwei <author>-Treffer ohne Link + <author-link>-XPath mit zwei Treffern -> der Reihe nach zugeordnet',
	'<html><body><p class="by"><span class="n">Bea Bild</span><span class="n">Carl Cut</span></p>'
		. '<footer><a class="p" href="/autor/bea">Bea</a><a class="p" href="/autor/carl">Carl</a></footer></body></html>',
	'<domain name="example.com"><metadata><author xpath="//span[@class=\'n\']" /><author-link xpath="//a[@class=\'p\']/@href" /></metadata></domain>',
	$a('Bea Bild, Carl Cut') + ['authors' => [
		['name' => 'Bea Bild', 'url' => 'https://example.com/autor/bea'],
		['name' => 'Carl Cut', 'url' => 'https://example.com/autor/carl'],
	]]
);

$check(
	'Anzahl Links passt nicht zur Anzahl Autoren -> keine Zuordnung per Reihenfolge',
	'<html><body><span class="n">Bea Bild</span><span class="n">Carl Cut</span><a class="p" href="/autor/bea">Bea</a></body></html>',
	'<domain name="example.com"><metadata><author xpath="//span[@class=\'n\']" /><author-link xpath="//a[@class=\'p\']/@href" /></metadata></domain>',
	$a('Bea Bild, Carl Cut') + ['authors' => null]
);

$check(
	'Co-Autoren per Domain-Regel auf <a> -> Link je Treffer',
	'<html><body><a class="au" href="/autor/dana">Dana Druck</a>, <a class="au" href="/autor/emil">Emil Eilmeldung</a></body></html>',
	'<domain name="example.com"><metadata><author xpath="//a[@class=\'au\']" /></metadata></domain>',
	$a('Dana Druck, Emil Eilmeldung') + ['authors' => [
		['name' => 'Dana Druck', 'url' => 'https://example.com/autor/dana'],
		['name' => 'Emil Eilmeldung', 'url' => 'https://example.com/autor/emil'],
	]]
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
