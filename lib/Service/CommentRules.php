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
	public const XPATH_MAX = 1000;
	/** Höchstens so viele Gast-Kommentare bzw. -Markierungen je Artikel und Tag. */
	public const GUEST_DAILY_CAP = 500;

	/** Bidi-Steuerzeichen (können Namen und Text optisch umdrehen). */
	private const BIDI = '\x{202A}-\x{202E}\x{2066}-\x{2069}';

	/**
	 * Steuerzeichen raus, Leerraum zu einem Leerzeichen, getrimmt.
	 */
	public static function normalizeName(string $name): string {
		$name = preg_replace('/[\p{Cc}' . self::BIDI . ']+/u', ' ', $name) ?? '';
		$name = preg_replace('/\s+/u', ' ', $name) ?? '';
		return trim($name);
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
		$body = str_replace(["\r\n", "\r"], "\n", $body);
		$body = preg_replace('/[^\P{Cc}\n\t]+|[' . self::BIDI . ']+/u', '', $body) ?? '';
		$body = preg_replace("/\n{3,}/", "\n\n", $body) ?? '';
		return trim($body);
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
