<?php

declare(strict_types=1);

namespace Merlin\Standalone;

/**
 * Werte aus config/config.php (oder der Datei in MERLIN_CONFIG), siehe
 * config/config.sample.php.
 */
final class Config {
	/** @param array<string, mixed> $values */
	public function __construct(private array $values) {
	}

	public static function load(?string $file = null): self {
		$file ??= getenv('MERLIN_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
		if (!is_file($file)) {
			throw new \RuntimeException(
				"Konfiguration fehlt: {$file}. config/config.sample.php nach config/config.php kopieren und anpassen."
			);
		}
		$values = require $file;
		if (!is_array($values)) {
			throw new \RuntimeException("{$file} muss ein Array zurückgeben.");
		}
		return new self($values);
	}

	public function get(string $key, mixed $default = null): mixed {
		return $this->values[$key] ?? $default;
	}

	public function string(string $key, string $default = ''): string {
		$value = $this->values[$key] ?? $default;
		return is_scalar($value) ? (string) $value : $default;
	}

	public function int(string $key, int $default = 0): int {
		$value = $this->values[$key] ?? $default;
		return is_numeric($value) ? (int) $value : $default;
	}

	/**
	 * Liste aus einem Array oder einer leerzeichen-/kommagetrennten
	 * Zeichenkette (Umgebungsvariablen im Docker-Image).
	 *
	 * @return list<string>
	 */
	public function stringList(string $key): array {
		$value = $this->values[$key] ?? [];
		if (is_array($value)) {
			return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $value), static fn (string $v): bool => $v !== ''));
		}
		return is_scalar($value) ? (preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
	}

	/** @return array<string, mixed> */
	public function section(string $key): array {
		$value = $this->values[$key] ?? [];
		return is_array($value) ? $value : [];
	}
}
