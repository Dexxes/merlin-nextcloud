<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

/**
 * Liest die eingebetteten Metadaten einer Datei aus „Merlin Dateien“ für die
 * Anzeige unter der Datei im Reader (siehe MerlinFileService::buildContent()):
 *
 *   EXIF (inkl. GPS)   JPEG, TIFF, HEIC – über ext-exif, wenn vorhanden
 *   IPTC               JPEG (APP13)
 *   XMP                jedes Format mit eingebettetem XMP-Paket (Bilder, PDF, Videos)
 *   ID3v2 / ID3v1      MP3 und andere Audiodateien mit ID3-Tag
 *   QuickTime/MP4      MP4, MOV, M4A, M4V (iTunes-Tags, Apple-Metadaten, Standort)
 *   PDF                Info-Wörterbuch und PDF-Version
 *
 * Arbeitet auf einem Stream (Nextcloud-Datei, fopen('r')) und liest nur Kopf,
 * Ende und gezielte Ausschnitte, damit auch große Videos schnell gehen. Frei
 * von Nextcloud-Abhängigkeiten (Test: tools/test-file-metadata.php).
 *
 * Ergebnis: Liste von Gruppen ['key' => 'exif', 'title' => 'EXIF',
 * 'entries' => [['label' => …, 'value' => …], …]]. Werte sind Klartext
 * (Escaping beim Rendern). Leere Gruppen fehlen.
 */
class FileMetadata {
	/** Kopf der Datei für XMP/IPTC/PDF (XMP steckt bei Bildern am Anfang). */
	private const HEAD_BYTES = 4 * 1024 * 1024;
	/** Ende der Datei für PDF-Trailer/Info, XMP am Dateiende, ID3v1. */
	private const TAIL_BYTES = 512 * 1024;
	/** Größter ID3v2-Tag / moov-Atom, der gelesen wird (Cover-Bilder, lange Videos). */
	private const MAX_BLOCK_BYTES = 32 * 1024 * 1024;
	private const MAX_VALUE_LENGTH = 2000;
	private const MAX_ENTRIES_PER_GROUP = 400;

	/** Lesbare Namen der IPTC-IIM-Datensätze (Record 2). */
	private const IPTC_NAMES = [
		'2#000' => 'RecordVersion', '2#003' => 'ObjectTypeReference', '2#004' => 'ObjectAttributeReference',
		'2#005' => 'ObjectName', '2#007' => 'EditStatus', '2#010' => 'Urgency', '2#012' => 'SubjectReference',
		'2#015' => 'Category', '2#020' => 'SupplementalCategories', '2#022' => 'FixtureIdentifier',
		'2#025' => 'Keywords', '2#026' => 'ContentLocationCode', '2#027' => 'ContentLocationName',
		'2#030' => 'ReleaseDate', '2#035' => 'ReleaseTime', '2#037' => 'ExpirationDate', '2#038' => 'ExpirationTime',
		'2#040' => 'SpecialInstructions', '2#042' => 'ActionAdvised', '2#045' => 'ReferenceService',
		'2#047' => 'ReferenceDate', '2#050' => 'ReferenceNumber', '2#055' => 'DateCreated', '2#060' => 'TimeCreated',
		'2#062' => 'DigitalCreationDate', '2#063' => 'DigitalCreationTime', '2#065' => 'OriginatingProgram',
		'2#070' => 'ProgramVersion', '2#075' => 'ObjectCycle', '2#080' => 'By-line', '2#085' => 'By-lineTitle',
		'2#090' => 'City', '2#092' => 'Sub-location', '2#095' => 'Province-State',
		'2#100' => 'Country-PrimaryLocationCode', '2#101' => 'Country-PrimaryLocationName',
		'2#103' => 'OriginalTransmissionReference', '2#105' => 'Headline', '2#110' => 'Credit',
		'2#115' => 'Source', '2#116' => 'CopyrightNotice', '2#118' => 'Contact', '2#120' => 'Caption-Abstract',
		'2#121' => 'LocalCaption', '2#122' => 'Writer-Editor', '2#130' => 'ImageType',
		'2#131' => 'ImageOrientation', '2#135' => 'LanguageIdentifier',
	];

	/** Lesbare Namen häufiger ID3v2-Frames (v2.3/v2.4, v2.2 wird umgesetzt). */
	private const ID3_NAMES = [
		'TIT1' => 'Content group', 'TIT2' => 'Title', 'TIT3' => 'Subtitle', 'TPE1' => 'Artist',
		'TPE2' => 'Album artist', 'TPE3' => 'Conductor', 'TPE4' => 'Remixer', 'TALB' => 'Album',
		'TRCK' => 'Track', 'TPOS' => 'Disc', 'TYER' => 'Year', 'TDRC' => 'Recording time',
		'TDRL' => 'Release time', 'TDOR' => 'Original release time', 'TORY' => 'Original release year',
		'TDAT' => 'Date', 'TIME' => 'Time', 'TCON' => 'Genre', 'TCOM' => 'Composer', 'TEXT' => 'Lyricist',
		'TCOP' => 'Copyright', 'TPUB' => 'Publisher', 'TENC' => 'Encoded by', 'TSSE' => 'Encoder settings',
		'TLEN' => 'Length (ms)', 'TBPM' => 'BPM', 'TKEY' => 'Initial key', 'TLAN' => 'Language',
		'TMED' => 'Media type', 'TSRC' => 'ISRC', 'TCMP' => 'Compilation', 'TOAL' => 'Original album',
		'TOPE' => 'Original artist', 'TOWN' => 'File owner', 'TRSN' => 'Radio station', 'TSOA' => 'Album sort order',
		'TSOP' => 'Performer sort order', 'TSOT' => 'Title sort order', 'TDEN' => 'Encoding time',
		'TDTG' => 'Tagging time', 'TMOO' => 'Mood', 'TFLT' => 'File type', 'TXXX' => 'User text',
		'COMM' => 'Comment', 'USLT' => 'Lyrics', 'APIC' => 'Picture', 'WXXX' => 'User URL',
		'WCOM' => 'Commercial URL', 'WCOP' => 'Copyright URL', 'WOAF' => 'Audio file URL',
		'WOAR' => 'Artist URL', 'WOAS' => 'Audio source URL', 'WORS' => 'Radio station URL',
		'WPAY' => 'Payment URL', 'WPUB' => 'Publisher URL', 'PCNT' => 'Play counter', 'POPM' => 'Popularimeter',
		'PRIV' => 'Private frame', 'GEOB' => 'Encapsulated object', 'UFID' => 'Unique file identifier',
		'CHAP' => 'Chapter', 'CTOC' => 'Table of contents', 'SYLT' => 'Synchronised lyrics',
	];

