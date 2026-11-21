<?php

declare(strict_types=1);

/**
 * Testharness für Service\RetentionPolicy (Rechenregeln der Löschfrist).
 *
 * Aufruf: php tools/test-retention.php
 *
 * Ohne Composer/Nextcloud: RetentionPolicy ist bewusst frei von
 * Nextcloud-Abhängigkeiten. Geprüft werden effektive Frist (Minimum aus Admin
 * und Nutzer, 0 = nicht gesetzt), Stichtag und wann der einmalige Hinweis
 * (Web-Popup, iOS) fällig ist.
 */

require_once __DIR__ . '/../lib/Service/RetentionPolicy.php';

use OCA\Merlin\Service\RetentionPolicy;

$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
	echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
	if (!$ok) {
		$failed++;
	}
};

echo "effectiveDays\n";
$check(RetentionPolicy::effectiveDays(0, 0) === 0, 'Beide 0: unbegrenzt');
$check(RetentionPolicy::effectiveDays(90, 0) === 90, 'Nur Admin: Admin-Maximum');
$check(RetentionPolicy::effectiveDays(0, 30) === 30, 'Nur Nutzer: Nutzerwahl');
$check(RetentionPolicy::effectiveDays(90, 30) === 30, 'Nutzer kürzer: Nutzerwahl');
$check(RetentionPolicy::effectiveDays(30, 90) === 30, 'Nutzer länger als erlaubt: Admin-Maximum');
$check(RetentionPolicy::effectiveDays(-5, 30) === 30, 'Negativer Admin-Wert zählt als 0');
$check(RetentionPolicy::effectiveDays(0, 999999) === RetentionPolicy::MAX_DAYS, 'Riesiger Wert wird gedeckelt');

echo "cutoff\n";
$now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
$check(RetentionPolicy::cutoff(0, $now) === null, 'Unbegrenzt: kein Stichtag');
$check(RetentionPolicy::cutoff(30, $now)?->format('Y-m-d H:i:s') === '2026-09-04 12:00:00', '30 Tage vor jetzt');
$berlin = new DateTimeImmutable('2026-10-04 14:00:00', new DateTimeZone('Europe/Berlin'));
$check(RetentionPolicy::cutoff(1, $berlin)?->format('Y-m-d H:i:s') === '2026-10-03 12:00:00', 'Stichtag wird in UTC gerechnet');

echo "noticeRequired\n";
$check(RetentionPolicy::fingerprint(30, 365) === '30:365', 'Fingerabdruck "Artikel:Favoriten"');
$check(RetentionPolicy::noticeRequired('', 0, 0) === false, 'Keine Frist aktiv: kein Hinweis');
$check(RetentionPolicy::noticeRequired('', 30, 0) === true, 'Frist aktiv, nie bestätigt: Hinweis');
$check(RetentionPolicy::noticeRequired('30:0', 30, 0) === false, 'Gleiche Fristen bestätigt: kein Hinweis');
$check(RetentionPolicy::noticeRequired('30:0', 60, 0) === false, 'Frist verlängert: kein neuer Hinweis');
$check(RetentionPolicy::noticeRequired('30:0', 7, 0) === true, 'Frist verkürzt: neuer Hinweis');
$check(RetentionPolicy::noticeRequired('30:0', 30, 365) === true, 'Favoriten-Frist neu: neuer Hinweis');
$check(RetentionPolicy::noticeRequired('0:0', 30, 0) === true, 'Vorher unbegrenzt bestätigt, jetzt Frist: Hinweis');
$check(RetentionPolicy::noticeRequired('kaputt', 30, 0) === true, 'Unlesbarer bestätigter Wert: Hinweis');

echo $failed === 0 ? "\nAlle Tests bestanden.\n" : "\n$failed Test(s) fehlgeschlagen.\n";
exit($failed === 0 ? 0 : 1);
