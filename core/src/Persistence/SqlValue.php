<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

/**
 * Umrechnung zwischen PHP-Werten und Spaltenwerten, so wie Nextclouds
 * Entities sie schreiben: DATETIME als 'Y-m-d H:i:s' in UTC, Wahrheitswerte
 * als 0/1.
 */
final class SqlValue {
	public const DATETIME_FORMAT = 'Y-m-d H:i:s';

	public static function dateTime(?\DateTimeInterface $value): ?string {
		if ($value === null) {
			return null;
		}
		return \DateTimeImmutable::createFromInterface($value)
			->setTimezone(new \DateTimeZone('UTC'))
			->format(self::DATETIME_FORMAT);
	}

	public static function toDateTime(mixed $value): ?\DateTime {
		if ($value === null || $value === '') {
			return null;
		}
		$utc = new \DateTimeZone('UTC');
		$parsed = \DateTime::createFromFormat('!' . self::DATETIME_FORMAT, (string) $value, $utc);
		if ($parsed === false) {
			// Andere Formate (z. B. mit Bruchteilen oder Zeitzone) großzügig lesen.
			try {
				$parsed = new \DateTime((string) $value, $utc);
			} catch (\Exception) {
				return null;
			}
		}
		return $parsed->setTimezone(new \DateTimeZone(date_default_timezone_get()));
	}

	/**
	 * Platzhalter für `spalte IN (…)`: liefert `(:name0, :name1, …)` und die
	 * Parameter dazu. Eine leere Liste ergibt `(NULL)`, das nichts trifft (wie
	 * IN () in Nextclouds QueryBuilder).
	 *
	 * @param list<int|string> $values
	 * @return array{0: string, 1: array<string, int|string>}
	 */
	public static function inList(string $name, array $values): array {
		if ($values === []) {
			return ['(NULL)', []];
		}
		$placeholders = [];
		$params = [];
		foreach (array_values($values) as $i => $value) {
			$placeholders[] = ':' . $name . $i;
			$params[$name . $i] = $value;
		}
		return ['(' . implode(', ', $placeholders) . ')', $params];
	}

	/**
	 * Suchbegriff für `LIKE :param ESCAPE '!'`: %, _ und ! im Begriff gelten
	 * wörtlich, davor und danach steht %. Das Escape-Zeichen ist ! statt \,
	 * weil MySQL Backslashes in Literalen selbst auswertet.
	 */
	public static function likeContains(string $term): string {
		return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
	}
}