	/** ID3v2.2-Frame-IDs (3 Zeichen) → v2.3. */
	private const ID3_V22 = [
		'TT1' => 'TIT1', 'TT2' => 'TIT2', 'TT3' => 'TIT3', 'TP1' => 'TPE1', 'TP2' => 'TPE2', 'TP3' => 'TPE3',
		'TP4' => 'TPE4', 'TAL' => 'TALB', 'TRK' => 'TRCK', 'TPA' => 'TPOS', 'TYE' => 'TYER', 'TDA' => 'TDAT',
		'TIM' => 'TIME', 'TCO' => 'TCON', 'TCM' => 'TCOM', 'TXT' => 'TEXT', 'TCR' => 'TCOP', 'TPB' => 'TPUB',
		'TEN' => 'TENC', 'TSS' => 'TSSE', 'TLE' => 'TLEN', 'TBP' => 'TBPM', 'TKE' => 'TKEY', 'TLA' => 'TLAN',
		'TMT' => 'TMED', 'TRC' => 'TSRC', 'TOT' => 'TOAL', 'TOA' => 'TOPE', 'TXX' => 'TXXX', 'COM' => 'COMM',
		'ULT' => 'USLT', 'PIC' => 'APIC', 'WXX' => 'WXXX', 'WAR' => 'WOAR', 'WAF' => 'WOAF', 'CNT' => 'PCNT',
		'POP' => 'POPM', 'UFI' => 'UFID', 'GEO' => 'GEOB', 'TCP' => 'TCMP',
	];

	private const ID3V1_GENRES = [
		'Blues', 'Classic Rock', 'Country', 'Dance', 'Disco', 'Funk', 'Grunge', 'Hip-Hop', 'Jazz', 'Metal',
		'New Age', 'Oldies', 'Other', 'Pop', 'R&B', 'Rap', 'Reggae', 'Rock', 'Techno', 'Industrial',
		'Alternative', 'Ska', 'Death Metal', 'Pranks', 'Soundtrack', 'Euro-Techno', 'Ambient', 'Trip-Hop',
		'Vocal', 'Jazz+Funk', 'Fusion', 'Trance', 'Classical', 'Instrumental', 'Acid', 'House', 'Game',
		'Sound Clip', 'Gospel', 'Noise', 'AlternRock', 'Bass', 'Soul', 'Punk', 'Space', 'Meditative',
		'Instrumental Pop', 'Instrumental Rock', 'Ethnic', 'Gothic', 'Darkwave', 'Techno-Industrial',
		'Electronic', 'Pop-Folk', 'Eurodance', 'Dream', 'Southern Rock', 'Comedy', 'Cult', 'Gangsta',
		'Top 40', 'Christian Rap', 'Pop/Funk', 'Jungle', 'Native American', 'Cabaret', 'New Wave',
		'Psychadelic', 'Rave', 'Showtunes', 'Trailer', 'Lo-Fi', 'Tribal', 'Acid Punk', 'Acid Jazz', 'Polka',
		'Retro', 'Musical', 'Rock & Roll', 'Hard Rock',
	];

	/** iTunes-/QuickTime-Tags in ilst bzw. udta. */
	private const MP4_NAMES = [
		"\xA9nam" => 'Title', "\xA9ART" => 'Artist', 'aART' => 'Album artist', "\xA9alb" => 'Album',
		"\xA9day" => 'Date', "\xA9gen" => 'Genre', 'gnre' => 'Genre', "\xA9wrt" => 'Composer',
		"\xA9cmt" => 'Comment', "\xA9too" => 'Encoder', "\xA9enc" => 'Encoded by', "\xA9lyr" => 'Lyrics',
		"\xA9grp" => 'Grouping', 'trkn' => 'Track', 'disk' => 'Disc', 'cprt' => 'Copyright',
		"\xA9cpy" => 'Copyright', 'desc' => 'Description', 'ldes' => 'Long description', 'tvsh' => 'TV show',
		'tven' => 'TV episode ID', 'tvsn' => 'TV season', 'tves' => 'TV episode', 'tvnn' => 'TV network',
		'cpil' => 'Compilation', 'pgap' => 'Gapless', 'tmpo' => 'BPM', 'covr' => 'Cover', 'stik' => 'Media kind',
		'purd' => 'Purchase date', 'soal' => 'Album sort order', 'soar' => 'Artist sort order',
		'sonm' => 'Title sort order', "\xA9xyz" => 'Location (ISO 6709)', "\xA9mak" => 'Make',
		"\xA9mod" => 'Model', "\xA9swr" => 'Software', "\xA9des" => 'Description', "\xA9dir" => 'Director',
		"\xA9inf" => 'Information', "\xA9req" => 'Requirements', "\xA9fmt" => 'Format', "\xA9src" => 'Source',
		"\xA9prd" => 'Producer', "\xA9prf" => 'Performers', "\xA9aut" => 'Author', "\xA9PRD" => 'Product',
		"\xA9dis" => 'Disclaimer', "\xA9ed1" => 'Edit date', "\xA9hst" => 'Host computer', "\xA9wrn" => 'Warning',
		"\xA9url" => 'URL', "\xA9st3" => 'Subtitle', "\xA9key" => 'Keywords',
	];

