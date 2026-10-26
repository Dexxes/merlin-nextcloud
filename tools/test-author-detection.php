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

echo "\n\033[1m<author-image>/<author-bio> (Autorenbox)\033[0m\n";

$check(
	'Ein Autor: Foto per <img>-Element, Bio mit Zeilenumbrüchen, relative URL aufgelöst',
	'<html><head><meta property="article:author" content="Bea Bild"></head><body>'
		. '<figure class="af"><img src="/profil/bea.webp" alt="Bea Bild"></figure>'
		. '<div class="bio">  Jahrgang 1980,' . "\n" . '   schreibt über   Kultur.<br>Bluesky: @bea  </div></body></html>',
	'<domain name="example.com"><metadata><author-image xpath="//figure[@class=\'af\']/img" />'
		. '<author-bio xpath="//div[@class=\'bio\']" /></metadata></domain>',
	$a('Bea Bild') + ['authors' => [
		['name' => 'Bea Bild', 'url' => null, 'image' => 'https://example.com/profil/bea.webp',
			'bio' => "Jahrgang 1980,\nschreibt über Kultur.\nBluesky: @bea"],
	]]
);

$check(
	'Co-Autoren: Fotos über den Namen im alt-Text zugeordnet, auch wenn nicht jeder eins hat',
	'<html><head><meta property="article:author" content="Bea Bild"><meta property="article:author" content="Carl Cut">'
		. '<meta property="article:author" content="Dana Druck"></head><body><div class="byline">'
		. '<figure class="af"><img src="https://cdn.example.com/carl.jpg" alt="Carl Cut "></figure>'
		. '<figure class="af"><img src="http://cdn.example.com/dana.jpg" alt="Dana Druck"></figure>'
		. '<span>Bea Bild</span>, <span>Carl Cut</span> und <span>Dana Druck</span></div></body></html>',
	'<domain name="example.com"><metadata><author-image xpath="//figure[@class=\'af\']/img/@src" /></metadata></domain>',
	$a('Bea Bild, Carl Cut, Dana Druck') + ['authors' => [
		['name' => 'Bea Bild', 'url' => null],
		['name' => 'Carl Cut', 'url' => null, 'image' => 'https://cdn.example.com/carl.jpg'],
		['name' => 'Dana Druck', 'url' => null, 'image' => 'https://cdn.example.com/dana.jpg'],
	]]
);

$check(
	'Co-Autoren: Bio über den Namen im umschließenden Element zugeordnet',
	'<html><head><meta property="article:author" content="Bea Bild"><meta property="article:author" content="Carl Cut"></head><body>'
		. '<div class="box"><a>Carl Cut</a><div class="bio">Schreibt über Sport.</div></div></body></html>',
	'<domain name="example.com"><metadata><author-bio xpath="//div[@class=\'bio\']" /></metadata></domain>',
	$a('Bea Bild, Carl Cut') + ['authors' => [
		['name' => 'Bea Bild', 'url' => null],
		['name' => 'Carl Cut', 'url' => null, 'bio' => 'Schreibt über Sport.'],
	]]
);

$check(
	'Co-Autoren per JSON-Pfad: der Reihe nach, javascript:-Bild verworfen',
	'<html><head><script type="application/ld+json">{"@type":"Article","author":['
		. '{"@type":"Person","name":"Bea Bild","image":"https://example.com/bea.jpg"},'
		. '{"@type":"Person","name":"Carl Cut","image":"https://example.com/carl.jpg"}]}</script></head><body></body></html>',
	'<domain name="example.com">' . $ldSource . '<metadata><author json="ld:$.author[*].name" />'
		. '<author-image json="ld:$.author[*].image" /><author-bio xpath="//p[@class=\'gibtsnicht\']" /></metadata></domain>',
	$a('Bea Bild, Carl Cut') + ['authors' => [
		['name' => 'Bea Bild', 'url' => null, 'image' => 'https://example.com/bea.jpg'],
		['name' => 'Carl Cut', 'url' => null, 'image' => 'https://example.com/carl.jpg'],
	]]
);

