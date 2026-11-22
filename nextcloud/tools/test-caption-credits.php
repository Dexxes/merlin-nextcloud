<?php

declare(strict_types=1);

/**
 * Testharness für das optionale credits-xpath-Attribut von
 * <images><caption> (ContentExtractorService::normalizeImageCaptions()).
 *
 * Aufruf (auf dem Server, im App-Verzeichnis):
 *   php tools/test-caption-credits.php
 *
 * credits-xpath liefert die Bildquelle getrennt vom Caption-Text. Sie landet
 * als <cite> hinter CAPTION_SEPARATOR in der <figcaption> - dieselbe Form,
 * die markCaptionCredit() sonst nur heuristisch erzeugt. Geprüft wird
 * zusätzlich der Hero-Pfad: Step 12 übernimmt nur den Text der Caption, die
 * Bereinigung (sanitizeHtml()) muss die Quelle dort wieder als <cite>
 * abtrennen.
 *
 * Der Service wird ohne Konstruktor instanziiert; $this->contentFilters wird
 * per Reflection durch einen Stub ersetzt, der getMerged() ohne DB bedient.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\ContentExtractorService;
use OCA\Merlin\Service\ContentFilterRepository;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';
require_once __DIR__ . '/../lib/Service/ContentFilterRepository.php';

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
$loggerProp = new ReflectionProperty(ContentExtractorService::class, 'logger');
$loggerProp->setAccessible(true);
$loggerProp->setValue($service, new \Psr\Log\NullLogger());

$normalize = new ReflectionMethod(ContentExtractorService::class, 'normalizeImageCaptions');
$normalize->setAccessible(true);
$sanitize = new ReflectionMethod(ContentExtractorService::class, 'sanitizeHtml');
$sanitize->setAccessible(true);

$passed   = 0;
$failures = [];

$report = function (string $label, string $expected, string $actual) use (&$passed, &$failures): void {
	if ($actual === $expected) {
		$passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
		return;
	}
	$failures[] = $label;
	echo "  \033[31m✗ " . $label . "\033[0m\n";
	echo '      erwartet: "' . $expected . "\"\n";
	echo '      erhalten: "' . $actual . "\"\n";
};

/**
 * Wendet eine <caption>-Regel auf $html an und prüft den Inhalt der ersten
 * erzeugten <figcaption> (HTML-Entities aufgelöst).
 */
$check = function (string $label, string $ruleAttributes, string $html, string $expected)
	use ($service, $contentFiltersProp, $normalize, $repoStubClass, $report): void {
	$config = new \SimpleXMLElement('<domain name="example.com"><images><caption ' . $ruleAttributes . ' /></images></domain>');
	$contentFiltersProp->setValue($service, new ($repoStubClass::class)($config));

	$out = (string) $normalize->invoke($service, '<html><body>' . $html . '</body></html>', 'example.com', null);
	$actual = preg_match('#<figcaption[^>]*>(.*?)</figcaption>#is', $out, $m) === 1
		? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
		: '(keine figcaption)';
	$report($label, $expected, $actual);
};

$zeitFigure = '<figure class="article__media"><picture><img src="https://img.zeit.de/a/bild/wide__1000x562" alt=""></picture>'
	. '<figcaption class="figure__caption"><span class="figure__text">Ein Bild.</span>'
	. '<span class="figure__copyright">© Agentur</span></figcaption></figure>';

echo "\n\033[1mnormalizeImageCaptions(): credits-xpath\033[0m\n";

$check(
	'Quelle aus credits-xpath steht als <cite> hinter " • "',
	'container-xpath="//figure" caption-xpath=".//span[@class=\'figure__text\']" credits-xpath=".//span[@class=\'figure__copyright\']"',
	$zeitFigure,
	'Ein Bild. • <cite>© Agentur</cite>'
);

$check(
	'Ohne credits-xpath unverändert: nur der Caption-Text',
	'container-xpath="//figure" caption-xpath=".//span[@class=\'figure__text\']"',
	$zeitFigure,
	'Ein Bild.'
);

$check(
	'Nur Quelle, kein Caption-Text: <figcaption> enthält allein das <cite>',
	'container-xpath="//figure" caption-xpath=".//span[@class=\'fehlt\']" credits-xpath=".//span[@class=\'figure__copyright\']"',
	$zeitFigure,
	'<cite>© Agentur</cite>'
);

$check(
	'caption-xpath trifft die Quelle schon mit: nicht doppelt angehängt',
	'container-xpath="//figure" caption-xpath=".//figcaption" credits-xpath=".//span[@class=\'figure__copyright\']"',
	$zeitFigure,
	'Ein Bild. • <cite>© Agentur</cite>'
);

$check(
	'Ungültiger credits-xpath kostet nur die Quelle, nicht die Caption',
	'container-xpath="//figure" caption-xpath=".//span[@class=\'figure__text\']" credits-xpath=".//span[@class="',
	$zeitFigure,
	'Ein Bild.'
);

$check(
	'Mehrere Treffer (Fotograf + Agentur) werden zusammengefügt',
	'container-xpath="//figure" caption-xpath=".//span[@class=\'t\']" credits-xpath=".//span[@class=\'c\']"',
	'<figure><img src="https://x/y.jpg" alt=""><span class="t">Text</span><span class="c">Foto: Anna</span><span class="c">/ dpa</span></figure>',
	'Text • <cite>Foto: Anna / dpa</cite>'
);

echo "\n\033[1mnormalizeImageCaptions(): <img> ohne class/id\033[0m\n";

$contentFiltersProp->setValue($service, new ($repoStubClass::class)(new \SimpleXMLElement(
	'<domain name="example.com"><images><caption container-xpath="//div[@class=\'c\']" caption-xpath=".//span" /></images></domain>'
)));
$out = (string) $normalize->invoke($service, '<html><body><div class="c"><picture><img class="header-fullwidth__media-item" id="hero" src="https://x/y.jpg" alt=""></picture><span>Text</span></div></body></html>', 'example.com', null);
$report(
	'zeit.de-Regression: class "header-fullwidth__media-item" (Readability: unlikelyCandidate "header") wird nicht übernommen',
	'<img src="https://x/y.jpg" alt="">',
	preg_match('#<img[^>]*>#', $out, $m) === 1 ? $m[0] : '(kein img)'
);

echo "\n\033[1mHero-Pfad: Caption-Text \"… • Quelle\" wird wieder zu <cite>\033[0m\n";

$out = (string) $sanitize->invoke($service, '<figure class="merlin-hero-image"><img src="https://x/y.jpg" alt=""><figcaption>Ein Bild. • © Agentur</figcaption></figure>');
$report(
	'Hero-<figcaption> aus Text: Quelle hinter dem letzten " • " wird <cite>, der Trenner entfällt, der Punkt bleibt',
	'Ein Bild. <cite>© Agentur</cite>',
	preg_match('#<figcaption[^>]*>(.*?)</figcaption>#is', $out, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : $out
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