	/** Container-Atome, in die beim Durchsuchen von moov hineingestiegen wird. */
	private const MP4_CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'udta', 'edts', 'dinf'];

	/**
	 * @param resource $handle lesbarer Stream der Datei (Position egal)
	 * @return list<array{key: string, title: string, entries: list<array{label: string, value: string}>}>
	 */
	public static function extract($handle, int $size, string $mime): array {
		$mime = strtolower(trim($mime));
		$seekable = (bool) (stream_get_meta_data($handle)['seekable'] ?? false);
		$head = self::readAt($handle, 0, min($size, self::HEAD_BYTES), $seekable);
		$tail = ($seekable && $size > self::HEAD_BYTES)
			? self::readAt($handle, max(self::HEAD_BYTES, $size - self::TAIL_BYTES), self::TAIL_BYTES, true)
			: '';

		$groups = [];
		if (str_starts_with($mime, 'image/') || self::looksLikeExifContainer($head)) {
			array_push($groups, ...self::exifGroups($handle, $head, $seekable));
			if (($iptc = self::iptc($head)) !== []) {
				$groups[] = self::group('iptc', 'IPTC', $iptc);
			}
		}
		if (str_starts_with($head, 'ID3')) {
			[$version, $entries] = self::id3v2($handle, $head, $seekable);
			if ($entries !== []) {
				$groups[] = self::group('id3v2', 'ID3v2.' . $version, $entries);
			}
		}
		$end = $tail !== '' ? $tail : $head;
		if (strlen($end) >= 128 && ($id3v1 = self::id3v1(substr($end, -128))) !== []) {
			$groups[] = self::group('id3v1', 'ID3v1', $id3v1);
		}
		if (self::isMp4($head)) {
			$entries = self::mp4($handle, $size, $seekable, $head);
			if ($entries !== []) {
				$groups[] = self::group('quicktime', 'QuickTime / MP4', $entries);
			}
		}
		if (str_starts_with($head, '%PDF-')) {
			$entries = self::pdf($head, $tail);
			if ($entries !== []) {
				$groups[] = self::group('pdf', 'PDF', $entries);
			}
		}
		$xmp = self::xmp($head) ?: self::xmp($tail);
		if ($xmp !== []) {
			$groups[] = self::group('xmp', 'XMP', $xmp);
		}
		return $groups;
	}

	// ── EXIF / IPTC ────────────────────────────────────────────────────────

	private static function looksLikeExifContainer(string $head): bool {
		return str_starts_with($head, "\xFF\xD8") || str_starts_with($head, "II*\0") || str_starts_with($head, "MM\0*");
	}

	/**
	 * EXIF-Abschnitte (IFD0, EXIF, GPS, Interop) aus exif_read_data(); GPS
	 * zusätzlich als Dezimalkoordinaten. Ohne ext-exif keine EXIF-Gruppe.
	 *
	 * @param resource $handle
	 * @return list<array{key: string, title: string, entries: list<array{label: string, value: string}>}>
	 */
	private static function exifGroups($handle, string $head, bool $seekable): array {
		if (!function_exists('exif_read_data')) {
			return [];
		}
		// exif_read_data braucht einen seekbaren Stream; sonst den Kopf (EXIF
		// steht bei JPEG/HEIC am Anfang) aus dem Speicher lesen.
		if ($seekable) {
			rewind($handle);
			$source = $handle;
		} else {
			$source = fopen('php://memory', 'w+b');
			if ($source === false) {
				return [];
			}
			fwrite($source, $head);
			rewind($source);
		}
		$data = @exif_read_data($source, null, true, false);
		if (!is_array($data)) {
			return [];
		}

		$groups = [];
		$exif = [];
		foreach (['IFD0', 'EXIF', 'INTEROP'] as $section) {
			foreach ((array) ($data[$section] ?? []) as $tag => $value) {
				if (!is_string($tag) || str_starts_with($tag, 'UndefinedTag:') || in_array($tag, ['MakerNote', 'Exif_IFD_Pointer', 'GPS_IFD_Pointer', 'InteroperabilityOffset'], true)) {
					continue;
				}
				if (($text = self::exifValue($tag, $value)) !== null) {
					$exif[] = ['label' => $tag, 'value' => $text];
				}
			}
		}
		if (isset($data['COMPUTED']) && is_array($data['COMPUTED'])) {
			foreach (['Width', 'Height', 'ApertureFNumber', 'CCDWidth', 'UserComment'] as $tag) {
				if (isset($data['COMPUTED'][$tag]) && ($text = self::exifValue($tag, $data['COMPUTED'][$tag])) !== null) {
					$exif[] = ['label' => $tag, 'value' => $text];
				}
			}
		}
		if ($exif !== []) {
			$groups[] = self::group('exif', 'EXIF', $exif);
		}

		$gpsData = (array) ($data['GPS'] ?? []);
		$gps = [];
		$coordinates = self::gpsCoordinates($gpsData);
		if ($coordinates !== null) {
			$gps[] = ['label' => 'Position', 'value' => $coordinates];
		}
		foreach ($gpsData as $tag => $value) {
			if (!is_string($tag) || str_starts_with($tag, 'UndefinedTag:')) {
				continue;
			}
			if (($text = self::exifValue($tag, $value)) !== null) {
				$gps[] = ['label' => $tag, 'value' => $text];
			}
		}
		if ($gps !== []) {
			$groups[] = self::group('gps', 'GPS', $gps);
		}
		return $groups;
	}

	private static function exifValue(string $tag, mixed $value): ?string {
		if (is_array($value)) {
			$parts = array_filter(array_map(fn($v) => self::exifValue($tag, $v), $value), fn($v) => $v !== null);
			return $parts === [] ? null : implode(', ', $parts);
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}
		if (!is_string($value)) {
			return null;
		}
		// Versionsfelder (ExifVersion "0232", FlashPixVersion …) sind ASCII-Ziffern.
		$value = rtrim($value, "\0 ");
		if ($value === '') {
			return null;
		}
		if (preg_match('#^(-?\d+)/(\d+)$#', $value, $m) === 1) {
			return self::rational($tag, (int) $m[1], (int) $m[2]);
		}
		return self::text($value);
	}

	/** Brüche lesbar: Belichtungszeit 1/125, Blende f/2.8, sonst Dezimalzahl. */
	private static function rational(string $tag, int $num, int $den): string {
		if ($den === 0) {
			return $num . '/0';
		}
		if ($tag === 'ExposureTime' && $num > 0 && $num < $den) {
			return '1/' . round($den / $num) . ' s';
		}
		$value = $num / $den;
		$formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
		return match ($tag) {
			'FNumber' => 'f/' . $formatted,
			'ExposureTime' => $formatted . ' s',
			'FocalLength' => $formatted . ' mm',
			default => $formatted,
		};
	}

	/** GPS-Position als "lat, lon" (Dezimalgrad, WGS 84) oder null. */
	private static function gpsCoordinates(array $gps): ?string {
		$lat = self::gpsDegrees($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? 'N');
		$lon = self::gpsDegrees($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? 'E');
		if ($lat === null || $lon === null) {
			return null;
		}
		return sprintf('%.6f, %.6f', $lat, $lon);
	}

	private static function gpsDegrees(mixed $parts, mixed $ref): ?float {
		if (!is_array($parts) || count($parts) !== 3) {
			return null;
		}
		$values = [];
		foreach (array_values($parts) as $part) {
			if (!is_string($part) || preg_match('#^(\d+)/(\d+)$#', $part, $m) !== 1 || (int) $m[2] === 0) {
				return null;
			}
			$values[] = (int) $m[1] / (int) $m[2];
		}
		$degrees = $values[0] + $values[1] / 60 + $values[2] / 3600;
		return in_array(strtoupper((string) $ref), ['S', 'W'], true) ? -$degrees : $degrees;
	}

	/** @return list<array{label: string, value: string}> */
	private static function iptc(string $head): array {
		if (!str_starts_with($head, "\xFF\xD8") || !function_exists('iptcparse')) {
			return [];
		}
		$info = [];
		if (@getimagesizefromstring($head, $info) === false || !isset($info['APP13'])) {
			return [];
		}
		$data = @iptcparse($info['APP13']);
		if (!is_array($data)) {
			return [];
		}
		$entries = [];
		foreach ($data as $code => $values) {
			if (!str_starts_with((string) $code, '2#') || $code === '2#000') {
				continue;
			}
			$label = self::IPTC_NAMES[$code] ?? ('IPTC ' . $code);
			$texts = array_values(array_filter(array_map(
				static fn($v) => is_string($v) ? self::text(self::toUtf8($v)) : null,
				(array) $values
			), static fn($v) => $v !== null && $v !== ''));
			if ($texts !== []) {
				$entries[] = ['label' => $label, 'value' => implode(', ', $texts)];
			}
		}
		return $entries;
	}

	// ── XMP ────────────────────────────────────────────────────────────────

	/**
	 * Eigenschaften des ersten XMP-Pakets als "prefix:Name" → Wert. Strukturen
	 * werden mit "/" aufgelöst, Listen (rdf:Seq/Bag/Alt) mit Komma verbunden.
	 *
	 * @return list<array{label: string, value: string}>
	 */
	private static function xmp(string $bytes): array {
		if ($bytes === '') {
			return [];
		}
		$start = strpos($bytes, '<x:xmpmeta');
		$endTag = '</x:xmpmeta>';
		if ($start === false) {
			// Manche Programme schreiben nur rdf:RDF ohne x:xmpmeta.
			$start = strpos($bytes, '<rdf:RDF');
			$endTag = '</rdf:RDF>';
		}
		if ($start === false) {
			return [];
		}
		$end = strpos($bytes, $endTag, $start);
		if ($end === false) {
			return [];
		}
		$xml = substr($bytes, $start, $end + strlen($endTag) - $start);
		// XMP hat keine DTD; Entity-Definitionen (Expansionsangriffe) gar nicht erst parsen.
		if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
			return [];
		}

		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		// Kein Netz, keine externen Entities (Standard seit PHP 8, hier explizit).
		$loaded = $dom->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded || $dom->documentElement === null) {
			return [];
		}

		$entries = [];
		$rdfNs = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
		foreach ($dom->getElementsByTagNameNS($rdfNs, 'Description') as $description) {
			if (!$description instanceof \DOMElement || self::hasAncestorDescription($description, $rdfNs)) {
				continue;
			}
			self::xmpProperties($description, '', $rdfNs, $entries);
		}
		return $entries;
	}

	private static function hasAncestorDescription(\DOMElement $element, string $rdfNs): bool {
		for ($node = $element->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
			if ($node->namespaceURI === $rdfNs && $node->localName === 'Description') {
				return true;
			}
		}
		return false;
	}

	/** @param list<array{label: string, value: string}> $entries */
	private static function xmpProperties(\DOMElement $description, string $prefix, string $rdfNs, array &$entries): void {
		foreach ($description->attributes ?? [] as $attribute) {
			if (!$attribute instanceof \DOMAttr || $attribute->namespaceURI === $rdfNs
				|| self::isXmlAttribute($attribute)) {
				continue;
			}
			self::addXmp($entries, $prefix . $attribute->nodeName, $attribute->value);
		}
		foreach ($description->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				self::xmpProperty($child, $prefix, $rdfNs, $entries);
			}
		}
	}

	/** @param list<array{label: string, value: string}> $entries */
	private static function xmpProperty(\DOMElement $property, string $prefix, string $rdfNs, array &$entries): void {
		$name = $prefix . $property->nodeName;
		$container = null;
		$nested = null;
		foreach ($property->childNodes as $child) {
			if (!$child instanceof \DOMElement) {
				continue;
			}
			if ($child->namespaceURI === $rdfNs && in_array($child->localName, ['Seq', 'Bag', 'Alt'], true)) {
				$container = $child;
			} elseif ($child->namespaceURI === $rdfNs && $child->localName === 'Description') {
				$nested = $child;
			} else {
				$nested ??= $property; // Struktur ohne rdf:Description (rdf:parseType="Resource")
			}
		}
		if ($container !== null) {
			$items = [];
			foreach ($container->childNodes as $li) {
				if (!$li instanceof \DOMElement) {
					continue;
				}
				$structure = null;
				foreach ($li->childNodes as $c) {
					if ($c instanceof \DOMElement) {
						$structure = $c->localName === 'Description' && $c->namespaceURI === $rdfNs ? $c : $li;
						break;
					}
				}
				if ($structure !== null || self::hasPropertyAttributes($li, $rdfNs)) {
					$sub = [];
					self::xmpProperties($structure ?? $li, '', $rdfNs, $sub);
					$items[] = implode('; ', array_map(static fn($e) => $e['label'] . '=' . $e['value'], $sub));
				} else {
					$items[] = trim($li->textContent);
				}
			}
			self::addXmp($entries, $name, implode(', ', array_filter($items, static fn($i) => $i !== '')));
			return;
		}
		if ($nested !== null) {
			self::xmpProperties($nested, $name . '/', $rdfNs, $entries);
			return;
		}
		if (self::hasPropertyAttributes($property, $rdfNs)) {
			self::xmpProperties($property, $name . '/', $rdfNs, $entries);
			return;
		}
		$resource = $property->getAttributeNS($rdfNs, 'resource');
		self::addXmp($entries, $name, $resource !== '' ? $resource : trim($property->textContent));
	}

	private static function hasPropertyAttributes(\DOMElement $element, string $rdfNs): bool {
		foreach ($element->attributes ?? [] as $attribute) {
			if ($attribute instanceof \DOMAttr && $attribute->namespaceURI !== $rdfNs
				&& !self::isXmlAttribute($attribute)) {
				return true;
			}
		}
		return false;
	}

	/** Namensraum-Deklarationen, xml:lang und Attribute ohne Präfix sind keine XMP-Eigenschaften. */
	private static function isXmlAttribute(\DOMAttr $attribute): bool {
		return $attribute->prefix === ''
			|| in_array($attribute->namespaceURI, ['http://www.w3.org/2000/xmlns/', 'http://www.w3.org/XML/1998/namespace'], true);
	}

	/** @param list<array{label: string, value: string}> $entries */
	private static function addXmp(array &$entries, string $label, string $value): void {
		$value = self::text($value);
		if ($value !== '') {
			$entries[] = ['label' => $label, 'value' => $value];
		}
	}

	// ── ID3 ────────────────────────────────────────────────────────────────

	/**
	 * @param resource $handle
	 * @return array{0: int, 1: list<array{label: string, value: string}>}
	 */
	private static function id3v2($handle, string $head, bool $seekable): array {
		if (strlen($head) < 10) {
			return [0, []];
		}
		$version = ord($head[3]);
		$flags = ord($head[5]);
		$tagSize = self::synchsafe(substr($head, 6, 4));
		if ($version < 2 || $version > 4 || $tagSize <= 0) {
			return [$version, []];
		}
		$tagSize = min($tagSize, self::MAX_BLOCK_BYTES);
		$tag = strlen($head) >= 10 + $tagSize ? substr($head, 10, $tagSize) : self::readAt($handle, 10, $tagSize, $seekable, $head);
		// Unsynchronisation auf Tag-Ebene (v2.2/v2.3): FF 00 → FF.
		if (($flags & 0x80) !== 0 && $version < 4) {
			$tag = str_replace("\xFF\x00", "\xFF", $tag);
		}
		$offset = 0;
		if (($flags & 0x40) !== 0 && $version >= 3 && strlen($tag) >= 4) {
			$extended = $version === 4 ? self::synchsafe(substr($tag, 0, 4)) : self::uint32(substr($tag, 0, 4)) + 4;
			$offset = $extended;
		}

		$entries = [];
		$idLength = $version === 2 ? 3 : 4;
		$headerLength = $version === 2 ? 6 : 10;
		while ($offset + $headerLength <= strlen($tag) && count($entries) < self::MAX_ENTRIES_PER_GROUP) {
			$id = substr($tag, $offset, $idLength);
			if (preg_match('/^[A-Z0-9]+$/', $id) !== 1) {
				break; // Padding
			}
			if ($version === 2) {
				$frameSize = (ord($tag[$offset + 3]) << 16) | (ord($tag[$offset + 4]) << 8) | ord($tag[$offset + 5]);
				$frameFlags = 0;
				$id = self::ID3_V22[$id] ?? $id;
			} else {
				$frameSize = $version === 4 ? self::synchsafe(substr($tag, $offset + 4, 4)) : self::uint32(substr($tag, $offset + 4, 4));
				$frameFlags = (ord($tag[$offset + 8]) << 8) | ord($tag[$offset + 9]);
			}
			$offset += $headerLength;
			if ($frameSize <= 0 || $offset + $frameSize > strlen($tag)) {
				break;
			}
			$body = substr($tag, $offset, $frameSize);
			$offset += $frameSize;

			if ($version === 4) {
				if (($frameFlags & 0x0001) !== 0) { // Datenlängen-Indikator
					$body = substr($body, 4);
				}
				if (($frameFlags & 0x0002) !== 0) { // Unsynchronisation des Frames
					$body = str_replace("\xFF\x00", "\xFF", $body);
				}
			}
			$compressed = $version === 4 ? ($frameFlags & 0x0008) !== 0 : ($frameFlags & 0x0080) !== 0;
			$encrypted = $version === 4 ? ($frameFlags & 0x0004) !== 0 : ($frameFlags & 0x0040) !== 0;
			if ($compressed || $encrypted) {
				$entries[] = ['label' => self::id3Label($id), 'value' => '[' . strlen($body) . ' bytes]'];
				continue;
			}
			$entry = self::id3Frame($id, $body, $version);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}
		return [$version, $entries];
	}

	/** @return array{label: string, value: string}|null */
	private static function id3Frame(string $id, string $body, int $version): ?array {
		if ($body === '') {
			return null;
		}
		$label = self::id3Label($id);
		if ($id === 'TXXX' || $id === 'WXXX') {
			$encoding = ord($body[0]);
			[$description, $rest] = self::id3SplitTerminated(substr($body, 1), $encoding);
			$value = $id === 'WXXX' ? self::latin1($rest) : self::id3Text($rest, $encoding);
			return ['label' => $label . ($description !== '' ? ' (' . $description . ')' : ''), 'value' => self::text($value)];
		}
		if ($id[0] === 'T') {
			$encoding = ord($body[0]);
			$value = self::id3Text(substr($body, 1), $encoding);
			if ($id === 'TCON') {
				$value = self::id3Genre($value);
			}
			$value = self::text($value);
			return $value === '' ? null : ['label' => $label, 'value' => $value];
		}
		if ($id[0] === 'W') {
			$value = self::text(self::latin1(rtrim($body, "\0")));
			return $value === '' ? null : ['label' => $label, 'value' => $value];
		}
		if ($id === 'COMM' || $id === 'USLT') {
			if (strlen($body) < 4) {
				return null;
			}
			$encoding = ord($body[0]);
			$language = trim(substr($body, 1, 3), "\0 ");
			[$description, $text] = self::id3SplitTerminated(substr($body, 4), $encoding);
			$value = self::text(self::id3Text($text, $encoding));
			$suffix = trim(($description !== '' ? $description : '') . ($language !== '' && $language !== 'XXX' ? ' ' . $language : ''));
			return $value === '' ? null : ['label' => $label . ($suffix !== '' ? ' (' . $suffix . ')' : ''), 'value' => $value];
		}
		if ($id === 'APIC') {
			$encoding = ord($body[0]);
			if ($version === 2) {
				$mime = 'image/' . strtolower(substr($body, 1, 3));
				$rest = substr($body, 5);
			} else {
				$nul = strpos($body, "\0", 1);
				$mime = $nul === false ? '' : substr($body, 1, $nul - 1);
				$rest = $nul === false ? '' : substr($body, $nul + 2);
			}
			[$description, $image] = self::id3SplitTerminated($rest, $encoding);
			$parts = array_filter([$description, $mime, self::bytes(strlen($image))], static fn($p) => $p !== '');
			return ['label' => $label, 'value' => implode(' · ', $parts)];
		}
		if ($id === 'PCNT') {
			return ['label' => $label, 'value' => (string) self::uintBig($body)];
		}
		if ($id === 'POPM') {
			[$email, $rest] = self::id3SplitTerminated($body, 0);
			$rating = $rest !== '' ? ord($rest[0]) : 0;
			return ['label' => $label, 'value' => trim($email . ' ' . $rating . '/255')];
		}
		if ($id === 'UFID' || $id === 'PRIV') {
			[$owner, $data] = self::id3SplitTerminated($body, 0);
			return ['label' => $label, 'value' => trim($owner . ' [' . strlen($data) . ' bytes]')];
		}
		if ($id === 'CHAP') {
			[$elementId, $rest] = self::id3SplitTerminated($body, 0);
			if (strlen($rest) < 8) {
				return ['label' => $label, 'value' => $elementId];
			}
			$start = self::uint32(substr($rest, 0, 4));
			$end = self::uint32(substr($rest, 4, 4));
			$title = '';
			if (preg_match('/TIT2(.{4})(.{2})(.)/s', substr($rest, 16), $m, PREG_OFFSET_CAPTURE) === 1) {
				$size = $version === 4 ? self::synchsafe($m[1][0]) : self::uint32($m[1][0]);
				$title = self::id3Text(substr($rest, 16 + $m[3][1] + 1, max(0, $size - 1)), ord($m[3][0]));
			}
			return ['label' => $label . ' ' . $elementId, 'value' => self::text(trim(self::milliseconds($start) . '–' . self::milliseconds($end) . ' ' . $title))];
		}
		return ['label' => $label, 'value' => '[' . strlen($body) . ' bytes]'];
	}

	private static function id3Label(string $id): string {
		return isset(self::ID3_NAMES[$id]) ? self::ID3_NAMES[$id] . ' (' . $id . ')' : $id;
	}

	/** TCON: "(17)Rock", "17", "(RX)" → Klartext. */
	private static function id3Genre(string $value): string {
		return (string) preg_replace_callback('/^\((\d+)\)|^(\d+)$/', static function (array $m): string {
			$index = (int) ($m[1] !== '' ? $m[1] : $m[2]);
			return self::ID3V1_GENRES[$index] ?? $m[0];
		}, $value);
	}

	/** Text in einer ID3-Kodierung; mehrere Werte (v2.4, NUL-getrennt) mit " / ". */
	private static function id3Text(string $bytes, int $encoding): string {
		$parts = [];
		foreach (self::id3SplitAll($bytes, $encoding) as $part) {
			$parts[] = match ($encoding) {
				1 => self::utf16($part, null),
				2 => self::utf16($part, 'BE'),
				3 => self::toUtf8($part),
				default => self::latin1($part),
			};
		}
		return implode(' / ', array_filter($parts, static fn($p) => $p !== ''));
	}

	/** @return list<string> */
	private static function id3SplitAll(string $bytes, int $encoding): array {
		$parts = [];
		while ($bytes !== '') {
			[$part, $bytes] = self::id3SplitRaw($bytes, $encoding);
			$parts[] = $part;
		}
		return $parts;
	}

	/** Bis zum Terminator (1 oder 2 NUL je nach Kodierung) dekodiert, Rest roh. */
	private static function id3SplitTerminated(string $bytes, int $encoding): array {
		[$first, $rest] = self::id3SplitRaw($bytes, $encoding);
		return [self::text(self::id3Text($first, $encoding)), $rest];
	}

	/** @return array{0: string, 1: string} */
	private static function id3SplitRaw(string $bytes, int $encoding): array {
		if ($encoding === 1 || $encoding === 2) {
			for ($i = 0; $i + 1 < strlen($bytes); $i += 2) {
				if ($bytes[$i] === "\0" && $bytes[$i + 1] === "\0") {
					return [substr($bytes, 0, $i), substr($bytes, $i + 2)];
				}
			}
			return [$bytes, ''];
		}
		$nul = strpos($bytes, "\0");
		return $nul === false ? [$bytes, ''] : [substr($bytes, 0, $nul), substr($bytes, $nul + 1)];
	}

	/** @return list<array{label: string, value: string}> */
	private static function id3v1(string $block): array {
		if (!str_starts_with($block, 'TAG')) {
			return [];
		}
		// Eigentlich Latin-1, manche Programme (ffmpeg) schreiben UTF-8.
		$field = static fn(int $offset, int $length): string => self::text(self::toUtf8(rtrim(substr($block, $offset, $length), "\0 ")));
		$entries = [];
		foreach (['Title' => [3, 30], 'Artist' => [33, 30], 'Album' => [63, 30], 'Year' => [93, 4]] as $label => [$offset, $length]) {
			if (($value = $field($offset, $length)) !== '') {
				$entries[] = ['label' => $label, 'value' => $value];
			}
		}
		// ID3v1.1: Track in Byte 126, wenn Byte 125 NUL ist.
		$hasTrack = $block[125] === "\0" && $block[126] !== "\0";
		if (($comment = $field(97, $hasTrack ? 28 : 30)) !== '') {
			$entries[] = ['label' => 'Comment', 'value' => $comment];
		}
		if ($hasTrack) {
			$entries[] = ['label' => 'Track', 'value' => (string) ord($block[126])];
		}
		$genre = ord($block[127]);
		if (isset(self::ID3V1_GENRES[$genre])) {
			$entries[] = ['label' => 'Genre', 'value' => self::ID3V1_GENRES[$genre]];
		}
		return $entries;
	}

	// ── QuickTime / MP4 ────────────────────────────────────────────────────

	private static function isMp4(string $head): bool {
		return strlen($head) >= 12 && in_array(substr($head, 4, 4), ['ftyp', 'moov', 'mdat', 'wide', 'free', 'skip'], true)
			&& (substr($head, 4, 4) === 'ftyp' || str_contains(substr($head, 0, 64 * 1024), 'moov'));
	}

	/**
	 * Sucht das moov-Atom auf oberster Ebene (springt über mdat hinweg) und
	 * liest daraus Erstellungszeit, Dauer, Bildgröße, iTunes-Tags (ilst),
	 * Apple-Metadaten (keys/mdta) und udta-Einträge wie ©xyz (Standort).
	 *
	 * @param resource $handle
	 * @return list<array{label: string, value: string}>
	 */
	private static function mp4($handle, int $size, bool $seekable, string $head): array {
		$entries = [];
		if (substr($head, 4, 4) === 'ftyp') {
			$length = self::uint32(substr($head, 0, 4));
			$brand = trim(substr($head, 8, 4));
			$compatible = [];
			for ($i = 16; $i + 4 <= min($length, strlen($head)); $i += 4) {
				$compatible[] = trim(substr($head, $i, 4));
			}
			$entries[] = ['label' => 'Major brand', 'value' => self::text($brand)];
			if ($compatible !== []) {
				$entries[] = ['label' => 'Compatible brands', 'value' => self::text(implode(', ', array_filter($compatible)))];
			}
		}

		$offset = 0;
		$moov = null;
		while ($offset + 8 <= $size) {
			$header = self::readAt($handle, $offset, 16, $seekable, $head);
			if (strlen($header) < 8) {
				break;
			}
			$length = self::uint32(substr($header, 0, 4));
			$type = substr($header, 4, 4);
			$headerLength = 8;
			if ($length === 1 && strlen($header) >= 16) {
				$length = self::uint64(substr($header, 8, 8));
				$headerLength = 16;
			} elseif ($length === 0) {
				$length = $size - $offset;
			}
			if ($length < $headerLength) {
				break;
			}
			if ($type === 'moov') {
				if ($length - $headerLength <= self::MAX_BLOCK_BYTES) {
					$moov = self::readAt($handle, $offset + $headerLength, $length - $headerLength, $seekable, $head);
				}
				break;
			}
			$offset += $length;
		}
		if ($moov === null || $moov === '') {
			return $entries;
		}

		$state = ['keys' => [], 'tracks' => []];
		self::mp4Walk($moov, 'moov', $entries, $state);
		foreach ($state['tracks'] as $index => $dimensions) {
			$entries[] = ['label' => 'Track ' . ($index + 1) . ' size', 'value' => $dimensions];
		}
		return array_slice($entries, 0, self::MAX_ENTRIES_PER_GROUP);
	}

	/**
	 * @param list<array{label: string, value: string}> $entries
	 * @param array{keys: array<int, string>, tracks: list<string>} $state
	 */
	private static function mp4Walk(string $data, string $parent, array &$entries, array &$state): void {
		$offset = 0;
		$length = strlen($data);
		while ($offset + 8 <= $length) {
			$atomLength = self::uint32(substr($data, $offset, 4));
			$type = substr($data, $offset + 4, 4);
			$headerLength = 8;
			if ($atomLength === 1 && $offset + 16 <= $length) {
				$atomLength = self::uint64(substr($data, $offset + 8, 8));
				$headerLength = 16;
			} elseif ($atomLength === 0) {
				$atomLength = $length - $offset;
			}
			if ($atomLength < $headerLength || $offset + $atomLength > $length) {
				break;
			}
			$body = substr($data, $offset + $headerLength, $atomLength - $headerLength);
			$offset += $atomLength;

			if (in_array($type, self::MP4_CONTAINERS, true)) {
				self::mp4Walk($body, $type, $entries, $state);
			} elseif ($type === 'meta') {
				// ISO-meta hat einen Version/Flags-Header, QuickTime-meta nicht.
				$inner = (strlen($body) >= 8 && substr($body, 4, 4) === 'hdlr') ? $body : substr($body, 4);
				self::mp4Walk($inner, 'meta', $entries, $state);
			} elseif ($type === 'mvhd') {
				self::mp4Movie($body, $entries);
			} elseif ($type === 'tkhd') {
				self::mp4Track($body, $state);
			} elseif ($type === 'keys') {
				$state['keys'] = self::mp4Keys($body);
			} elseif ($type === 'ilst') {
				self::mp4Items($body, $entries, $state['keys']);
			} elseif ($parent === 'udta' && $type[0] === "\xA9") {
				// QuickTime-Benutzerdaten: 16-Bit-Länge, 16-Bit-Sprache, Text.
				$text = strlen($body) >= 4 ? substr($body, 4, self::uint16(substr($body, 0, 2))) : '';
				$value = self::text(self::toUtf8($text));
				if ($value !== '') {
					$entries[] = ['label' => self::mp4Label($type), 'value' => $value];
				}
			}
		}
	}

	/** @param list<array{label: string, value: string}> $entries */
	private static function mp4Movie(string $body, array &$entries): void {
		if (strlen($body) < 20) {
			return;
		}
		$version = ord($body[0]);
		if ($version === 1 && strlen($body) >= 32) {
			$created = self::uint64(substr($body, 4, 8));
			$modified = self::uint64(substr($body, 12, 8));
			$timescale = self::uint32(substr($body, 20, 4));
			$duration = self::uint64(substr($body, 24, 8));
		} else {
			$created = self::uint32(substr($body, 4, 4));
			$modified = self::uint32(substr($body, 8, 4));
			$timescale = self::uint32(substr($body, 12, 4));
			$duration = self::uint32(substr($body, 16, 4));
		}
		// QuickTime-Zeit: Sekunden seit 1904-01-01 UTC.
		$epoch = 2082844800;
		if ($created > $epoch) {
			$entries[] = ['label' => 'Creation date', 'value' => gmdate('Y-m-d H:i:s', $created - $epoch) . ' UTC'];
		}
		if ($modified > $epoch && $modified !== $created) {
			$entries[] = ['label' => 'Modification date', 'value' => gmdate('Y-m-d H:i:s', $modified - $epoch) . ' UTC'];
		}
		if ($timescale > 0 && $duration > 0) {
			$entries[] = ['label' => 'Duration', 'value' => self::milliseconds((int) round($duration * 1000 / $timescale))];
		}
	}

	/** @param array{keys: array<int, string>, tracks: list<string>} $state */
	private static function mp4Track(string $body, array &$state): void {
		$version = ord($body[0] ?? "\0");
		$offset = $version === 1 ? 88 : 76;
		if (strlen($body) < $offset + 8) {
			return;
		}
		$width = self::uint32(substr($body, $offset, 4)) >> 16;
		$height = self::uint32(substr($body, $offset + 4, 4)) >> 16;
		if ($width > 0 && $height > 0) {
			$state['tracks'][] = $width . ' × ' . $height;
		}
	}

	/** @return array<int, string> Index (ab 1) → Schlüsselname (com.apple.quicktime.…) */
	private static function mp4Keys(string $body): array {
		$keys = [];
		$count = strlen($body) >= 8 ? self::uint32(substr($body, 4, 4)) : 0;
		$offset = 8;
		for ($i = 1; $i <= $count && $offset + 8 <= strlen($body); $i++) {
			$length = self::uint32(substr($body, $offset, 4));
			if ($length < 8) {
				break;
			}
			$keys[$i] = substr($body, $offset + 8, $length - 8);
			$offset += $length;
		}
		return $keys;
	}

	/**
	 * @param list<array{label: string, value: string}> $entries
	 * @param array<int, string> $keys
	 */
	private static function mp4Items(string $body, array &$entries, array $keys): void {
		$offset = 0;
		while ($offset + 8 <= strlen($body)) {
			$length = self::uint32(substr($body, $offset, 4));
			if ($length < 8 || $offset + $length > strlen($body)) {
				break;
			}
			$type = substr($body, $offset + 4, 4);
			$item = substr($body, $offset + 8, $length - 8);
			$offset += $length;

			if ($type === '----') {
				// Freie iTunes-Tags: mean (Namensraum), name, data.
				$name = '';
				$value = null;
				foreach (self::mp4Children($item) as [$childType, $childBody]) {
					if ($childType === 'name') {
						$name = substr($childBody, 4);
					} elseif ($childType === 'data') {
						$value = self::mp4Data($childBody, '----');
					}
				}
				if ($value !== null && $value !== '') {
					$entries[] = ['label' => self::text($name !== '' ? $name : '----'), 'value' => $value];
				}
				continue;
			}

			$index = self::uint32($type);
			$label = isset($keys[$index]) && !isset(self::MP4_NAMES[$type]) ? $keys[$index] : self::mp4Label($type);
			$values = [];
			foreach (self::mp4Children($item) as [$childType, $childBody]) {
				if ($childType === 'data' && ($value = self::mp4Data($childBody, $type)) !== null && $value !== '') {
					$values[] = $value;
				}
			}
			if ($values !== []) {
				$entries[] = ['label' => self::text($label), 'value' => implode(', ', $values)];
			}
		}
	}

	/** @return list<array{0: string, 1: string}> */
	private static function mp4Children(string $data): array {
		$children = [];
		$offset = 0;
		while ($offset + 8 <= strlen($data)) {
			$length = self::uint32(substr($data, $offset, 4));
			if ($length < 8 || $offset + $length > strlen($data)) {
				break;
			}
			$children[] = [substr($data, $offset + 4, 4), substr($data, $offset + 8, $length - 8)];
			$offset += $length;
		}
		return $children;
	}

	/** Inhalt eines data-Atoms (4 Byte Typ, 4 Byte Locale, Wert). */
	private static function mp4Data(string $body, string $type): ?string {
		if (strlen($body) < 8) {
			return null;
		}
		$dataType = self::uint32(substr($body, 0, 4)) & 0xFFFFFF;
		$value = substr($body, 8);
		if ($type === 'trkn' || $type === 'disk') {
			if (strlen($value) < 6) {
				return null;
			}
			$number = self::uint16(substr($value, 2, 2));
			$total = self::uint16(substr($value, 4, 2));
			return $total > 0 ? $number . '/' . $total : (string) $number;
		}
		if ($type === 'gnre' && strlen($value) >= 2) {
			return self::ID3V1_GENRES[self::uint16($value) - 1] ?? (string) self::uint16($value);
		}
		return match ($dataType) {
			1, 4 => self::text(self::toUtf8($value)),                         // UTF-8
			2, 5 => self::text(self::utf16($value, 'BE')),                    // UTF-16
			13, 14, 27 => self::bytes(strlen($value)) . ' ' . match ($dataType) { 13 => 'JPEG', 14 => 'PNG', default => 'BMP' },
			21 => (string) self::intBig($value, true),                       // vorzeichenbehaftet
			22 => (string) self::uintBig($value),
			23 => strlen($value) === 4 ? (string) round(unpack('G', $value)[1], 6) : null,
			24 => strlen($value) === 8 ? (string) round(unpack('E', $value)[1], 6) : null,
			0 => in_array($type, ['cpil', 'pgap', 'tmpo', 'stik', 'tvsn', 'tves', 'rtng'], true)
				? (string) self::uintBig($value)
				: (self::isPrintable($value) ? self::text($value) : '[' . strlen($value) . ' bytes]'),
			default => self::isPrintable($value) ? self::text(self::toUtf8($value)) : '[' . strlen($value) . ' bytes]',
		};
	}

	private static function mp4Label(string $type): string {
		$name = self::MP4_NAMES[$type] ?? null;
		$code = str_replace("\xA9", '©', $type);
		$code = self::toUtf8($code);
		return $name !== null ? $name . ' (' . $code . ')' : $code;
	}

	// ── PDF ────────────────────────────────────────────────────────────────

	/** @return list<array{label: string, value: string}> */
	private static function pdf(string $head, string $tail): array {
		$entries = [];
		if (preg_match('/^%PDF-(\d\.\d)/', $head, $m) === 1) {
			$entries[] = ['label' => 'Version', 'value' => $m[1]];
		}
		$seen = [];
		foreach (['Title', 'Author', 'Subject', 'Keywords', 'Creator', 'Producer', 'CreationDate', 'ModDate', 'Trapped'] as $key) {
			foreach ([$tail, $head] as $bytes) {
				if ($bytes === '' || isset($seen[$key])) {
					continue;
				}
				$value = self::pdfValue($bytes, $key);
				if ($value !== null && $value !== '') {
					$seen[$key] = true;
					if (in_array($key, ['CreationDate', 'ModDate'], true)) {
						$value = self::pdfDate($value);
					}
					$entries[] = ['label' => $key, 'value' => self::text($value)];
				}
			}
		}
		if (preg_match_all('#/Type\s*/Page[^s]#', $head . $tail) > 0 && preg_match('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)#s', $head . $tail, $m) === 1) {
			$entries[] = ['label' => 'Pages', 'value' => $m[1]];
		}
		if (str_contains($head, '/Encrypt') || str_contains($tail, '/Encrypt')) {
			$entries[] = ['label' => 'Encrypted', 'value' => 'yes'];
		}
		return $entries;
	}

	/** Letzter Wert von /Key (…) oder /Key <hex> (der Trailer steht am Ende). */
	private static function pdfValue(string $bytes, string $key): ?string {
		$pattern = '#/' . $key . '\s*(\((?:\\\\.|[^\\\\)]|\((?:\\\\.|[^\\\\)])*\))*\)|<[0-9A-Fa-f\s]*>|/[^\s/<>()\[\]]+)#s';
		if (preg_match_all($pattern, $bytes, $matches) < 1) {
			return null;
		}
		$raw = end($matches[1]);
		if ($raw[0] === '/') {
			return substr($raw, 1);
		}
		if ($raw[0] === '<') {
			$hex = preg_replace('/\s+/', '', substr($raw, 1, -1)) ?? '';
			$binary = (string) @hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);
			return self::pdfString($binary);
		}
		$literal = substr($raw, 1, -1);
		$decoded = preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r?\n)/', static function (array $m): string {
			$c = $m[1];
			return match (true) {
				$c === 'n' => "\n", $c === 'r' => "\r", $c === 't' => "\t", $c === 'b' => "\x08", $c === 'f' => "\x0C",
				$c === "\n" || $c === "\r\n" => '',
				ctype_digit($c) => chr(octdec($c) & 0xFF),
				default => $c,
			};
		}, $literal);
		return self::pdfString((string) $decoded);
	}

	/** PDF-Textstring: UTF-16BE mit BOM, UTF-8 mit BOM (PDF 2.0) oder PDFDocEncoding (~Latin-1). */
	private static function pdfString(string $bytes): string {
		if (str_starts_with($bytes, "\xFE\xFF")) {
			return self::utf16(substr($bytes, 2), 'BE');
		}
		if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
			return substr($bytes, 3);
		}
		return self::toUtf8($bytes);
	}

	/** D:YYYYMMDDHHmmSSOHH'mm → YYYY-MM-DD HH:mm:ss ±HH:mm */
	private static function pdfDate(string $value): string {
		if (preg_match("/^D?:?(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?([Zz+\-])?(\d{2})?'?(\d{2})?/", $value, $m) !== 1) {
			return $value;
		}
		$date = $m[1] . '-' . ($m[2] ?? '' ?: '01') . '-' . ($m[3] ?? '' ?: '01');
		if (($m[4] ?? '') !== '') {
			$date .= ' ' . $m[4] . ':' . (($m[5] ?? '') ?: '00') . ':' . (($m[6] ?? '') ?: '00');
		}
		$zone = $m[7] ?? '';
		if ($zone === 'Z' || $zone === 'z') {
			$date .= ' UTC';
		} elseif ($zone !== '' && ($m[8] ?? '') !== '') {
			$date .= ' ' . $zone . $m[8] . ':' . (($m[9] ?? '') ?: '00');
		}
		return $date;
	}

	// ── Hilfen ─────────────────────────────────────────────────────────────

	/**
	 * @param list<array{label: string, value: string}> $entries
	 * @return array{key: string, title: string, entries: list<array{label: string, value: string}>}
	 */
	private static function group(string $key, string $title, array $entries): array {
		return ['key' => $key, 'title' => $title, 'entries' => array_slice($entries, 0, self::MAX_ENTRIES_PER_GROUP)];
	}

	/**
	 * Liest $length Bytes ab $offset. Ohne Seek-Möglichkeit nur aus dem bereits
	 * gelesenen Kopf.
	 *
	 * @param resource $handle
	 */
	private static function readAt($handle, int $offset, int $length, bool $seekable, string $head = ''): string {
		if ($length <= 0) {
			return '';
		}
		if (!$seekable) {
			if ($offset === 0 && $head === '') {
				$data = '';
				while (strlen($data) < $length && !feof($handle)) {
					$chunk = fread($handle, min(1024 * 1024, $length - strlen($data)));
					if ($chunk === false || $chunk === '') {
						break;
					}
					$data .= $chunk;
				}
				return $data;
			}
			return substr($head, $offset, $length);
		}
		if (fseek($handle, $offset) !== 0) {
			return '';
		}
		$data = '';
		while (strlen($data) < $length && !feof($handle)) {
			$chunk = fread($handle, min(1024 * 1024, $length - strlen($data)));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
		}
		return $data;
	}

	/** Gültiges UTF-8 ohne Steuerzeichen, auf MAX_VALUE_LENGTH gekürzt. */
	private static function text(string $value): string {
		$value = self::toUtf8($value);
		$value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value);
		$value = trim($value);
		if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
			$value = rtrim(mb_substr($value, 0, self::MAX_VALUE_LENGTH)) . '…';
		}
		return $value;
	}

	private static function toUtf8(string $value): string {
		if (mb_check_encoding($value, 'UTF-8')) {
			return $value;
		}
		return self::latin1($value);
	}

	private static function latin1(string $value): string {
		return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
	}

	/** UTF-16 mit BOM ($order null) bzw. ohne BOM in der angegebenen Bytefolge. */
	private static function utf16(string $bytes, ?string $order): string {
		if ($order === null) {
			if (str_starts_with($bytes, "\xFF\xFE")) {
				$order = 'LE';
				$bytes = substr($bytes, 2);
			} elseif (str_starts_with($bytes, "\xFE\xFF")) {
				$order = 'BE';
				$bytes = substr($bytes, 2);
			} else {
				$order = 'LE';
			}
		}
		if (strlen($bytes) % 2 === 1) {
			$bytes = substr($bytes, 0, -1);
		}
		$converted = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16' . $order);
		return is_string($converted) ? $converted : '';
	}

	private static function isPrintable(string $value): bool {
		return $value !== '' && mb_check_encoding($value, 'UTF-8') && preg_match('/[\x00-\x08\x0E-\x1F]/', $value) !== 1;
	}

	private static function synchsafe(string $bytes): int {
		if (strlen($bytes) < 4) {
			return 0;
		}
		return ((ord($bytes[0]) & 0x7F) << 21) | ((ord($bytes[1]) & 0x7F) << 14) | ((ord($bytes[2]) & 0x7F) << 7) | (ord($bytes[3]) & 0x7F);
	}

	private static function uint16(string $bytes): int {
		return strlen($bytes) >= 2 ? unpack('n', $bytes)[1] : 0;
	}

	private static function uint32(string $bytes): int {
		return strlen($bytes) >= 4 ? unpack('N', $bytes)[1] : 0;
	}

	private static function uint64(string $bytes): int {
		return strlen($bytes) >= 8 ? unpack('J', $bytes)[1] : 0;
	}

	/** Vorzeichenlose Big-Endian-Zahl beliebiger Länge (bis 8 Byte). */
	private static function uintBig(string $bytes): int {
		$value = 0;
		foreach (str_split(substr($bytes, 0, 8)) as $byte) {
			$value = ($value << 8) | ord($byte);
		}
		return $value;
	}

	private static function intBig(string $bytes, bool $signed): int {
		$bytes = substr($bytes, 0, 8);
		$value = self::uintBig($bytes);
		$bits = strlen($bytes) * 8;
		if ($signed && $bits > 0 && $bits < 64 && ($value & (1 << ($bits - 1))) !== 0) {
			$value -= 1 << $bits;
		}
		return $value;
	}

	private static function bytes(int $count): string {
		if ($count >= 1024 * 1024) {
			return round($count / 1024 / 1024, 1) . ' MB';
		}
		if ($count >= 1024) {
			return round($count / 1024) . ' KB';
		}
		return $count . ' B';
	}

	private static function milliseconds(int $ms): string {
		$seconds = intdiv($ms, 1000);
		return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
	}
}
