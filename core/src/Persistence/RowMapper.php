<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

/**
 * Überträgt die Felder eines Domain-Objekts (Getter/Setter wie
 * `getArticleId()`/`setArticleId()`) in Tabellenspalten (`article_id`) und
 * zurück. Den Spaltentyp leitet sie aus dem Setter-Parameter ab: int, bool,
 * float, string oder \DateTimeInterface, jeweils auch nullbar.
 *
 * @template T of object
 */
final class RowMapper {
	/** @var array<string, array{column: string, type: string, nullable: bool}> */
	private array $fields = [];

	/**
	 * @param class-string<T> $class
	 * @param list<string> $fields Feldnamen ohne get/set und ohne id
	 */
	public function __construct(private string $class, array $fields) {
		foreach ($fields as $field) {
			$param = (new \ReflectionMethod($class, 'set' . ucfirst($field)))->getParameters()[0];
			$type = $param->getType();
			$typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'string';
			$this->fields[$field] = [
				'column' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field)),
				'type' => is_a($typeName, \DateTimeInterface::class, true) ? 'datetime' : $typeName,
				'nullable' => $param->allowsNull(),
			];
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return T
	 */
	public function toObject(array $row): object {
		$object = new ($this->class)();
		$object->setId(isset($row['id']) ? (int) $row['id'] : null);
		foreach ($this->fields as $field => $meta) {
			$value = $row[$meta['column']] ?? null;
			if ($value === null) {
				// Spalten ohne Wert, die das Objekt nicht als null kennt, behalten
				// dessen Standardwert (wie früher Adapter\EntityCopy in der Nextcloud-App).
				if ($meta['nullable']) {
					$object->{'set' . ucfirst($field)}(null);
				}
				continue;
			}
			$object->{'set' . ucfirst($field)}(match ($meta['type']) {
				'int' => (int) $value,
				'bool' => (bool) (int) $value,
				'float' => (float) $value,
				'datetime' => SqlValue::toDateTime($value),
				default => (string) $value,
			});
		}
		return $object;
	}

	/**
	 * @param T $object
	 * @return array<string, mixed> Spalte => Wert, ohne id
	 */
	public function toRow(object $object): array {
		$row = [];
		foreach ($this->fields as $field => $meta) {
			$value = $object->{'get' . ucfirst($field)}();
			$row[$meta['column']] = match (true) {
				$value instanceof \DateTimeInterface => SqlValue::dateTime($value),
				is_bool($value) => $value ? 1 : 0,
				default => $value,
			};
		}
		return $row;
	}

	/**
	 * `spalte = :spalte, …` für ein UPDATE mit den Werten aus toRow().
	 *
	 * @param array<string, mixed> $row
	 */
	public static function assignments(array $row): string {
		return implode(', ', array_map(static fn (string $column) => "{$column} = :{$column}", array_keys($row)));
	}
}
