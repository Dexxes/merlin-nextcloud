<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use OCP\AppFramework\Db\Entity;

/**
 * Überträgt Felder zwischen Domain-Objekten des Kerns und Nextcloud-Entities,
 * deren Getter und Setter gleich heißen.
 *
 * Nextclouds Entity merkt sich geänderte Felder an den Settern und schreibt
 * bei update() nur diese. Zeitpunkte werden deshalb nur gesetzt, wenn sie
 * sich wirklich geändert haben (ein neues DateTime-Objekt mit demselben
 * Zeitpunkt zählte sonst als Änderung); so schreibt update() dieselben
 * Spalten wie vorher mit dem Entity direkt.
 */
final class EntityCopy {
	/**
	 * @param list<string> $fields Feldnamen ohne get/set, z. B. 'articleId'
	 * @param list<string> $dates  davon die Zeitpunkt-Felder
	 */
	public static function toEntity(object $from, Entity $to, array $fields, array $dates = []): void {
		foreach ($fields as $field) {
			$getter = 'get' . ucfirst($field);
			$setter = 'set' . ucfirst($field);
			$value = $from->$getter();
			if (in_array($field, $dates, true)) {
				if (self::sameInstant($to->$getter(), $value)) {
					continue;
				}
				$value = self::dateTime($value);
			}
			$to->$setter($value);
		}
	}

	/**
	 * @param list<string> $fields
	 */
	public static function toDomain(Entity $from, object $to, array $fields): void {
		$id = $from->getId();
		$to->setId($id === null ? null : (int) $id);
		foreach ($fields as $field) {
			$getter = 'get' . ucfirst($field);
			$setter = 'set' . ucfirst($field);
			$value = $from->$getter();
			// Spalten ohne Wert, die das Domain-Objekt nicht als null kennt
			// (z. B. alte Zeilen), behalten dessen Standardwert.
			if ($value === null && !self::acceptsNull($to, $setter)) {
				continue;
			}
			$to->$setter($value);
		}
	}

	private static function acceptsNull(object $to, string $setter): bool {
		static $cache = [];
		$key = $to::class . '::' . $setter;
		return $cache[$key] ??= (new \ReflectionMethod($to, $setter))->getParameters()[0]->allowsNull();
	}

	private static function sameInstant(mixed $a, mixed $b): bool {
		if (!$a instanceof \DateTimeInterface || !$b instanceof \DateTimeInterface) {
			return $a === $b;
		}
		return $a->format('U.u') === $b->format('U.u');
	}

	private static function dateTime(mixed $value): mixed {
		if ($value instanceof \DateTimeInterface && !$value instanceof \DateTime) {
			return \DateTime::createFromInterface($value);
		}
		return $value;
	}
}