$check(
	'Foto mit javascript:-URL wird ignoriert -> keine Autorenliste',
	'<html><head><meta property="article:author" content="Bea Bild"></head><body><img class="af" src="javascript:alert(1)"></body></html>',
	'<domain name="example.com"><metadata><author-image xpath="//img[@class=\'af\']" /></metadata></domain>',
	$a('Bea Bild') + ['authors' => null]
);

echo "\n\033[1mRollen-Etikett vor dem Namen (z. B. nd-aktuell.de)\033[0m\n";

$ndConfig = '<domain name="example.com"><metadata>'
	. '<author xpath="//ul[contains(@class,\'Infoline\')]/li[contains(@class,\'Author\')]//a" />'
	. '</metadata></domain>';

$check(
	'Domain-Regel: "Interview: Name" im Profil-Link -> Etikett entfernt, Link bleibt',
	'<html><body><ul class="Infoline"><li class="Author"><a href="/redaktion/autor/191"> Interview: Matthias Monroy</a></li></ul></body></html>',
	$ndConfig,
	$a('Matthias Monroy', 'https://example.com/redaktion/autor/191')
);

$check(
	'Domain-Regel: Name ohne Etikett bleibt unverändert',
	'<html><body><ul class="Infoline"><li class="Author"><a href="/redaktion/autor/121">Stephan Kaufmann</a></li></ul></body></html>',
	$ndConfig,
	$a('Stephan Kaufmann', 'https://example.com/redaktion/autor/121')
);

$check(
	'Generische Byline: "Interview: Name" -> Etikett entfernt',
	'<html><body><a rel="author" href="/autor/jana">Interview: Jana Muster</a></body></html>',
	null,
	$a('Jana Muster', 'https://example.com/autor/jana')
);

echo "\n\033[1mnetzpolitik.org: Box \"Über die Autor:innen\"\033[0m\n";

// Echte Filterdatei, gekürztes HTML einer Artikelseite mit zwei Autoren.
$npConfig = preg_replace('/<domain name="[^"]*"/', '<domain name="example.com"', (string) file_get_contents(__DIR__ . '/../content-filters/netzpolitik.org.xml'));
$npEntry  = fn (string $slug, string $name, string $img, string $bio): string =>
	'<li class="np-outro-author-entry"><figure class="np-outro-author-image"><img src="' . $img . '" alt="' . $name . '" class="author-avatars__img"></figure>'
	. '<div class="np-outro-authro-inner-wrapper"><div class="np-outro-author-name"><a href="https://netzpolitik.org/author/' . $slug . '/">' . $name . '</a></div>'
	. '<div class="np-outro-author-biography"><p>' . $bio . '</p><p><strong>Kontakt: </strong><a href="mailto:x@netzpolitik.org">E-Mail</a>, <a href="https://mastodon.social/@x">Mastodon</a></p><p><strong>Foto:</strong> Jemand</p></div></div></li>';

