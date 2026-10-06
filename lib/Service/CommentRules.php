<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

/**
 * Reine Regeln für Kommentare und Gast-Namen, frei von Nextcloud-Abhängigkeiten
 * (getestet in tools/test-comments.php).
 *
 * Gäste weisen sich allein über ihren Namen aus: wer denselben Namen angibt,
 * darf alles bearbeiten und löschen, was unter diesem Namen steht. Verglichen
 * wird über nameKey(), damit "Anna", " anna " und "ANNA" derselbe Gast sind.
 */
final class CommentRules {
	public const NAME_MIN = 2;
	public const NAME_MAX = 50;
	public const BODY_MAX = 5000;
	public const HIGHLIGHT_TEXT_MAX = 2000;
	/** Markierungen des Besitzers dürfen länger sein (ganze Absätze). */
	public const OWNER_HIGHLIGHT_TEXT_MAX = 20000;
	public const XPATH_MAX = 1000;
	public const OFFSET_MAX = 1_000_000;
	public const COLORS = ['yellow', 'green', 'blue', 'pink', 'orange'];
	/**
	 * "Farbe" einer Textstelle, die nur für einen Kommentar angelegt wurde:
	 * die Clients unterstreichen sie, statt sie einzufärben. Sie entsteht nur
	 * zusammen mit ihrem ersten Kommentar und verschwindet mit dem letzten.
	 */
	public const COLOR_COMMENT = 'comment';
	/**
	 * Verfasser-Farben: der Besitzer ist immer orange, jeder Gast-Name hat an
	 * einem Artikel eine eigene Farbe aus GUEST_COLORS, die kein anderer Name
	 * dort hat. Die Farbe färbt die Unterstreichung seiner Kommentar-Stellen,
	 * den Zähler daran und seine Kommentare. Alle Töne tragen weiße Schrift
	 * mit einem Kontrast von mindestens 5:1 (Zähler-Plakette).
	 */
	public const OWNER_COLOR = '#c2410c';
	public const GUEST_COLORS = [
		'#1d4ed8', '#15803d', '#7e22ce', '#be185d', '#0e7490', '#b91c1c',
		'#4338ca', '#0f766e', '#a21caf', '#4d7c0f', '#6d28d9', '#0369a1',
		'#be123c', '#047857', '#854d0e', '#475569',
	];
	/** Für Gäste ohne Eintrag (alle Farben vergeben). */
	public const FALLBACK_COLOR = '#57534e';
	/** Höchstens so viele Gast-Kommentare bzw. -Markierungen je Artikel und Tag. */
	public const GUEST_DAILY_CAP = 500;

