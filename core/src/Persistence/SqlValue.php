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
}