$check(
	'Foto und erster Bio-Absatz je Autor, ohne Kontakt- und Fotonachweis-Zeile',
	'<html><body><div class="np-intro-author"><ul class="np-intro-author-image-list"><li><img src="https://cdn.netzpolitik.org/ingo.jpg" alt="Ingo Dachwitz"></li></ul>'
		. '<div class="np-intro-author-name-list"><div class="np-intro-author-name-list"><a href="https://netzpolitik.org/author/ingo/">Ingo Dachwitz</a> und <a href="https://netzpolitik.org/author/sebastian/">Sebastian Meineck</a></div></div></div>'
		. '<p>Artikeltext.</p>'
		. '<div class="np-outro-author"><h3>Über die Autor:innen</h3><ul class="np-outro-author-list">'
		. $npEntry('ingo', 'Ingo Dachwitz', 'https://cdn.netzpolitik.org/ingo.jpg', 'Ingo ist Journalist.')
		. $npEntry('sebastian', 'Sebastian Meineck', 'https://cdn.netzpolitik.org/seb.png', 'Sebastian Meineck ist Redakteur.')
		. '</ul></div></body></html>',
	$npConfig,
	$a('Ingo Dachwitz, Sebastian Meineck') + ['authors' => [
		['name' => 'Ingo Dachwitz', 'url' => 'https://netzpolitik.org/author/ingo/', 'image' => 'https://cdn.netzpolitik.org/ingo.jpg', 'bio' => 'Ingo ist Journalist.'],
		['name' => 'Sebastian Meineck', 'url' => 'https://netzpolitik.org/author/sebastian/', 'image' => 'https://cdn.netzpolitik.org/seb.png', 'bio' => 'Sebastian Meineck ist Redakteur.'],
	]]
);

echo "\n\033[1m<author-bio from=\"author-page\"> (Autorenseite)\033[0m\n";

// Unterklasse ohne Netzwerk: liefert Autorenseiten aus $pages und merkt sich die Abrufe.
$pageService = new class extends ContentExtractorService {
	public array $pages   = [];
	public array $fetched = [];
	public function __construct() {
	}
	protected function fetchAuthorPage(string $url): ?string {
		$this->fetched[] = $url;
		return $this->pages[$url] ?? null;
	}
};
$fillFromPages = new ReflectionMethod(ContentExtractorService::class, 'fillAuthorExtrasFromAuthorPages');

