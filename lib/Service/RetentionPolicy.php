<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

/**
 * Reine Rechenregeln der Löschfrist, ohne Nextcloud-Abhängigkeiten (damit
 * tools/test-retention.php sie ohne Composer prüfen kann). Den Zugriff auf die
 * gespeicherten Werte übernimmt RetentionService.
 *
 * Regeln (siehe Plan "Automatische Löschung"):
 * - Gelöscht werden nur archivierte Artikel; das Alter zählt ab `archived_at`.
 * - Es gibt zwei Fristen: eine für normale Artikel, eine eigene für Favoriten.
 * - Je Frist gibt der Admin ein Maximum vor, der Nutzer kann kürzer wählen.
 *   0 bedeutet "nicht gesetzt"; die effektive Frist ist das Minimum der
 *   gesetzten Werte, sind beide 0, wird nie gelöscht.
 */
final class RetentionPolicy {
	/** Obergrenze für eingegebene Tage (100 Jahre), schützt vor DateTime-Überläufen. */
	public const MAX_DAYS = 36500;

	/**
	 * Effektive Frist in Tagen aus Admin-Maximum und Nutzerwahl; 0 = unbegrenzt.
	 * Ein Nutzerwert über dem Admin-Maximum wirkt als Admin-Maximum.
	 */
	public static function effectiveDays(int $adminMaxDays, int $userDays): int {
		$admin = self::normalizeDays($adminMaxDays);
		$user = self::normalizeDays($userDays);
		if ($admin === 0) {
			return $user;
		}
		if ($user === 0) {
			return $admin;
		}
		return min($admin, $user);
	}

	/** Negative oder unsinnig große Werte auf den gültigen Bereich bringen. */
	public static function normalizeDays(int $days): int {
		return max(0, min(self::MAX_DAYS, $days));
	}

	/**
	 * Stichtag: Artikel, die vor diesem Zeitpunkt archiviert wurden, sind
	 * abgelaufen. null bei unbegrenzter Frist.
	 */
	public static function cutoff(int $effectiveDays, \DateTimeImmutable $now): ?\DateTimeImmutable {
		if ($effectiveDays <= 0) {
			return null;
		}
		return $now->setTimezone(new \DateTimeZone('UTC'))->modify('-' . $effectiveDays . ' days');
	}

	/**
	 * Fingerabdruck der Fristen, die dem Nutzer im Hinweis gezeigt wurden
	 * ("Artikel:Favoriten", z. B. "30:365", "0" = unbegrenzt).
	 */
	public static function fingerprint(int $effectiveDays, int $effectiveFavoritesDays): string {
		return $effectiveDays . ':' . $effectiveFavoritesDays;
	}

	/**
	 * Ob der einmalige Hinweis (Web-Popup, iOS) gezeigt werden muss: nur wenn
	 * mindestens eine Frist aktiv ist und sich gegenüber dem bestätigten Stand
	 * eine Frist neu ergeben oder verkürzt hat. Verlängerungen oder das
	 * Aufheben einer Frist lösen keinen neuen Hinweis aus.
	 */
	public static function noticeRequired(string $acknowledged, int $effectiveDays, int $effectiveFavoritesDays): bool {
		if ($effectiveDays === 0 && $effectiveFavoritesDays === 0) {
			return false;
		}
		$parts = explode(':', $acknowledged);
		if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
			return true;
		}
		return self::isStricter($effectiveDays, (int) $parts[0])
			|| self::isStricter($effectiveFavoritesDays, (int) $parts[1]);
	}

	/** Ist $now strenger als $before? 0 steht für unbegrenzt. */
	private static function isStricter(int $now, int $before): bool {
		if ($now === 0) {
			return false;
		}
		return $before === 0 || $now < $before;
	}
}
