<?php

declare(strict_types=1);

/**
 * Testharness für Service\TagTree (verschachtelte Tags).
 *
 * Aufruf: php tools/test-tag-tree.php
 *
 * Ohne Composer/Nextcloud: TagTree ist bewusst frei von
 * Nextcloud-Abhängigkeiten. Geprüft werden Nachfahren (für Filter, Ausblenden
 * und Löschen samt Unter-Tags) und die Kreisprüfung beim Verschieben.
 */

require_once __DIR__ . '/../lib/Service/TagTree.php';

use OCA\Merlin\Service\TagTree;

$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
	echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
	if (!$ok) {
		$failed++;
	}
};

// Reisen(1) > Japan(2) > Tokio(3); Reisen(1) > Italien(4); Arbeit(5)
$parents = [1 => null, 2 => 1, 3 => 2, 4 => 1, 5 => null];
$sorted = static function (array $ids): array {
	sort($ids);
	return $ids;
};

echo "descendantIds\n";
$check($sorted(TagTree::descendantIds($parents, 1)) === [2, 3, 4], 'Alle Ebenen unter dem Eltern-Tag');
$check(TagTree::descendantIds($parents, 2) === [3], 'Mittlere Ebene: nur eigene Kinder');
$check(TagTree::descendantIds($parents, 3) === [], 'Blatt hat keine Nachfahren');
$check(TagTree::descendantIds($parents, 5) === [], 'Tag ohne Kinder');
$check(TagTree::descendantIds($parents, 99) === [], 'Unbekannte Id');
$check($sorted(TagTree::descendantIds([1 => 2, 2 => 1], 1)) === [2], 'Kaputter Kreis in den Daten hängt nicht');

echo "canMove\n";
$check(TagTree::canMove($parents, 3, null), 'Auf oberste Ebene ist immer erlaubt');
$check(TagTree::canMove($parents, 5, 3), 'Unter fremden Zweig');
$check(TagTree::canMove($parents, 3, 4), 'Zwischen Geschwister-Zweigen');
$check(!TagTree::canMove($parents, 1, 1), 'Nicht unter sich selbst');
$check(!TagTree::canMove($parents, 1, 2), 'Nicht unter eigenes Kind');
$check(!TagTree::canMove($parents, 1, 3), 'Nicht unter eigenen Enkel');

echo $failed === 0 ? "\nAlle Tests bestanden.\n" : "\n$failed Test(s) fehlgeschlagen.\n";
exit($failed === 0 ? 0 : 1);