$checkPages = function (string $label, array $meta, string $configXml, array $pages, array $expectedAuthors, array $expectedFetches)
	use ($pageService, $contentFiltersProp, $fillFromPages, $repoStubClass, &$passed, &$failures): void {
	$contentFiltersProp->setValue($pageService, new ($repoStubClass::class)(new \SimpleXMLElement($configXml)));
	$pageService->pages   = $pages;
	$pageService->fetched = [];
	$result = $fillFromPages->invoke($pageService, $meta, 'example.com', 'https://example.com/2026/10/artikel/', null);
	$actual = ['authors' => $result['authors'] ?? null, 'fetched' => $pageService->fetched];
	$expected = ['authors' => $expectedAuthors, 'fetched' => $expectedFetches];
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

$pageConfig = '<domain name="example.com"><metadata>'
	. '<author-bio xpath="//div[@class=\'bio\']" />'
	. '<author-bio from="author-page" xpath="//div[@class=\'profil-bio\']" />'
	. '<author-image from="author-page" xpath="//img[@class=\'profil-foto\']" />'
	. '</metadata></domain>';
$profilePage = '<html><body><img class="profil-foto" src="/fotos/bea.jpg"><div class="profil-bio">Schreibt über Kultur.</div></body></html>';

$checkPages(
	'Bio und Foto fehlen im Artikel -> von der Autorenseite',
	['author' => 'Bea Bild', 'authors' => [['name' => 'Bea Bild', 'url' => 'https://example.com/autor/bea']]],
	$pageConfig,
	['https://example.com/autor/bea' => $profilePage],
	[['name' => 'Bea Bild', 'url' => 'https://example.com/autor/bea', 'image' => 'https://example.com/fotos/bea.jpg', 'bio' => 'Schreibt über Kultur.']],
	['https://example.com/autor/bea']
);

$checkPages(
	'Bio und Foto schon da -> keine Autorenseite geladen',
	['author' => 'Bea Bild', 'authors' => [['name' => 'Bea Bild', 'url' => 'https://example.com/autor/bea', 'image' => 'https://example.com/a.jpg', 'bio' => 'Aus dem Artikel.']]],
	$pageConfig,
	['https://example.com/autor/bea' => $profilePage],
	[['name' => 'Bea Bild', 'url' => 'https://example.com/autor/bea', 'image' => 'https://example.com/a.jpg', 'bio' => 'Aus dem Artikel.']],
	[]
);

$checkPages(
	'Profil-Link auf fremder Website -> nicht geladen; Seite nicht ladbar -> Feld bleibt leer',
	['author' => 'Bea Bild, Carl Cut', 'authors' => [
		['name' => 'Bea Bild', 'url' => 'https://twitter.com/bea'],
		['name' => 'Carl Cut', 'url' => 'https://www.example.com/autor/carl'],
	]],
	$pageConfig,
	[],
	[['name' => 'Bea Bild', 'url' => 'https://twitter.com/bea'], ['name' => 'Carl Cut', 'url' => 'https://www.example.com/autor/carl']],
	['https://www.example.com/autor/carl']
);

// rbb24.de: echte Filterdatei, gekürzte Autorenseiten (article.cast).
$rbbConfig = preg_replace('/<domain name="[^"]*"/', '<domain name="example.com"', (string) file_get_contents(__DIR__ . '/../content-filters/rbb24.de.xml'));
$rbbPage   = fn (string $img, string $alt, string $items): string =>
	'<html><body><main><article class="cast" aria-label=""><div class="container__cast">'
	. '<figure class="cast__image-container"><picture><source srcset="' . $img . '.webp" type="image/webp"><img src="' . $img . '" alt="' . $alt . '" title="' . $alt . '" loading="lazy"></picture></figure>'
	. '<h2 class="cast__name headline-xl">Name</h2><span class="cast__text body-lg"><p>Autorin rbb|24</p></span>'
	. '<div class="cast__additional_info body-status-quo font-light">' . $items . '</div></div></article>'
	. '<section><article class="layout_1_3 standard teaser"><figure class="teaser__image-container"><img src="/teaser.jpg" alt="Teaser"></figure><p>Teaser-Text.</p></article></section></main></body></html>';

$checkPages(
	'rbb24.de: Foto und erster Bio-Absatz von der Autorenseite, Platzhalterbild bleibt weg',
	['author' => 'Barbara Behrendt, Sabine Priess', 'authors' => [
		['name' => 'Barbara Behrendt', 'url' => 'https://www.example.com/autoren/barbara-behrendt.html'],
		['name' => 'Sabine Priess', 'url' => 'https://www.example.com/autoren/sabine-priess.html'],
	]],
	$rbbConfig,
	[
		'https://www.example.com/autoren/barbara-behrendt.html' => $rbbPage(
			'/content/dam/rbb/rbb/rbb24/2026/2026_02/rbb-reporter/barbara-behrendt-foto.jpg.jpg/quality=160/size=712x400.jpg',
			'Barbara Behrendt',
			'<span class="cast__additional_info_item"></span>'
				. '<span class="cast__additional_info_item"><p>Ich komme aus dem Print-Bereich.<br></p></span>'
				. '<span class="cast__additional_info_item"><p>Mein Schwerpunkt ist das Berliner Theaterleben.</p></span>'
		),
		'https://www.example.com/autoren/sabine-priess.html' => $rbbPage(
			'/content/dam/rbb/rbb/rbb24/Autorenbilder/rbb24-autorenprofil-weiblich.png.png/quality=160/size=712x400.png',
			'Symbolbild: rbb24 Autorinnenprofil weiblich. (Quelle: rbb24)',
			'<span class="cast__additional_info_item"><p>Sabine Priess ist seit 2003 Redakteurin beim rbb.</p></span>'
				. '<span class="cast__additional_info_item"><p><a href="mailto:x@rbb-online.de">Mail an die Autorin</a></p></span>'
		),
	],
	[
		['name' => 'Barbara Behrendt', 'url' => 'https://www.example.com/autoren/barbara-behrendt.html',
			'image' => 'https://www.example.com/content/dam/rbb/rbb/rbb24/2026/2026_02/rbb-reporter/barbara-behrendt-foto.jpg.jpg/quality=160/size=712x400.jpg',
			'bio' => 'Ich komme aus dem Print-Bereich.'],
		['name' => 'Sabine Priess', 'url' => 'https://www.example.com/autoren/sabine-priess.html',
			'bio' => 'Sabine Priess ist seit 2003 Redakteurin beim rbb.'],
	],
	['https://www.example.com/autoren/barbara-behrendt.html', 'https://www.example.com/autoren/sabine-priess.html']
);

// morgenpost.de: echte Filterdatei, gekürzte Byline bzw. Autorenseite.
$mopoConfig = preg_replace('/<domain name="[^"]*"/', '<domain name="example.com"', (string) file_get_contents(__DIR__ . '/../content-filters/morgenpost.de.xml'));
$mopoByline = fn (string $slug, string $name, ?string $img): string =>
	'<figure class="flex flex-row items-center gap-2 md:gap-2.5">'
	. ($img === null ? '' : '<a rel="author" class="inline-block" href="/autoren/' . $slug . '/" data-track-v2-parent="Autorenkennzeichnung" data-track-v2-label="Autorenbild">'
		. '<picture class="relative isolate"><source srcset="' . $img . '_v1_1_200.webp 200w, ' . $img . '_v1_1_400.webp 400w" type="image/webp" sizes="60px"/>'
		. '<img src="' . $img . '_v16_9_1200.jpeg" class="relative z-10 rounded-full w-[60px]" alt="' . $name . '" title="' . $name . '"/></picture></a>')
	. '<figcaption><address class="not-italic">Von <a rel="author" href="/autoren/' . $slug . '/" data-track-v2-label="Autorenname" class="hover:underline">' . $name . '</a>, Redakteurin</address></figcaption></figure>';
$mopoArticle = fn (string $byline, string $slug, string $name): string =>
	'<html><head><meta name="author" content="' . $name . '"/>'
	. '<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","author":[{"@type":"Person","name":"' . $name . '","url":"https://example.com/autoren/' . $slug . '/"}]}</script>'
	. '</head><body><article>' . $byline . '<p>Artikeltext.</p></article></body></html>';

$check(
	'morgenpost.de: Foto aus der Byline',
	$mopoArticle($mopoByline('nina-noire-kugler', 'Nina Noire Kugler', 'https://img.sparknews.funkemedien.de/413027162/413027162_1788263936'), 'nina-noire-kugler', 'Nina Noire Kugler'),
	$mopoConfig,
	$a('Nina Noire Kugler', 'https://example.com/autoren/nina-noire-kugler/') + ['authors' => [
		['name' => 'Nina Noire Kugler', 'url' => 'https://example.com/autoren/nina-noire-kugler/',
			'image' => 'https://img.sparknews.funkemedien.de/413027162/413027162_1788263936_v16_9_1200.jpeg'],
	]]
);

$check(
	'morgenpost.de: Byline ohne Foto -> kein Bild',
	$mopoArticle($mopoByline('isabell-juergens', 'Isabell Jürgens', null), 'isabell-juergens', 'Isabell Jürgens'),
	$mopoConfig,
	$a('Isabell Jürgens', 'https://example.com/autoren/isabell-juergens/') + ['authors' => [
		['name' => 'Isabell Jürgens', 'url' => 'https://example.com/autoren/isabell-juergens/'],
	]]
);

$mopoPage = fn (string $name, string $bio): string =>
	'<html><body><main><article class="m-4 mb-8 flex flex-col"><div class="mt-2 grow self-stretch p-4 shadow-smd"><header class="mb-2 text-center">'
	. '<h2 class="mb-4 text-title4">' . $name . '</h2><p class="text-gray-ts mb-2 uppercase">Redakteurin, Berliner Morgenpost</p>'
	. '<ul data-test-social-follow-bar=""><li><a href="mailto:x@funkemedien.de"><img src="/static/bmo/mail-dark.svg" alt="' . $name . ' auf E-Mail"/></a></li></ul></header>'
	. '<p data-test-details-description="" class="mb-6">' . $bio . '</p></div></article>'
	. '<section><article><p>Teaser-Text.</p></article></section></main></body></html>';

$checkPages(
	'morgenpost.de: Bio von der Autorenseite, leere Beschreibung bleibt weg',
	['author' => 'Nina Noire Kugler, Isabell Jürgens', 'authors' => [
		['name' => 'Nina Noire Kugler', 'url' => 'https://www.example.com/autoren/nina-noire-kugler/', 'image' => 'https://img.sparknews.funkemedien.de/nina.jpeg'],
		['name' => 'Isabell Jürgens', 'url' => 'https://www.example.com/autoren/isabell-juergens/'],
	]],
	$mopoConfig,
	[
		'https://www.example.com/autoren/nina-noire-kugler/' => $mopoPage('Nina Noire Kugler',
			'Nina Noire Kugler (Jahrgang 1988) kommt aus Stuttgart und arbeitet seit 2023 für die FUNKE Zentralredaktion in Berlin. '),
		'https://www.example.com/autoren/isabell-juergens/' => $mopoPage('Isabell Jürgens', ''),
	],
	[
		['name' => 'Nina Noire Kugler', 'url' => 'https://www.example.com/autoren/nina-noire-kugler/', 'image' => 'https://img.sparknews.funkemedien.de/nina.jpeg',
			'bio' => 'Nina Noire Kugler (Jahrgang 1988) kommt aus Stuttgart und arbeitet seit 2023 für die FUNKE Zentralredaktion in Berlin.'],
		['name' => 'Isabell Jürgens', 'url' => 'https://www.example.com/autoren/isabell-juergens/'],
	],
	['https://www.example.com/autoren/nina-noire-kugler/', 'https://www.example.com/autoren/isabell-juergens/']
);

// tagesspiegel.de: echte Filterdatei, gekürzte Byline bzw. Autorenseiten.
$tspConfig = preg_replace('/<domain name="[^"]*"/', '<domain name="example.com"', (string) file_get_contents(__DIR__ . '/../content-filters/tagesspiegel.de.xml'));
$tspLi = fn (string $href, string $name): string =>
	'<li property="author" typeof="Person"><a href="' . $href . '" property="url name" data-gtm-class="article-author-link" class="tspBNmm"> ' . $name . '</a></li>';

$check(
	'tagesspiegel.de: mehrere Autor*innen mit Profil-Link aus der Byline',
	'<html><body><main id="main-content"><article><header><div class="tspBGk5"><div> Von <ul class="tspBGk9">'
		. $tspLi('/autoren/daniel-boldt', 'Daniel Böldt') . $tspLi('/berlin/autoren/ingo-salmen', 'Ingo Salmen')
		. '</ul></div></div></header><p>Artikeltext.</p></article></main></body></html>',
	$tspConfig,
	$a('Daniel Böldt, Ingo Salmen') + ['authors' => [
		['name' => 'Daniel Böldt', 'url' => 'https://example.com/autoren/daniel-boldt'],
		['name' => 'Ingo Salmen', 'url' => 'https://example.com/berlin/autoren/ingo-salmen'],
	]]
);

$tspPage = fn (string $name, ?string $img, string $bio): string =>
	'<html><body><header class="tspA"><nav><p>Navigation</p></nav></header><main id="main-content" tabindex="-1" class="tspJbb"><div><div></div></div>'
	. '<header class="tspEai">'
	. ($img === null ? '<!---->' : '<figure class="tspBAj9 tspEaj"><div class="tspBAka"><img src="' . $img . '" alt="' . $name . '" width="160" height="160" loading="lazy" class="tspBDkq"></div></figure>')
	. '<div class="tspEak"><div><h1 class="tspEal">' . $name . '</h1><span class="tspEam"> Redakteur </span></div><p class="tspEan"> ' . $bio . ' </p></div></header>'
	. '<h2 class="tspEao">Aktuelle Artikel</h2><section><article><figure><img src="/images/1/teaser.jpeg" alt="Teaser"></figure><p>Teaser-Text.</p></article></section></main></body></html>';

$checkPages(
	'tagesspiegel.de: Foto und Bio von der Autorenseite, ohne Foto nur Bio',
	['author' => 'Ingo Salmen, Simon Röhricht', 'authors' => [
		['name' => 'Ingo Salmen', 'url' => 'https://www.example.com/berlin/autoren/ingo-salmen'],
		['name' => 'Simon Röhricht', 'url' => 'https://www.example.com/autoren/simon-rohricht'],
	]],
	$tspConfig,
	[
		'https://www.example.com/berlin/autoren/ingo-salmen' => $tspPage('Ingo Salmen',
			'/images/11532794/alternates/BASE_1_1_W160/1713367406000/ingo-salmen-20-3.jpeg',
			'Ingo Salmen leitet seit Sommer 2026 das Berlin-Ressort beim Tagesspiegel.'),
		'https://www.example.com/autoren/simon-rohricht' => $tspPage('Simon Röhricht', null,
			'Simon Röhricht arbeitet im Berlin-Ressort des Tagesspiegels.'),
	],
	[
		['name' => 'Ingo Salmen', 'url' => 'https://www.example.com/berlin/autoren/ingo-salmen',
			'image' => 'https://www.example.com/images/11532794/alternates/BASE_1_1_W160/1713367406000/ingo-salmen-20-3.jpeg',
			'bio' => 'Ingo Salmen leitet seit Sommer 2026 das Berlin-Ressort beim Tagesspiegel.'],
		['name' => 'Simon Röhricht', 'url' => 'https://www.example.com/autoren/simon-rohricht',
			'bio' => 'Simon Röhricht arbeitet im Berlin-Ressort des Tagesspiegels.'],
	],
	['https://www.example.com/berlin/autoren/ingo-salmen', 'https://www.example.com/autoren/simon-rohricht']
);

$check(
	'from="author-page"-Regeln laufen nicht auf der Artikelseite',
	'<html><head><meta property="article:author" content="Bea Bild"></head><body><div class="profil-bio">Falsch: Artikelseite.</div></body></html>',
	$pageConfig,
	$a('Bea Bild') + ['authors' => null]
);

echo "\n\033[1mmedium.com: Byline und Kasten \"Written by\"\033[0m\n";

// Echte Filterdatei, nachgebautes Medium-Markup (die Seite blockt Abrufe aus
// der Testumgebung): Byline mit data-testid="authorPhoto", Kasten unter dem
// Artikel mit ?source=post_page---post_author_info--…-Links.
$mediumConfig = preg_replace('/<domain name="[^"]*"/', '<domain name="example.com"', (string) file_get_contents(__DIR__ . '/../content-filters/medium.com.xml'));
$mediumSrc    = '?source=post_page---post_author_info--b405357e1198---------------------------------------';
$mediumPage   = fn (string $byline, string $box): string =>
	'<html><head><meta name="author" content="Guy Phenix"><meta property="article:author" content="https://medium.com/@GuyPhenix">'
	. '<script type="application/ld+json">{"@context":"http://schema.org","@type":"NewsArticle","headline":"That Time The X-Files &amp; Twin Peaks Merged","author":{"@type":"Person","name":"Guy Phenix","url":"https://medium.com/@GuyPhenix"},"creator":["Guy Phenix"],"publisher":{"@type":"Organization","name":"Medium","url":"https://medium.com/"}}</script>'
	. '</head><body><div id="root"><article><div class="l"><section><div><h1 data-testid="storyTitle">That Time The X-Files &amp; Twin Peaks Merged</h1>'
	. '<div class="speechify-ignore ab cp">' . $byline . '</div>'
	. '<p class="pw-post-body-paragraph">Artikeltext.</p></div></section></div></article>'
	. $box . '</div></body></html>';
$mediumByline = '<div class="ab"><a href="/@GuyPhenix?source=post_page---byline--b405357e1198---------------------------------------" rel="noopener follow">'
	. '<div class="m fn dc"><div class="m dz"><img alt="Guy Phenix" class="m eu bw ff fg dp" src="https://miro.medium.com/v2/resize:fill:64:64/1*Gp3x_AbC.jpeg" width="32" height="32" loading="lazy" data-testid="authorPhoto"></div></div></a>'
	. '<div class="ab q"><span><a class="ag ah" data-testid="authorName" href="/@GuyPhenix?source=post_page---byline--b405357e1198---------------------------------------" rel="noopener follow">Guy Phenix</a></span></div></div>';
$mediumBox = fn (string $bio): string =>
	'<div class="ou ov ow ox oy"><div class="ab co"><div class="ab"><a href="/@GuyPhenix' . $mediumSrc . '" rel="noopener follow">'
	. '<div class="m pa"><img alt="Guy Phenix" class="m eu bw pb pc dp" src="https://miro.medium.com/v2/resize:fill:96:96/1*Gp3x_AbC.jpeg" width="48" height="48" loading="lazy"></div></a></div>'
	. '<div class="ab pd"><button class="be b">Follow</button></div></div>'
	. '<div class="pe"><div class="ab"><a href="/@GuyPhenix' . $mediumSrc . '" rel="noopener follow"><h2 class="be uu uv"><span class="">Written by Guy Phenix</span></h2></a></div>'
	. '<div class="ab"><span class="pi"><a href="/@GuyPhenix/followers' . $mediumSrc . '" rel="noopener follow">1.4K followers</a></span>'
	. '<span>·</span><a href="/@GuyPhenix/following' . $mediumSrc . '" rel="noopener follow">190 following</a></div>'
	. $bio . '</div></div>'
	. '<div class="ap"><h2 class="be">No responses yet</h2><p class="be b">What are your thoughts?</p></div>';

$check(
	'medium.com: Foto (größere Variante) und Bio aus dem Kasten "Written by", Link aus JSON-LD',
	$mediumPage($mediumByline, $mediumBox('<div class="pj"><p class="be b dy"><span class="m">Writer, podcaster &amp; lifelong fan of all things weird.</span></p></div>')),
	$mediumConfig,
	$a('Guy Phenix', 'https://medium.com/@GuyPhenix') + ['authors' => [
		['name' => 'Guy Phenix', 'url' => 'https://medium.com/@GuyPhenix',
			'image' => 'https://miro.medium.com/v2/resize:fill:176:176/1*Gp3x_AbC.jpeg',
			'bio' => 'Writer, podcaster & lifelong fan of all things weird.'],
	]]
);

$check(
	'medium.com: Kasten ohne Bio -> keine Bio, kein fremder Absatz',
	$mediumPage($mediumByline, $mediumBox('')),
	$mediumConfig,
	$a('Guy Phenix', 'https://medium.com/@GuyPhenix') + ['authors' => [
		['name' => 'Guy Phenix', 'url' => 'https://medium.com/@GuyPhenix',
			'image' => 'https://miro.medium.com/v2/resize:fill:176:176/1*Gp3x_AbC.jpeg'],
	]]
);

$check(
	'medium.com: ohne Kasten -> Foto aus der Byline',
	$mediumPage($mediumByline, ''),
	$mediumConfig,
	$a('Guy Phenix', 'https://medium.com/@GuyPhenix') + ['authors' => [
		['name' => 'Guy Phenix', 'url' => 'https://medium.com/@GuyPhenix',
			'image' => 'https://miro.medium.com/v2/resize:fill:176:176/1*Gp3x_AbC.jpeg'],
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
