<?php

declare(strict_types=1);

/**
 * Testharness für das Content-Filter-Merge-System.
 *
 * Aufruf (auf dem Server, im App-Verzeichnis):
 *   php tools/test-content-filter-merge.php
 *
 * Bewusst ohne Composer-Autoload, PHPUnit oder Nextcloud-Bootstrap: das Skript
 * soll per SSH auf einem NAS laufen, wo nur die PHP-CLI der Nextcloud-Instanz
 * vorhanden ist. Die beiden externen Abhängigkeiten der geprüften Klassen
 * (Psr\Log\LoggerInterface, OCP\IConfig) werden unten als Stubs deklariert.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

// ══════════════════════════════════════════════════════════════════════════════
// Stubs für die Framework-Abhängigkeiten
// ══════════════════════════════════════════════════════════════════════════════

namespace Psr\Log {

	if (!interface_exists(LoggerInterface::class)) {
		interface LoggerInterface {
			public function emergency($message, array $context = []);
			public function alert($message, array $context = []);
			public function critical($message, array $context = []);
			public function error($message, array $context = []);
			public function warning($message, array $context = []);
			public function notice($message, array $context = []);
			public function info($message, array $context = []);
			public function debug($message, array $context = []);
			public function log($level, $message, array $context = []);
		}
	}
}

namespace Merlin\FilterTest {

	use Psr\Log\LoggerInterface;

	/** Sammelt Logmeldungen, damit Tests prüfen können, ob gewarnt wurde. */
	final class CollectingLogger implements LoggerInterface {
		/** @var list<string> */
		public array $messages = [];

		public function emergency($message, array $context = []) { $this->messages[] = (string) $message; }
		public function alert($message, array $context = []) { $this->messages[] = (string) $message; }
		public function critical($message, array $context = []) { $this->messages[] = (string) $message; }
		public function error($message, array $context = []) { $this->messages[] = (string) $message; }
		public function warning($message, array $context = []) { $this->messages[] = (string) $message; }
		public function notice($message, array $context = []) { $this->messages[] = (string) $message; }
		public function info($message, array $context = []) { $this->messages[] = (string) $message; }
		public function debug($message, array $context = []) { $this->messages[] = (string) $message; }
		public function log($level, $message, array $context = []) { $this->messages[] = (string) $message; }
	}

	final class TestRunner {
		private int $passed = 0;
		/** @var list<string> */
		private array $failures = [];
		private string $group = '';

		public function group(string $name): void {
			$this->group = $name;
			echo "\n\033[1m" . $name . "\033[0m\n";
		}

		public function ok(bool $condition, string $label, string $detail = ''): void {
			if ($condition) {
				$this->passed++;
				echo "  \033[32m✓\033[0m " . $label . "\n";
				return;
			}
			$this->failures[] = $this->group . ' → ' . $label . ($detail === '' ? '' : ' | ' . $detail);
			echo "  \033[31m✗ " . $label . "\033[0m\n";
			if ($detail !== '') {
				foreach (explode("\n", $detail) as $line) {
					echo "      " . $line . "\n";
				}
			}
		}

		public function eq(mixed $actual, mixed $expected, string $label): void {
			$this->ok(
				$actual === $expected,
				$label,
				$actual === $expected ? '' : 'erwartet: ' . $this->dump($expected) . "\nerhalten: " . $this->dump($actual)
			);
		}

		public function throws(callable $fn, string $label, string $needle = ''): void {
			try {
				$fn();
			} catch (\Throwable $e) {
				$this->ok(
					$needle === '' || stripos($e->getMessage(), $needle) !== false,
					$label,
					$needle === '' || stripos($e->getMessage(), $needle) !== false
						? ''
						: 'Meldung enthielt nicht "' . $needle . '": ' . $e->getMessage()
				);
				return;
			}
			$this->ok(false, $label, 'Es wurde keine Exception geworfen.');
		}

		private function dump(mixed $value): string {
			if (is_string($value)) {
				return '"' . $value . '"';
			}
			return var_export($value, true);
		}

		public function summary(): int {
			$total = $this->passed + count($this->failures);
			echo "\n" . str_repeat('─', 72) . "\n";
			if ($this->failures === []) {
				echo "\033[32mAlle " . $total . " Prüfungen bestanden.\033[0m\n";
				return 0;
			}
			echo "\033[31m" . count($this->failures) . ' von ' . $total . " Prüfungen fehlgeschlagen:\033[0m\n";
			foreach ($this->failures as $failure) {
				echo '  · ' . $failure . "\n";
			}
			return 1;
		}
	}
}

// ══════════════════════════════════════════════════════════════════════════════
// Tests
// ══════════════════════════════════════════════════════════════════════════════

namespace {

	use Merlin\FilterTest\CollectingLogger;
	use Merlin\FilterTest\TestRunner;
	use OCA\Merlin\Service\ContentFilterMerger;
	use OCA\Merlin\Service\ContentFilterSchema;
	use OCA\Merlin\Service\ContentFilterSerializer;
	use OCA\Merlin\Service\ContentFilterValidator;

	require_once __DIR__ . '/../lib/Service/ContentFilterSchema.php';
	require_once __DIR__ . '/../lib/Service/ContentFilterTrace.php';
	require_once __DIR__ . '/../lib/Service/ContentFilterMerger.php';
	require_once __DIR__ . '/../lib/Service/ContentFilterValidator.php';
	require_once __DIR__ . '/../lib/Service/ContentFilterSerializer.php';

	// ContentFilterRepository wird hier bewusst NICHT mehr geladen/getestet: seit
	// der DB-Umstellung (tasks/content-filter-db-scopes-todo.md) braucht ihr
	// Konstruktor eine echte IDBConnection statt der bisherigen IConfig-
	// Dateipfad-Abhängigkeit. Ein Fake-QueryBuilder-Stub dafür wäre deutlich
	// aufwändiger als die bisherigen IConfig/LoggerInterface-Stubs und wurde für
	// diese Runde bewusst zurückgestellt (mit dem Nutzer abgestimmt) – die
	// Repository-Tests, die vorher hier standen (Domain-Validierung, Pfade/
	// Schreiben, Liste/Merge-Cache), sind ersatzlos entfernt. Was bleibt und
	// weiterhin ohne Nextcloud/Composer läuft, ist die reine Merge-/Validierungs-
	// /Serialisierungslogik – inklusive der neuen Dreiebenen-Verkettung (Gruppe 14
	// unten), die ausschliesslich ContentFilterMerger nutzt und daher von der
	// Repository-DB-Frage unberührt ist.

