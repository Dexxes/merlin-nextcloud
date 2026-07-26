<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

/**
 * Übersetzt zwischen Filter-XML und der JSON-Struktur, mit der der Regel-Builder
 * im Frontend arbeitet.
 *
 * Warum serverseitig: Damit es genau eine Stelle gibt, die das XML-Format kennt.
 * Würde das Frontend XML zusammenbauen, müsste die Grammatik in JavaScript
 * gespiegelt werden und würde bei der ersten Formaterweiterung auseinanderlaufen.
 *
 * JSON-Form (alle Schlüssel optional):
 *   {
 *     "domain": "golem.de",
 *     "note": "Freitext des Admins",
 *     "disable": {
 *       "sections": ["post-filter"],
 *       "rules": {
 *         "pre-filter": [ { "element": "remove", "attributes": { "class": "supplementary" } } ]
 *       }
 *     },
 *     "fetch":       [ { "element": "header", "attributes": { "name": "Cookie", "value": "…" } } ],
 *     "pre-filter":  [ { "element": "remove", "attributes": { "class": "ad" } } ],
 *     "images":      [ { "element": "caption", "attributes": { … } } ],
 *     "quotes":      [ { "element": "quote",   "attributes": { … } } ],
 *     "post-filter": [ … ],
 *     "json":        [ { "element": "json",    "attributes": { "id": "ld", "xpath": "…" } } ],
 *     "metadata":    [ { "element": "author",  "attributes": { "xpath": "…" } } ],
 *     "category":    "Nachrichten"
 *   }
 *
 * Jede Regel hat dieselbe Form { element, attributes } – dadurch bleiben die
 * Vue-Komponenten generisch und lesen die erlaubten Felder aus dem Schema.
 */
class ContentFilterSerializer {

	/**
	 * Kopfkommentar generierter Dateien. Der Hinweis ist wichtig, weil ein
	 * Speichern über die UI die Datei komplett neu schreibt: handgeschriebene
	 * XML-Kommentare wären danach weg. Admin-Notizen gehören deshalb in <note>.
	 */
	private const HEADER_COMMENT = <<<'TXT'

  Merlin Content-Filter (Custom)

  Diese Datei wurde von der Merlin-Administrationsoberfläche erzeugt.
  Sie ergänzt einen etwaigen mitgelieferten Filter derselben Domain; einzelne
  Bundle-Regeln lassen sich über <disable> abschalten.

  ACHTUNG: Beim Speichern über die Oberfläche wird die Datei neu geschrieben.
  Eigene XML-Kommentare gehen dabei verloren – Notizen gehören in <note>.

  Alle Sektionen und Regeltypen sind in content-filters/000.sample.com.xml
  dokumentiert.

TXT;