	/**
	 * Bereinigt Text aus Nutzereingaben, bevor er gespeichert wird:
	 * - ungültiges UTF-8 wird ersetzt (sonst scheitert json_encode und damit
	 *   die Auslieferung an alle Leser),
	 * - Unicode-NFC, damit gleich aussehende Namen gleich verglichen werden,
	 * - Steuerzeichen (\p{Cc}) fallen weg, bei $multiline bleiben \n und \t,
	 * - unsichtbare Formatzeichen (\p{Cf}: Bidi-Umkehr, Nullbreiten-Leerzeichen,
	 *   BOM, Tag-Zeichen …) fallen weg; nur ZWNJ/ZWJ bleiben, die braucht
	 *   Schrift (Persisch, Emoji-Sequenzen).
	 *
	 * HTML wird hier bewusst nicht entfernt: Kommentare sind Klartext, und
	 * jede Ausgabe (Vue-Interpolation, textContent, SwiftUI Text, JSON)
	 * maskiert ihn. So bleibt "a < b" lesbar.
	 */
	public static function sanitizeText(string $text, bool $multiline): string {
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}
		if (class_exists(\Normalizer::class)) {
			$text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
		}
		if ($multiline) {
			$text = str_replace(["\r\n", "\r"], "\n", $text);
			$text = preg_replace('/[^\P{Cc}\n\t]/u', '', $text) ?? '';
		} else {
			$text = preg_replace('/\p{Cc}/u', ' ', $text) ?? '';
		}
		return preg_replace('/[^\P{Cf}\x{200C}\x{200D}]/u', '', $text) ?? '';
	}

	/**
	 * Name bereinigt (sanitizeText), dazu ohne spitze Klammern – ein Name
	 * braucht kein Markup-Zeichen –, Leerraum zu einem Leerzeichen, getrimmt.
	 */
	public static function normalizeName(string $name): string {
		$name = self::sanitizeText($name, false);
		$name = str_replace(['<', '>'], '', $name);
		$name = preg_replace('/\s+/u', ' ', $name) ?? '';
		return trim($name);
	}

	/** Die angefragte Farbe, wenn sie zur Gast-Palette gehört, sonst null. */
	public static function sanitizeGuestColor(?string $color): ?string {
		$color = strtolower(trim((string) $color));
		return in_array($color, self::GUEST_COLORS, true) ? $color : null;
	}

	/**
	 * Erste Farbe der Gast-Palette, die nicht in $taken steht, oder null.
	 *
	 * @param string[] $taken
	 */
	public static function firstFreeColor(array $taken): ?string {
		foreach (self::GUEST_COLORS as $color) {
			if (!in_array($color, $taken, true)) {
				return $color;
			}
		}
		return null;
	}

	public static function nameKey(string $name): string {
		return mb_strtolower(self::normalizeName($name), 'UTF-8');
	}

	/**
	 * @return string|null Fehlercode oder null, wenn der Name gültig ist
	 */
	public static function validateName(string $name): ?string {
		$length = mb_strlen(self::normalizeName($name), 'UTF-8');
		if ($length < self::NAME_MIN) {
			return 'name_too_short';
		}
		if ($length > self::NAME_MAX) {
			return 'name_too_long';
		}
		return null;
	}

	/**
	 * Darf ein Gast diesen Namen tragen? Den Namen des Besitzers (Anzeigename
	 * oder Nutzer-ID) nicht, sonst könnte er sich als Autor des Links ausgeben.
	 *
	 * @param string[] $reserved
	 */
	public static function isReservedName(string $name, array $reserved): bool {
		$key = self::nameKey($name);
		foreach ($reserved as $r) {
			if ($r !== '' && self::nameKey($r) === $key) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Zeilenumbrüche bleiben, andere Steuerzeichen fallen weg; Text wird nur
	 * als Klartext ausgegeben (nie als HTML).
	 */
	public static function normalizeBody(string $body): string {
		$body = self::sanitizeText($body, true);
		$body = preg_replace("/\n{3,}/", "\n\n", $body) ?? '';
		return trim($body);
	}

	/**
	 * Markierter Text: bereinigt wie ein Kommentar, Zeilenumbrüche bleiben.
	 */
	public static function normalizeHighlightText(string $text): string {
		return trim(self::sanitizeText($text, true));
	}

	/**
	 * Nur die Form, die die Clients erzeugen und auflösen: Schritte
	 * `tag[n]` oder `text()[n]`, durch `/` getrennt (siehe getXPath() in
	 * highlight-engine.js und im iOS-Reader).
	 */
	public static function isValidXpath(string $xpath): bool {
		if ($xpath === '' || strlen($xpath) > self::XPATH_MAX) {
			return false;
		}
		// Tag-Namen: Kleinbuchstaben wie von getXPath() erzeugt, inkl.
		// Custom Elements (merlin-inline-player) und Namespaces (o:p).
		$step = '(?:[a-z][a-z0-9:_.-]{0,40}|text\(\))\[[1-9][0-9]{0,5}\]';
		return preg_match('~^' . $step . '(?:/' . $step . ')*$~', $xpath) === 1;
	}

	/**
	 * @return string|null Fehlercode oder null, wenn die Markierung gültig ist
	 */
	public static function validateHighlight(string $text, string $startXpath, int $startOffset, string $endXpath, int $endOffset, int $maxText): ?string {
		$text = self::normalizeHighlightText($text);
		if ($text === '' || mb_strlen($text, 'UTF-8') > $maxText) {
			return 'highlight_invalid';
		}
		if (!self::isValidXpath($startXpath) || !self::isValidXpath($endXpath)) {
			return 'highlight_invalid';
		}
		foreach ([$startOffset, $endOffset] as $offset) {
			if ($offset < 0 || $offset > self::OFFSET_MAX) {
				return 'highlight_invalid';
			}
		}
		return null;
	}

	/** Unbekannte Farben werden gelb (nur Namen aus der festen Liste). */
	public static function sanitizeColor(string $color): string {
		return in_array($color, self::COLORS, true) || $color === self::COLOR_COMMENT ? $color : 'yellow';
	}

	/** Änderungsmarke aus ?since= / Last-Event-ID: nur Hex, sonst leer. */
	public static function sanitizeSignature(string $since): string {
		return preg_match('/^[0-9a-f]{1,40}$/', $since) === 1 ? $since : '';
	}

	/**
	 * @return string|null Fehlercode oder null
	 */
	public static function validateBody(string $body): ?string {
		$length = mb_strlen(self::normalizeBody($body), 'UTF-8');
		if ($length === 0) {
			return 'body_empty';
		}
		if ($length > self::BODY_MAX) {
			return 'body_too_long';
		}
		return null;
	}

	/**
	 * Darf der Gast mit $guestName diesen Beitrag ändern? Nur Gast-Beiträge
	 * und nur bei gleichem Namensschlüssel; Beiträge des Besitzers nie.
	 */
	public static function guestMayEdit(string $authorType, ?string $authorNameKey, string $guestName): bool {
		if ($authorType !== 'guest' || $authorNameKey === null || $authorNameKey === '') {
			return false;
		}
		$key = self::nameKey($guestName);
		return $key !== '' && hash_equals($authorNameKey, $key);
	}

	/**
	 * Wurzel und direkter Adressat für eine Antwort auf $parent. Antworten auf
	 * Antworten hängen an derselben Wurzel (eine Antwortebene wie in einem
	 * Thread), reply_to_id merkt sich den direkten Adressaten.
	 *
	 * @return array{0: int, 1: int} [rootId, replyToId]
	 */
	public static function threadPosition(int $parentId, ?int $parentsParentId): array {
		return [$parentsParentId ?? $parentId, $parentId];
	}

	/**
	 * Baut aus der flachen Liste (älteste zuerst) die Threads: Wurzeln mit
	 * `replies`. Antworten ohne vorhandene Wurzel werden verworfen.
	 *
	 * @param array<int, array<string, mixed>> $flat serialisierte Kommentare
	 * @return array<int, array<string, mixed>>
	 */
	public static function nest(array $flat): array {
		$roots = [];
		$order = [];
		foreach ($flat as $c) {
			if ($c['parentId'] === null) {
				$c['replies'] = [];
				$roots[$c['id']] = $c;
				$order[] = $c['id'];
			}
		}
		foreach ($flat as $c) {
			if ($c['parentId'] !== null && isset($roots[$c['parentId']])) {
				$roots[$c['parentId']]['replies'][] = $c;
			}
		}
		return array_map(fn ($id) => $roots[$id], $order);
	}
}