	$t         = new TestRunner();
	$logger    = new CollectingLogger();
	$merger    = new ContentFilterMerger($logger);
	$validator = new ContentFilterValidator();
	$serializer = new ContentFilterSerializer();

	// ── Helfer ────────────────────────────────────────────────────────────────

	/** Baut eine Filterdatei aus Sektions-Schnipseln. */
	$doc = static function (string $domain, string $body): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<domain name="' . $domain . '">' . $body . '</domain>';
	};

	/** Kanonische Form ohne Kommentare und ohne das interne Herkunftsattribut. */
	$canonical = static function (string $xml): string {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		$dom->loadXML($xml, LIBXML_NONET);
		$xp = new DOMXPath($dom);
		foreach ($xp->query('//@' . ContentFilterSchema::ORIGIN_ATTRIBUTE) as $attr) {
			/** @var DOMAttr $attr */
			$attr->ownerElement?->removeAttributeNode($attr);
		}
		return (string) $dom->C14N(false, false);
	};

	/** Attributwerte aller Treffer eines XPath, in Dokumentreihenfolge. */
	$values = static function (?SimpleXMLElement $xml, string $xpath): array {
		if ($xml === null) {
			return [];
		}
		$out = [];
		foreach (($xml->xpath($xpath) ?: []) as $node) {
			$out[] = (string) $node;
		}
		return $out;
	};

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('1. Regression: Merge ohne Custom-Filter lässt das Bundle unverändert');

	$bundleDir = realpath(__DIR__ . '/../content-filters');
	$t->ok($bundleDir !== false, 'Bundle-Ordner gefunden', 'Pfad: ' . __DIR__ . '/../content-filters');

	$checked = 0;
	$drift   = [];
	foreach (glob(($bundleDir ?: '.') . '/*.xml') ?: [] as $path) {
		$name = basename($path, '.xml');
		// 000-Präfix = keine Domain-Config (Referenzdatei, Parkliste toter Domains).
		if (str_starts_with($name, '000')) {
			continue;
		}
		$raw = (string) file_get_contents($path);
		try {
			$mergedXml = $merger->merge($raw, null, $name)?->asXML();
		} catch (Throwable $e) {
			$drift[] = $name . ': ' . $e->getMessage();
			continue;
		}
		$checked++;
		if ($mergedXml === false || $mergedXml === null) {
			$drift[] = $name . ': Merge lieferte kein XML';
			continue;
		}
		if ($canonical($mergedXml) !== $canonical($raw)) {
			$drift[] = $name;
		}
	}
	$t->ok($checked > 50, 'Alle mitgelieferten Filter geprüft', 'geprüft: ' . $checked);
	$t->ok($drift === [], 'Kein Filter verändert sich durch den Merge', implode("\n", $drift));

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('2. Listen-Sektionen werden additiv zusammengeführt');

	$bundle = $doc('example.com', '
		<pre-filter>
			<remove class="bundle-a" />
			<remove class="bundle-b" />
			<infobox id="box" />
			<saveElements xpath="//aside" class="merlin-sidebar" />
		</pre-filter>
		<post-filter><remove class="bundle-post" /></post-filter>
		<quotes><quote container-xpath="//div[@class=\'bq\']" /></quotes>
		<images><caption container-xpath="//figure" caption-xpath=".//span" /></images>
	');
	$custom = $doc('example.com', '
		<pre-filter><remove class="custom-a" /></pre-filter>
		<post-filter><remove class="custom-post" /></post-filter>
		<quotes><quote container-xpath="//div[@class=\'cq\']" text-xpath=".//p" /></quotes>
		<images><caption container-xpath="//div[@class=\'img\']" caption-xpath=".//em" /></images>
	');
	$merged = $merger->merge($bundle, $custom, 'example.com');

	$t->eq(
		$values($merged, 'pre-filter/remove/@class'),
		['bundle-a', 'bundle-b', 'custom-a'],
		'pre-filter: Custom-Regel steht hinter den Bundle-Regeln'
	);
	$t->eq(
		$values($merged, 'post-filter/remove/@class'),
		['bundle-post', 'custom-post'],
		'post-filter additiv'
	);
	$t->eq(count($merged?->xpath('quotes/quote') ?: []), 2, 'quotes additiv');
	$t->eq(count($merged?->xpath('images/caption') ?: []), 2, 'images additiv');
	$t->eq(
		$values($merged, 'pre-filter/infobox/@id'),
		['box'],
		'infobox aus dem Bundle bleibt erhalten'
	);
	$t->eq(
		$values($merged, 'pre-filter/saveElements/@class'),
		['merlin-sidebar'],
		'saveElements aus dem Bundle bleibt erhalten'
	);
	$t->eq(
		$values($merged, 'pre-filter/remove[@class="custom-a"]/@' . ContentFilterSchema::ORIGIN_ATTRIBUTE),
		[ContentFilterSchema::ORIGIN_ADMIN],
		'Custom-Regel trägt standardmässig die Herkunft "admin"'
	);
	$t->eq(
		$values($merged, 'pre-filter/remove[@class="bundle-a"]/@' . ContentFilterSchema::ORIGIN_ATTRIBUTE),
		[ContentFilterSchema::ORIGIN_BUNDLE],
		'Bundle-Regel trägt die Herkunft "bundle"'
	);

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('3. Schlüssel-Sektionen: fetch/@name und json/@id ersetzen statt zu ergänzen');

	$bundle = $doc('example.com', '
		<fetch>
			<header name="Cookie" value="bundle=1" />
			<header name="User-Agent" value="BundleAgent" />
		</fetch>
		<json id="ld" xpath="//script[@type=\'application/ld+json\']" index="0" />
	');
	$custom = $doc('example.com', '
		<fetch><header name="Cookie" value="custom=2" /></fetch>
		<json id="ld" xpath="//script[@id=\'other\']" />
		<json id="next" xpath="//script[@id=\'__NEXT_DATA__\']" />
	');
	$merged = $merger->merge($bundle, $custom, 'example.com');

	$t->eq(count($merged?->xpath('fetch/header') ?: []), 2, 'fetch: kein doppelter Cookie-Header');
	$t->eq(
		$values($merged, 'fetch/header[@name="Cookie"]/@value'),
		['custom=2'],
		'fetch: Custom-Cookie ersetzt den Bundle-Cookie'
	);
	$t->eq(
		$values($merged, 'fetch/header[@name="User-Agent"]/@value'),
		['BundleAgent'],
		'fetch: nicht überschriebener Bundle-Header bleibt'
	);
	$t->eq(count($merged?->xpath('json') ?: []), 2, 'json: id "ld" ersetzt, "next" ergänzt');
	$t->eq(
		$values($merged, 'json[@id="ld"]/@xpath'),
		["//script[@id='other']"],
		'json: Custom-Quelle mit gleicher id gewinnt'
	);

	// HTTP-Header-Namen sind case-insensitiv (RFC 9110): "cookie" muss "Cookie"
	// ersetzen, nicht danebenstehen.
	$merged = $merger->merge(
		$doc('example.com', '<fetch><header name="Cookie" value="bundle=1" /></fetch>'),
		$doc('example.com', '<fetch><header name="cookie" value="custom=2" /></fetch>'),
		'example.com'
	);
	$t->eq(count($merged?->xpath('fetch/header') ?: []), 1, 'fetch: abweichende Schreibweise erzeugt keinen Doppel-Header');
	$t->eq(
		$values($merged, 'fetch/header/@value'),
		['custom=2'],
		'fetch: Schlüsselvergleich ist case-insensitiv'
	);
	$t->ok(
		$validator->validate(
			$doc('a.example', '<fetch><header name="Cookie" value="a" /><header name="cookie" value="b" /></fetch>'),
			'a.example'
		) !== [],
		'Validator erkennt Cookie/cookie als denselben Header'
	);

	// Gleiches gilt beim Abschalten einer Bundle-Regel.
	$merged = $merger->merge(
		$doc('example.com', '<fetch><header name="Cookie" value="bundle=1" /></fetch>'),
		$doc('example.com', '<disable><fetch><header name="cookie" /></fetch></disable>'),
		'example.com'
	);
	$t->eq(count($merged?->xpath('fetch/header') ?: []), 0, 'disable: Header-Name case-insensitiv abschaltbar');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('4. Einzelwert-Felder: Custom gewinnt, sonst Bundle');

	$bundle = $doc('example.com', '
		<metadata>
			<title xpath="//h1" />
			<author xpath="//span[@class=\'bundle-author\']" />
			<published xpath="//time/@datetime" />
		</metadata>
		<category>Nachrichten</category>
	');
	$custom = $doc('example.com', '
		<metadata>
			<author json="ld:$.author[0].name" />
		</metadata>
		<category>Technik</category>
	');
	$merged = $merger->merge($bundle, $custom, 'example.com');

	$t->eq($values($merged, 'metadata/title/@xpath'), ['//h1'], 'metadata: nicht gesetztes Feld fällt aufs Bundle zurück');
	$t->eq($values($merged, 'metadata/author/@json'), ['ld:$.author[0].name'], 'metadata: Custom-Feld gewinnt');
	$t->eq(
		$values($merged, 'metadata/author/@xpath'),
		[],
		'metadata: Bundle-XPath bleibt NICHT als stille Fallback-Kette stehen'
	);
	$t->eq(count($merged?->xpath('metadata/author') ?: []), 1, 'metadata: Feld existiert nur einmal');
	$t->eq(trim((string) ($merged->category ?? '')), 'Technik', 'category: Custom gewinnt');
	$t->eq(count($merged?->xpath('category') ?: []), 1, 'category: existiert nur einmal');

	// Fallback-Ketten: taz.de hat zwei <author>-Regeln für verschiedene Layouts.
	// Der Extractor probiert sie in Dokumentreihenfolge durch, deshalb müssen
	// mehrere Regeln gleichen Namens erlaubt sein und beim Merge als GANZE Kette
	// ersetzt werden.
	$bundle = $doc('example.com', '
		<metadata>
			<author xpath="//span[@class=\'a1\']" />
			<author xpath="//span[@class=\'a2\']" />
			<title xpath="//h1" />
		</metadata>
	');
	$merged = $merger->merge($bundle, null, 'example.com');
	$t->eq(
		$values($merged, 'metadata/author/@xpath'),
		["//span[@class='a1']", "//span[@class='a2']"],
		'metadata: Bundle-Fallback-Kette bleibt in Reihenfolge erhalten'
	);

	$custom = $doc('example.com', '
		<metadata>
			<author xpath="//span[@class=\'c1\']" />
			<author xpath="//span[@class=\'c2\']" />
		</metadata>
	');
	$merged = $merger->merge($bundle, $custom, 'example.com');
	$t->eq(
		$values($merged, 'metadata/author/@xpath'),
		["//span[@class='c1']", "//span[@class='c2']"],
		'metadata: Custom-Kette ersetzt die Bundle-Kette komplett (nicht einzelne Einträge)'
	);
	$t->eq($values($merged, 'metadata/title/@xpath'), ['//h1'], 'metadata: anderes Feld bleibt beim Bundle');

	$tazPath = ($bundleDir ?: '.') . '/taz.de.xml';
	if (is_file($tazPath)) {
		$taz = (string) file_get_contents($tazPath);
		$t->eq($validator->validate($taz, 'taz.de'), [], 'taz.de mit zwei <author>-Regeln ist gültig');
		$t->eq(
			count($merger->merge($taz, null, 'taz.de')?->xpath('metadata/author') ?: []),
			2,
			'taz.de behält beide <author>-Regeln'
		);
	} else {
		$t->ok(false, 'taz.de.xml gefunden');
	}

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('5. <disable>: einzelne Bundle-Regeln abschalten');

	$bundle = $doc('example.com', '
		<pre-filter>
			<remove class="keep" />
			<remove class="drop" />
			<remove id="drop-by-id" />
		</pre-filter>
		<post-filter><remove class="drop" /></post-filter>
		<metadata><author xpath="//span[@class=\'broken\']" /><title xpath="//h1" /></metadata>
		<json id="ld" xpath="//script" />
	');
	$custom = $doc('example.com', '
		<disable>
			<pre-filter>
				<remove class="drop" />
				<remove id="drop-by-id" />
			</pre-filter>
			<metadata><author /></metadata>
			<json id="ld" />
		</disable>
	');
	$merged = $merger->merge($bundle, $custom, 'example.com');

	$t->eq($values($merged, 'pre-filter/remove/@class'), ['keep'], 'disable trifft genau die benannte Regel');
	$t->eq($values($merged, 'pre-filter/remove/@id'), [], 'disable per id funktioniert');
	$t->eq(
		$values($merged, 'post-filter/remove/@class'),
		['drop'],
		'disable in pre-filter lässt die gleichnamige post-filter-Regel unberührt'
	);
	$t->eq($values($merged, 'metadata/author/@xpath'), [], 'attributloses <author/> schaltet das Bundle-Feld ab');
	$t->eq($values($merged, 'metadata/title/@xpath'), ['//h1'], 'disable eines Feldes lässt andere Felder stehen');
	$t->eq(count($merged?->xpath('json') ?: []), 0, 'disable per json/@id funktioniert');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('6. <disable section="…">: ganze Sektion verwerfen');

	$bundle = $doc('example.com', '
		<pre-filter><remove class="a" /><remove class="b" /></pre-filter>
		<metadata><title xpath="//h1" /></metadata>
	');
	$custom = $doc('example.com', '
		<disable section="pre-filter" />
		<pre-filter><remove class="own" /></pre-filter>
	');
	$merged = $merger->merge($bundle, $custom, 'example.com');

	$t->eq(
		$values($merged, 'pre-filter/remove/@class'),
		['own'],
		'section-disable + eigene Regeln = vollständiger Ersatz der Sektion'
	);
	$t->eq($values($merged, 'metadata/title/@xpath'), ['//h1'], 'andere Sektionen bleiben unberührt');

	$custom = $doc('example.com', '<disable section="metadata" />');
	$merged = $merger->merge($bundle, $custom, 'example.com');
	$t->eq(count($merged?->xpath('metadata') ?: []), 0, 'metadata-Sektion komplett entfernbar');

	$custom = $doc('example.com', '<disable section="nonsense" />');
	$logger->messages = [];
	$merged = $merger->merge($bundle, $custom, 'example.com');
	$t->eq(count($merged?->xpath('pre-filter/remove') ?: []), 2, 'unbekannte Sektion in disable ändert nichts');
	$t->ok($logger->messages !== [], 'unbekannte Sektion in disable wird protokolliert');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('7. Interne Elemente erreichen den Extractor nicht');

	$custom = $doc('example.com', '
		<note>Interne Notiz</note>
		<disable section="metadata" />
		<pre-filter><remove class="x" /></pre-filter>
	');
	$merged = $merger->merge($doc('example.com', '<metadata><title xpath="//h1" /></metadata>'), $custom, 'example.com');

	$t->eq(count($merged?->xpath('note') ?: []), 0, '<note> ist im Ergebnis nicht vorhanden');
	$t->eq(count($merged?->xpath('disable') ?: []), 0, '<disable> ist im Ergebnis nicht vorhanden');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('8. Custom-Filter für eine Domain ohne Bundle');

	$merged = $merger->merge(null, $doc('neu.example', '<pre-filter><remove class="a" /></pre-filter>'), 'neu.example');
	$t->eq((string) ($merged['name'] ?? ''), 'neu.example', 'Wurzelattribut name wird gesetzt');
	$t->eq($values($merged, 'pre-filter/remove/@class'), ['a'], 'Custom-Regeln greifen auch ohne Bundle');
	$t->eq($merger->merge(null, null, 'nichts.example'), null, 'ohne beide Quellen: null');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('9. Merger lehnt gefährliches XML ab');

	$t->throws(
		static fn() => $merger->merge(null, '<!DOCTYPE domain [<!ENTITY x "y">]><domain name="a.example" />', 'a.example'),
		'DOCTYPE wird abgelehnt',
		'DOCTYPE'
	);
	$t->throws(
		static fn() => $merger->merge(null, '<domain name="a.example"><pre-filter></domain>', 'a.example'),
		'Nicht wohlgeformtes XML wird abgelehnt',
		'wohlgeformt'
	);
	$t->throws(
		static fn() => $merger->merge(null, '<filter name="a.example" />', 'a.example'),
		'Falsches Wurzelelement wird abgelehnt',
		'Wurzelelement'
	);

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('10. Validator: gültige Datei');

	$valid = $doc('example.com', '
		<note>Notiz mit & und < im Text</note>
		<disable section="post-filter" />
		<disable><pre-filter><remove class="x" /></pre-filter></disable>
		<fetch><header name="Cookie" value="consent=1" /></fetch>
		<pre-filter>
			<remove id="banner" />
			<remove class="ad" />
			<remove xpath="//div[@data-ad]" />
			<infobox xpath="//aside[contains(@class,\'facts\')]" />
			<saveElements xpath="//div[contains(@class,\'box\')]" class="merlin-facts" />
		</pre-filter>
		<images><caption container-xpath="//figure" caption-xpath=".//figcaption" /></images>
		<quotes><quote container-xpath="//div[contains(@class,\'q\')]" text-xpath=".//p" author-xpath=".//cite" /></quotes>
		<post-filter><remove class="footer-links" /></post-filter>
		<json id="ld" xpath="//script[@type=\'application/ld+json\']" index="1" />
		<metadata>
			<title xpath="//h1" />
			<author json="ld:$.author[0].name" />
			<published xpath="//time/@datetime" />
		</metadata>
		<category>Nachrichten</category>
	');
	// Der Notiztext oben ist absichtlich unescaped-verdächtig – hier korrekt escapen.
	$valid = str_replace('Notiz mit & und < im Text', 'Notiz mit &amp; und &lt; im Text', $valid);

	$errors = $validator->validate($valid, 'example.com');
	$t->eq($errors, [], 'Vollständige, korrekte Datei erzeugt keine Fehler');

	$sample = @file_get_contents(($bundleDir ?: '.') . '/000.sample.com.xml');
	if (is_string($sample)) {
		$t->eq($validator->validate($sample, 'sample.com'), [], 'Die Referenzdatei 000.sample.com.xml ist gültig');
	} else {
		$t->ok(false, 'Referenzdatei 000.sample.com.xml gefunden');
	}

	$bundleErrors = [];
	foreach (glob(($bundleDir ?: '.') . '/*.xml') ?: [] as $path) {
		$name = basename($path, '.xml');
		if (str_starts_with($name, '000')) {
			continue;
		}
		// <login> ist bewusst NICHT Teil von ContentFilterSchema::SECTIONS und
		// läuft nie durch ContentFilterValidator (siehe Service\Login\LoginConfig-
		// Docblock: Admin/User dürfen nie einen Login-Endpoint definieren, nur
		// Bundle-Dateien). Für diesen Schema-Test daher per DOM entfernt (nicht
		// per Regex - ein <login> könnte auch nur in einem Kommentartext
		// erwähnt sein, siehe tagesspiegel.de.xml), eigene Prüfung läuft über
		// LoginConfig::isValid() (siehe SiteCredentialService).
		$rawXml = (string) file_get_contents($path);
		$prevXml = libxml_use_internal_errors(true);
		$domForStripping = new DOMDocument('1.0', 'UTF-8');
		$xml = $rawXml;
		if ($domForStripping->loadXML($rawXml, LIBXML_NONET | LIBXML_NOENT)) {
			foreach (iterator_to_array($domForStripping->getElementsByTagName('login')) as $loginNode) {
				$loginNode->parentNode?->removeChild($loginNode);
			}
			$xml = $domForStripping->saveXML() ?: $rawXml;
		}
		libxml_clear_errors();
		libxml_use_internal_errors($prevXml);

		$found = $validator->validate($xml, $name);
		if ($found !== []) {
			$bundleErrors[] = $name . ': ' . $found[0]['message'];
		}
	}
	$t->ok(
		$bundleErrors === [],
		'Alle mitgelieferten Filter erfüllen das Schema',
		implode("\n", array_slice($bundleErrors, 0, 20))
	);

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('11. Validator: fehlerhafte Dateien werden abgelehnt');

	/** Erste Fehlermeldung oder '' */
	$firstError = static function (array $errors): string {
		return $errors[0]['message'] ?? '';
	};

	$cases = [
		'DOCTYPE'                => ['<!DOCTYPE domain><domain name="a.example" />', 'a.example', 'DOCTYPE'],
		'nicht wohlgeformt'      => ['<domain name="a.example"><pre-filter></domain>', 'a.example', 'XML'],
		'falsches Wurzelelement' => ['<filter name="a.example" />', 'a.example', 'Wurzelelement'],
		'name-Attribut fehlt'    => ['<domain />', 'a.example', 'name'],
		'name passt nicht'       => ['<domain name="b.example" />', 'a.example', 'passt nicht'],
		'unbekannte Sektion'     => [$doc('a.example', '<vorfilter><remove class="x" /></vorfilter>'), 'a.example', 'Unbekannte Sektion'],
		'unbekanntes Element'    => [$doc('a.example', '<pre-filter><entferne class="x" /></pre-filter>'), 'a.example', 'nicht erlaubt'],
		'unbekanntes Attribut'   => [$doc('a.example', '<pre-filter><remove selector="x" /></pre-filter>'), 'a.example', 'nicht erlaubt'],
		'oneOf fehlt'            => [$doc('a.example', '<pre-filter><remove /></pre-filter>'), 'a.example', 'genau eines'],
		'oneOf mehrfach'         => [$doc('a.example', '<pre-filter><remove id="a" class="b" /></pre-filter>'), 'a.example', 'mehrere'],
		'Pflichtattribut fehlt'  => [$doc('a.example', '<pre-filter><saveElements xpath="//div" /></pre-filter>'), 'a.example', 'braucht das Attribut'],
		'ungültiger XPath'       => [$doc('a.example', '<pre-filter><remove xpath="//div[" /></pre-filter>'), 'a.example', 'XPath'],
		'verbotener Header'      => [$doc('a.example', '<fetch><header name="Authorization" value="x" /></fetch>'), 'a.example', 'nicht erlaubt'],
		'internes Attribut'      => [$doc('a.example', '<pre-filter><remove class="x" ' . ContentFilterSchema::ORIGIN_ATTRIBUTE . '="bundle" /></pre-filter>'), 'a.example', 'intern'],
		'ungültiger JSON-Pfad'   => [$doc('a.example', '<metadata><author json="author.name" /></metadata>'), 'a.example', 'JSON-Pfad'],
		'index nicht numerisch'  => [$doc('a.example', '<json id="ld" xpath="//script" index="erste" />'), 'a.example', 'index'],
		'Sektion doppelt'        => [$doc('a.example', '<pre-filter><remove class="a" /></pre-filter><pre-filter><remove class="b" /></pre-filter>'), 'a.example', 'mehrfach'],
		'fetch-Header doppelt'   => [$doc('a.example', '<fetch><header name="Cookie" value="a" /><header name="Cookie" value="b" /></fetch>'), 'a.example', 'mehrfach'],
		'json-id doppelt'        => [$doc('a.example', '<json id="ld" xpath="//a" /><json id="ld" xpath="//b" />'), 'a.example', 'mehrfach'],
		'disable leer'           => [$doc('a.example', '<disable />'), 'a.example', 'leer'],
		'disable beides'         => [$doc('a.example', '<disable section="pre-filter"><pre-filter><remove class="x" /></pre-filter></disable>'), 'a.example', 'entweder'],
		'disable unbekannt'      => [$doc('a.example', '<disable section="quatsch" />'), 'a.example', 'kann nicht deaktiviert'],
		'disable mit category'   => [$doc('a.example', '<disable><category /></disable>'), 'a.example', 'nicht erlaubt'],
		'Wrapper mit Attribut'   => [$doc('a.example', '<pre-filter mode="replace"><remove class="x" /></pre-filter>'), 'a.example', 'nicht erlaubt'],
		'category mit Kind'      => [$doc('a.example', '<category><name>x</name></category>'), 'a.example', 'nur Text'],
		'Attribut am Wurzelelement' => ['<domain name="a.example" mode="replace" />', 'a.example', 'nicht erlaubt'],
		'leeres oneOf-Attribut'  => [$doc('a.example', '<pre-filter><remove id="" class="ads" /></pre-filter>'), 'a.example', 'leer'],
		'leeres Pflichtattribut' => [$doc('a.example', '<pre-filter><saveElements xpath="//div" class="" /></pre-filter>'), 'a.example', 'leer'],
		'Textinhalt in Regel'    => [$doc('a.example', '<pre-filter><remove class="x">Tippfehler</remove></pre-filter>'), 'a.example', 'Textinhalt'],
	];

	foreach ($cases as $label => [$xml, $domain, $needle]) {
		$found = $validator->validate($xml, $domain);
		$t->ok(
			$found !== [] && stripos($firstError($found) . ' ' . implode(' ', array_column($found, 'message')), $needle) !== false,
			'abgelehnt: ' . $label,
			$found === []
				? 'Es wurde kein Fehler gemeldet.'
				: 'Meldungen: ' . implode(' | ', array_column($found, 'message'))
		);
	}

	$t->ok(
		$validator->validate(str_repeat(' ', ContentFilterSchema::MAX_FILE_BYTES + 1), 'a.example') !== [],
		'abgelehnt: Datei über dem Grössenlimit'
	);
	$t->ok($validator->validate('', 'a.example') !== [], 'abgelehnt: leere Datei');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('12. Validator: JSON-Pfade');

	$jsonOk = ['$', '$.author', '$.author.name', '$.author[0].name', '$[0].key', 'ld:$.author[0].name', '$.@type', 'next:$.pageProps.article.author'];
	foreach ($jsonOk as $path) {
		$xml   = $doc('a.example', '<metadata><author json="' . str_replace(['&', '<', '"'], ['&amp;', '&lt;', '&quot;'], $path) . '" /></metadata>');
		$found = $validator->validate($xml, 'a.example');
		$t->eq($found, [], 'akzeptiert: json="' . $path . '"');
	}
	$jsonBad = ['author.name', '.author', '$..author', '$.author..name', ':$.a'];
	foreach ($jsonBad as $path) {
		$xml   = $doc('a.example', '<metadata><author json="' . $path . '" /></metadata>');
		$found = $validator->validate($xml, 'a.example');
		$t->ok($found !== [], 'abgelehnt: json="' . $path . '"');
	}

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('13. Serializer: Round-trip JSON → XML → JSON');

	$builderData = [
		'domain'  => 'example.com',
		'note'    => 'Notiz mit & und <spitzen> Klammern',
		'disable' => [
			'sections' => ['post-filter'],
			'rules'    => [
				'pre-filter' => [['element' => 'remove', 'attributes' => ['class' => 'supplementary']]],
				'metadata'   => [['element' => 'author', 'attributes' => []]],
				'json'       => [['element' => 'json', 'attributes' => ['id' => 'ld']]],
			],
		],
		'fetch'       => [['element' => 'header', 'attributes' => ['name' => 'Cookie', 'value' => 'consent=1']]],
		'pre-filter'  => [
			['element' => 'remove', 'attributes' => ['class' => 'ad']],
			['element' => 'saveElements', 'attributes' => ['xpath' => '//aside', 'class' => 'merlin-sidebar']],
		],
		'images'      => [['element' => 'caption', 'attributes' => ['container-xpath' => '//figure', 'caption-xpath' => './/figcaption']]],
		'quotes'      => [['element' => 'quote', 'attributes' => ['container-xpath' => '//blockquote']]],
		'post-filter' => [['element' => 'remove', 'attributes' => ['class' => 'hint']]],
		'json'        => [['element' => 'json', 'attributes' => ['id' => 'next', 'xpath' => '//script[@id="__NEXT_DATA__"]']]],
		'metadata'    => [['element' => 'author', 'attributes' => ['xpath' => '//a[@rel="author"]']]],
		'category'    => 'Technik',
	];

	$xml = $serializer->toXml($builderData, 'example.com');
	$t->eq($validator->validate($xml, 'example.com'), [], 'Erzeugtes XML ist schemakonform');

	$back = $serializer->toArray($xml);
	$t->eq($back['domain'], 'example.com', 'Round-trip: domain');
	$t->eq($back['note'] ?? '', $builderData['note'], 'Round-trip: note inkl. Sonderzeichen');
	$t->eq($back['category'] ?? '', 'Technik', 'Round-trip: category');
	$t->eq($back['pre-filter'] ?? [], $builderData['pre-filter'], 'Round-trip: pre-filter');
	$t->eq($back['fetch'] ?? [], $builderData['fetch'], 'Round-trip: fetch');
	$t->eq($back['images'] ?? [], $builderData['images'], 'Round-trip: images');
	$t->eq($back['quotes'] ?? [], $builderData['quotes'], 'Round-trip: quotes');
	$t->eq($back['post-filter'] ?? [], $builderData['post-filter'], 'Round-trip: post-filter');
	$t->eq($back['json'] ?? [], $builderData['json'], 'Round-trip: json');
	$t->eq($back['metadata'] ?? [], $builderData['metadata'], 'Round-trip: metadata');
	$t->eq($back['disable']['sections'] ?? [], ['post-filter'], 'Round-trip: disable/sections');
	$t->eq($back['disable']['rules'] ?? [], $builderData['disable']['rules'], 'Round-trip: disable/rules');

	$t->eq($serializer->toXml($back, 'example.com'), $xml, 'Zweiter Durchlauf erzeugt identisches XML (stabil)');

	$t->throws(
		static fn() => $serializer->toXml(['pre-filter' => [['element' => 're move', 'attributes' => []]]], 'a.example'),
		'Ungültiger Elementname wird abgelehnt',
		'Ungültiger'
	);
	$t->throws(
		static fn() => $serializer->toArray('<!DOCTYPE domain><domain name="a.example" />'),
		'toArray lehnt DOCTYPE ab',
		'DOCTYPE'
	);

	// Herkunftsattribut darf nicht als echtes Attribut zurückkommen
	$withOrigin = $doc('a.example', '<pre-filter><remove class="x" ' . ContentFilterSchema::ORIGIN_ATTRIBUTE . '="bundle" /></pre-filter>');
	$parsed     = $serializer->toArray($withOrigin);
	$t->eq($parsed['pre-filter'][0]['attributes'], ['class' => 'x'], 'Herkunft landet nicht in attributes');
	$t->eq($parsed['pre-filter'][0]['origin'] ?? '', 'bundle', 'Herkunft landet im Feld origin');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('14. Drei-Ebenen-Verkettung (Bundle → Admin → User)');

	// Verkettung wie in ContentFilterRepository::getMerged(): erst Bundle+Admin
	// zu einem XML-String mergen, das Ergebnis dann als "Basis" in einen zweiten
	// merge()-Aufruf mit dem User-Layer geben. Reine Merger-Tests, keine
	// Repository-/DB-Abhängigkeit.
	$bundle = $doc('example.com', '
		<pre-filter>
			<remove class="bundle-a" />
			<remove class="bundle-b" />
		</pre-filter>
		<metadata><author xpath="//span[@class=\'bundle-author\']" /></metadata>
	');
	$adminXml = $doc('example.com', '
		<pre-filter><remove class="admin-a" /></pre-filter>
	');
	$userXml = $doc('example.com', '
		<pre-filter><remove class="user-a" /></pre-filter>
	');

	// Bundle+Admin (Regression: entspricht dem bisherigen zweistufigen Merge).
	$withAdminXml = $merger->mergeToString($bundle, $adminXml, 'example.com', ContentFilterSchema::ORIGIN_ADMIN);
	$withAdmin     = simplexml_load_string((string) $withAdminXml);
	$t->eq(
		$values($withAdmin, 'pre-filter/remove/@class'),
		['bundle-a', 'bundle-b', 'admin-a'],
		'Bundle+Admin: additiv, Admin-Regel läuft zuletzt'
	);
	$t->eq(
		$values($withAdmin, 'pre-filter/remove[@class="admin-a"]/@' . ContentFilterSchema::ORIGIN_ATTRIBUTE),
		[ContentFilterSchema::ORIGIN_ADMIN],
		'Bundle+Admin: Admin-Regel trägt die Herkunft "admin"'
	);

	// Bundle+User, kein Admin: zweistufiger Aufruf übersprungen (kein
	// Admin-Custom vorhanden), direkte Verkettung Bundle → User.
	$bundleOnly = $merger->mergeToString($bundle, null, 'example.com', ContentFilterSchema::ORIGIN_ADMIN);
	$onlyUser   = $merger->merge($bundleOnly, $userXml, 'example.com', ContentFilterSchema::ORIGIN_USER);
	$t->eq(
		$values($onlyUser, 'pre-filter/remove/@class'),
		['bundle-a', 'bundle-b', 'user-a'],
		'Bundle+User (kein Admin): additiv'
	);
	$t->eq(
		$values($onlyUser, 'pre-filter/remove[@class="user-a"]/@' . ContentFilterSchema::ORIGIN_ATTRIBUTE),
		[ContentFilterSchema::ORIGIN_USER],
		'Bundle+User: User-Regel trägt die Herkunft "user"'
	);

	// Bundle+Admin+User: volle Verkettung. Alle drei Herkünfte müssen im
	// Ergebnis unterscheidbar bleiben – insbesondere darf der zweite merge()-
	// Aufruf die im ersten Durchlauf gesetzten bundle-/admin-Tags NICHT auf
	// "bundle" zurücksetzen (siehe ContentFilterMerger::tagOrigin()).
	$final = $merger->merge($withAdminXml, $userXml, 'example.com', ContentFilterSchema::ORIGIN_USER);
	$t->eq(
		$values($final, 'pre-filter/remove/@class'),
		['bundle-a', 'bundle-b', 'admin-a', 'user-a'],
		'Bundle+Admin+User: additiv über alle drei Ebenen, in Reihenfolge'
	);
	$t->eq(
		$values($final, 'pre-filter/remove[@class="bundle-a"]/@' . ContentFilterSchema::ORIGIN_ATTRIBUTE),
		[ContentFilterSchema::ORIGIN_BUNDLE],
		'Bundle+Admin+User: Bundle-Regel bleibt "bundle" (wird vom zweiten Merge nicht überschrieben)'
	);
	$t->eq(
		$values($final, 'pre-filter/remove[@class="admin-a"]/@' . ContentFilterSchema::ORIGIN_ATTRIBUTE),
		[ContentFilterSchema::ORIGIN_ADMIN],
		'Bundle+Admin+User: Admin-Regel bleibt "admin" (wird vom zweiten Merge nicht überschrieben)'
	);
	$t->eq(
		$values($final, 'pre-filter/remove[@class="user-a"]/@' . ContentFilterSchema::ORIGIN_ATTRIBUTE),
		[ContentFilterSchema::ORIGIN_USER],
		'Bundle+Admin+User: User-Regel trägt "user"'
	);

	// <disable> im User-Layer gegen eine Regel, die der ADMIN-Layer hinzugefügt
	// hat (nicht nur gegen Bundle-Regeln) – muss über die Verkettung hinweg greifen.
	$adminAddsRule = $doc('example.com', '<pre-filter><remove class="admin-only" /></pre-filter>');
	$withAdmin2    = $merger->mergeToString($bundle, $adminAddsRule, 'example.com', ContentFilterSchema::ORIGIN_ADMIN);
	$userDisables  = $doc('example.com', '<disable><pre-filter><remove class="admin-only" /></pre-filter></disable>');
	$afterDisable  = $merger->merge($withAdmin2, $userDisables, 'example.com', ContentFilterSchema::ORIGIN_USER);
	$t->eq(
		$values($afterDisable, 'pre-filter/remove/@class'),
		['bundle-a', 'bundle-b'],
		'<disable> im User-Layer schaltet eine vom Admin-Layer hinzugefügte Regel ab'
	);

	// metadata-Fallback-Kette über drei Ebenen: User ersetzt die GANZE Kette,
	// nicht einzelne Einträge – wie schon bei Admin/Bundle.
	$adminMeta = $doc('example.com', '<metadata><author xpath="//span[@class=\'admin-author\']" /></metadata>');
	$withAdmin3 = $merger->mergeToString($bundle, $adminMeta, 'example.com', ContentFilterSchema::ORIGIN_ADMIN);
	$userMeta   = $doc('example.com', '<metadata><author xpath="//span[@class=\'user-author-1\']" /><author xpath="//span[@class=\'user-author-2\']" /></metadata>');
	$withUser3  = $merger->merge($withAdmin3, $userMeta, 'example.com', ContentFilterSchema::ORIGIN_USER);
	$t->eq(
		$values($withUser3, 'metadata/author/@xpath'),
		["//span[@class='user-author-1']", "//span[@class='user-author-2']"],
		'metadata: User-Kette ersetzt Admin-Kette komplett, in Reihenfolge'
	);

	// Kaskadierender Fail-open: kaputner User-Filter → Ergebnis wie Bundle+Admin.
	$brokenUser = '<domain name="example.com"><pre-filter></domain>';
	$fallback1  = null;
	try {
		$fallback1 = $merger->merge($withAdmin2, $brokenUser, 'example.com', ContentFilterSchema::ORIGIN_USER);
	} catch (\Throwable $e) {
		// Erwartet: merge() wirft bei kaputtem XML. ContentFilterRepository
		// fängt das ab und fällt auf merge($withAdmin, null, ...) zurück – das
		// wird hier direkt nachgebildet, ohne die Repository-Klasse zu brauchen.
		$fallback1 = $merger->merge($withAdmin2, null, 'example.com', ContentFilterSchema::ORIGIN_USER);
	}
	$t->eq(
		$values($fallback1, 'pre-filter/remove/@class'),
		['bundle-a', 'bundle-b', 'admin-only'],
		'Kaputter User-Filter: Fail-open auf Bundle+Admin'
	);

	// Zusätzlich kaputter Admin-Filter → Ergebnis wie Bundle allein.
	$brokenAdmin = '<domain name="example.com"><pre-filter></domain>';
	$withAdmin4  = null;
	try {
		$withAdmin4 = $merger->mergeToString($bundle, $brokenAdmin, 'example.com', ContentFilterSchema::ORIGIN_ADMIN);
	} catch (\Throwable $e) {
		$withAdmin4 = $merger->mergeToString($bundle, null, 'example.com', ContentFilterSchema::ORIGIN_ADMIN);
	}
	$t->eq(
		$values(simplexml_load_string((string) $withAdmin4), 'pre-filter/remove/@class'),
		['bundle-a', 'bundle-b'],
		'Kaputter Admin-Filter: Fail-open auf reines Bundle'
	);

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('15. Trace: Trefferzähler');

	$traceConfig = simplexml_load_string($doc('example.com', '
		<pre-filter>
			<remove class="a" ' . ContentFilterSchema::ORIGIN_ATTRIBUTE . '="bundle" />
			<remove class="b" ' . ContentFilterSchema::ORIGIN_ATTRIBUTE . '="custom" />
		</pre-filter>
	'));
	$rules = $traceConfig->xpath('pre-filter/remove') ?: [];

	$trace = new \OCA\Merlin\Service\ContentFilterTrace();
	$trace->record('pre-filter', $rules[0], 3);
	$trace->record('pre-filter', $rules[1], 0);
	$entries = $trace->toArray();

	$t->eq(count($entries), 2, 'Zwei Regeln, zwei Einträge');
	$t->eq($entries[0]['attributes'], ['class' => 'a'], 'Herkunftsattribut steht nicht in attributes');
	$t->eq($entries[0]['origin'], 'bundle', 'Herkunft wird übernommen');
	$t->eq($entries[1]['origin'], 'custom', 'Herkunft der Custom-Regel wird übernommen');
	$t->eq($entries[0]['matches'], 3, 'Trefferzahl wird festgehalten');
	$t->eq($trace->countMisses(), 1, 'countMisses zählt Regeln ohne Treffer');
	$t->eq($trace->countErrors(), 0, 'countErrors ist ohne Fehler 0');

	// Dieselbe Regel zweimal (z. B. weil ein Applier mehrfach läuft) → summieren,
	// nicht zwei Zeilen mit halbierten Zahlen anzeigen.
	$trace->record('pre-filter', $rules[0], 2);
	$entries = $trace->toArray();
	$t->eq(count($entries), 2, 'Wiederholte Regel erzeugt keinen zweiten Eintrag');
	$t->eq($entries[0]['matches'], 5, 'Treffer werden summiert');

	$trace->record('post-filter', $rules[0], 0, 'Ungültiger XPath-Ausdruck');
	$t->eq(count($trace->toArray()), 3, 'Gleiche Regel in anderer Sektion ist ein eigener Eintrag');
	$t->eq($trace->countErrors(), 1, 'countErrors zählt fehlerhafte Regeln');

	$trace->recordRaw('metadata', 'author', ['xpath' => '//a'], 'custom', 1);
	$t->eq(count($trace->toArray()), 4, 'recordRaw ergänzt einen Eintrag');

	// Identische Regeln aus Bundle und Custom laufen beide – sie dürfen im Bericht
	// nicht zu einer Zeile verschmelzen, sonst hält der Admin seine eigene Regel
	// für verschwunden.
	$dupTrace = new \OCA\Merlin\Service\ContentFilterTrace();
	$dupTrace->recordRaw('pre-filter', 'remove', ['class' => 'ads'], 'bundle', 2);
	$dupTrace->recordRaw('pre-filter', 'remove', ['class' => 'ads'], 'custom', 0);
	$t->eq(count($dupTrace->toArray()), 2, 'Gleiche Regel aus Bundle und Custom bleibt getrennt');
	$t->eq($dupTrace->countMisses(), 1, 'Die wirkungslose eigene Regel ist als Fehltreffer sichtbar');

	exit($t->summary());
}