	/** Zulässige Element- und Attributnamen (Syntax, nicht Semantik). */
	private const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9._-]*$/';

	// ──────────────────────────────────────────────────────────────────────────
	// JSON → XML
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Baut Filter-XML aus der Builder-Struktur.
	 *
	 * Erzeugt bewusst KEIN validiertes Ergebnis – die Prüfung übernimmt
	 * ContentFilterValidator auf dem fertigen XML. So gibt es einen einzigen
	 * Prüfpfad, egal ob das XML aus dem Builder, aus einem Import oder von Hand
	 * kommt.
	 *
	 * @param array<string,mixed> $data
	 * @throws \InvalidArgumentException bei syntaktisch unmöglichen Namen
	 */
	public function toXml(array $data, string $domain): string {
		$doc = new \DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;

		$root = $doc->createElement(ContentFilterSchema::ROOT_ELEMENT);
		$root->setAttribute('name', $domain);

		$doc->appendChild($doc->createComment(self::HEADER_COMMENT));
		$doc->appendChild($root);

		foreach (ContentFilterSchema::SECTION_ORDER as $section) {
			if ($section === 'disable') {
				$this->writeDisable($doc, $root, $data['disable'] ?? null);
				continue;
			}

			$def = ContentFilterSchema::section($section);
			if ($def === null) {
				continue;
			}

			switch ($def['kind']) {
				case 'root-text':
					$this->writeTextSection($doc, $root, $section, $data[$section] ?? null);
					break;

				case 'root-list-keyed':
					foreach ($this->ruleList($data[$section] ?? null) as $rule) {
						$root->appendChild($this->buildRule($doc, $rule, $section));
					}
					break;

				default:
					$rules = $this->ruleList($data[$section] ?? null);
					if ($rules === []) {
						break;
					}
					$wrapper = $doc->createElement($section);
					foreach ($rules as $rule) {
						$wrapper->appendChild($this->buildRule($doc, $rule, null));
					}
					$root->appendChild($wrapper);
					break;
			}
		}

		$xml = $doc->saveXML();
		if ($xml === false) {
			throw new \InvalidArgumentException('Filter-XML konnte nicht erzeugt werden.');
		}
		return $xml;
	}

	/**
	 * @param array<string,mixed>|null $disable
	 */
	private function writeDisable(\DOMDocument $doc, \DOMElement $root, ?array $disable): void {
		if ($disable === null) {
			return;
		}

		foreach ($this->stringList($disable['sections'] ?? null) as $section) {
			$el = $doc->createElement('disable');
			$el->setAttribute('section', $section);
			$root->appendChild($el);
		}

		$rules = $disable['rules'] ?? null;
		if (!is_array($rules) || $rules === []) {
			return;
		}

		$block   = $doc->createElement('disable');
		$written = false;

		foreach ($rules as $section => $sectionRules) {
			$list = $this->ruleList($sectionRules);
			if ($list === []) {
				continue;
			}
			$section = (string) $section;
			$this->assertName($section);

			// <json> ist wrapper-los und steht deshalb direkt in <disable>.
			if ($section === 'json') {
				foreach ($list as $rule) {
					$block->appendChild($this->buildRule($doc, $rule, 'json'));
					$written = true;
				}
				continue;
			}

			$wrapper = $doc->createElement($section);
			foreach ($list as $rule) {
				$wrapper->appendChild($this->buildRule($doc, $rule, null));
			}
			$block->appendChild($wrapper);
			$written = true;
		}

		if ($written) {
			$root->appendChild($block);
		}
	}

	private function writeTextSection(\DOMDocument $doc, \DOMElement $root, string $section, mixed $value): void {
		if (!is_string($value)) {
			return;
		}
		$value = trim($value);
		if ($value === '') {
			return;
		}
		// Textknoten statt createElement($name, $value): die zweite Form escapet
		// &, < und > nicht und würde bei einer Notiz mit "&" ungültiges XML
		// erzeugen.
		$el = $doc->createElement($section);
		$el->appendChild($doc->createTextNode($value));
		$root->appendChild($el);
	}

	/**
	 * Baut ein Regelelement. $fixedElement erzwingt den Elementnamen (nötig für
	 * <json>, wo Sektions- und Elementname identisch sind).
	 *
	 * @param array<string,mixed> $rule
	 */
	private function buildRule(\DOMDocument $doc, array $rule, ?string $fixedElement): \DOMElement {
		$name = $fixedElement ?? trim((string) ($rule['element'] ?? ''));
		$this->assertName($name);

		$el = $doc->createElement($name);

		$attributes = $rule['attributes'] ?? [];
		if (!is_array($attributes)) {
			return $el;
		}

		foreach ($attributes as $attrName => $attrValue) {
			$attrName = trim((string) $attrName);
			$this->assertName($attrName);

			if (is_bool($attrValue)) {
				$attrValue = $attrValue ? 'true' : 'false';
			}
			if (!is_scalar($attrValue)) {
				continue;
			}
			$attrValue = trim((string) $attrValue);

			// Leere Attribute werden weggelassen statt als name="" geschrieben:
			// der Validator würde sie ohnehin als fehlend melden, und ein leeres
			// Attribut in der Datei suggeriert eine Absicht, die es nicht gibt.
			if ($attrValue === '') {
				continue;
			}

			$el->setAttribute($attrName, $attrValue);
		}

		return $el;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// XML → JSON
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Zerlegt Filter-XML in die Builder-Struktur.
	 *
	 * Unbekannte Sektionen werden mit ausgelesen, damit die UI sie anzeigen kann;
	 * toXml() schreibt allerdings nur bekannte Sektionen zurück. Für Dateien aus
	 * der Oberfläche ist das folgenlos – der Validator lehnt Unbekanntes beim
	 * Speichern ab. Relevant bleibt es nur für von Hand im Custom-Ordner
	 * abgelegte Dateien: Wer sie über die Oberfläche speichert, verliert die
	 * unbekannten Teile.
	 *
	 * @return array<string,mixed>
	 * @throws \RuntimeException wenn das XML nicht lesbar ist
	 */
	public function toArray(string $xml): array {
		if (stripos($xml, '<!DOCTYPE') !== false) {
			throw new \RuntimeException('DOCTYPE-Deklarationen sind nicht erlaubt.');
		}

		$prev = libxml_use_internal_errors(true);
		$doc  = new \DOMDocument('1.0', 'UTF-8');
		$ok   = $doc->loadXML($xml, LIBXML_NONET);
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if ($ok === false || $doc->documentElement === null) {
			$first = $errors[0]->message ?? 'unbekannter XML-Fehler';
			throw new \RuntimeException('Filter-XML ist nicht wohlgeformt: ' . trim($first));
		}

		$root = $doc->documentElement;
		$out  = ['domain' => trim($root->getAttribute('name'))];

		foreach ($this->elementChildren($root) as $child) {
			$section = $child->tagName;

			if ($section === 'disable') {
				$out['disable'] = $this->readDisable($child, $out['disable'] ?? null);
				continue;
			}

			$def  = ContentFilterSchema::section($section);
			$kind = $def['kind'] ?? 'list';

			if ($def !== null && $kind === 'root-text') {
				$out[$section] = trim($child->textContent);
				continue;
			}

			if ($def !== null && $kind === 'root-list-keyed') {
				$out[$section][] = $this->readRule($child);
				continue;
			}

			// Wrapper-Sektion (auch unbekannte, damit die UI sie sichtbar machen kann)
			$out[$section] ??= [];
			foreach ($this->elementChildren($child) as $rule) {
				$out[$section][] = $this->readRule($rule);
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed>|null $carry bereits gelesene <disable>-Blöcke
	 * @return array{sections:list<string>,rules:array<string,list<array<string,mixed>>>}
	 */
	private function readDisable(\DOMElement $disable, ?array $carry): array {
		$result = [
			'sections' => $carry['sections'] ?? [],
			'rules'    => $carry['rules'] ?? [],
		];

		$section = trim($disable->getAttribute('section'));
		if ($section !== '') {
			$result['sections'][] = $section;
			return $result;
		}

		foreach ($this->elementChildren($disable) as $child) {
			// <json> steht wrapper-los direkt in <disable>.
			if ($child->tagName === 'json') {
				$result['rules']['json'][] = $this->readRule($child);
				continue;
			}
			$name = $child->tagName;
			$result['rules'][$name] ??= [];
			foreach ($this->elementChildren($child) as $rule) {
				$result['rules'][$name][] = $this->readRule($rule);
			}
		}

		return $result;
	}

	/**
	 * @return array{element:string,attributes:array<string,string>,origin?:string}
	 */
	private function readRule(\DOMElement $rule): array {
		$attributes = [];
		$origin     = null;

		foreach ($rule->attributes as $attr) {
			if ($attr->nodeName === ContentFilterSchema::ORIGIN_ATTRIBUTE) {
				// Herkunft wandert in ein eigenes Feld, damit sie nicht beim
				// nächsten Speichern als echtes Attribut zurückgeschrieben wird.
				$origin = trim((string) $attr->nodeValue);
				continue;
			}
			$attributes[$attr->nodeName] = trim((string) $attr->nodeValue);
		}

		$out = ['element' => $rule->tagName, 'attributes' => $attributes];
		if ($origin !== null) {
			$out['origin'] = $origin;
		}
		return $out;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Helfer
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * @return list<array<string,mixed>>
	 */
	private function ruleList(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		$out = [];
		foreach ($value as $entry) {
			if (is_array($entry)) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	private function stringList(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		$out = [];
		foreach ($value as $entry) {
			if (is_string($entry) && trim($entry) !== '') {
				$out[] = trim($entry);
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Element- und Attributnamen kommen aus einem HTTP-Request. Ohne diese
	 * Prüfung würde DOMDocument::createElement() bei einem Namen mit Leerzeichen
	 * oder Sonderzeichen eine DOMException werfen, die als 500er beim Nutzer
	 * landet statt als Validierungsmeldung.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertName(string $name): void {
		if (preg_match(self::NAME_PATTERN, $name) !== 1) {
			throw new \InvalidArgumentException('Ungültiger Element- oder Attributname: ' . $name);
		}
	}

	/**
	 * @return list<\DOMElement>
	 */
	private function elementChildren(\DOMElement $parent): array {
		$out = [];
		foreach ($parent->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$out[] = $child;
			}
		}
		return $out;
	}
}
