<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

/**
 * Reine Baumregeln für verschachtelte Tags (Tag mit parentId).
 *
 * Arbeitet auf einer Map id => parentId (null = oberste Ebene) und ist frei
 * von Nextcloud-Abhängigkeiten, damit tools/test-tag-tree.php sie ohne Server
 * prüfen kann. Die Tags eines Nutzers sind wenige, deshalb läuft der Baum im
 * Speicher statt per rekursivem SQL, das nicht jede Datenbank gleich kann.
 */
class TagTree {
	/**
	 * Ids aller Nachfahren von $id (ohne $id selbst).
	 *
	 * @param array<int, ?int> $parents id => parentId
	 * @return int[]
	 */
	public static function descendantIds(array $parents, int $id): array {
		$children = [];
		foreach ($parents as $child => $parent) {
			if ($parent !== null) {
				$children[$parent][] = $child;
			}
		}
		$result = [];
		$seen = [$id => true];
		$queue = $children[$id] ?? [];
		while ($queue) {
			$next = array_shift($queue);
			if (isset($seen[$next])) {
				continue;
			}
			$seen[$next] = true;
			$result[] = $next;
			foreach ($children[$next] ?? [] as $grandchild) {
				$queue[] = $grandchild;
			}
		}
		return $result;
	}

	/**
	 * Darf $id unter $newParent gehängt werden? Nicht unter sich selbst und
	 * nicht unter einen eigenen Nachfahren, sonst entstünde ein Kreis.
	 * $newParent = null (oberste Ebene) ist immer erlaubt.
	 *
	 * @param array<int, ?int> $parents id => parentId
	 */
	public static function canMove(array $parents, int $id, ?int $newParent): bool {
		if ($newParent === null) {
			return true;
		}
		if ($newParent === $id) {
			return false;
		}
		return !in_array($newParent, self::descendantIds($parents, $id), true);
	}
}
