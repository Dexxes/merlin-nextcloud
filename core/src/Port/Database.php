<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Kleiner Tabellenzugriff für Services, die über mehrere Tabellen hinweg
 * aufräumen (ArticleDeletionService). Tabellennamen ohne Präfix, wie in den
 * Migrationen (z. B. 'merlin_articles').
 *
 * $equals: Spalte => Wert (UND-verknüpft); $in: Spalte => Liste von
 * Ganzzahlen (IN). Die Bedingungen gelten in dieser Reihenfolge.
 */
interface Database {
	/**
	 * Führt $fn in einer Transaktion aus; wirft $fn, wird zurückgerollt und
	 * die Ausnahme weitergereicht.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function transactional(callable $fn): mixed;

	public function tableExists(string $table): bool;

	/**
	 * @param array<string, string> $equals
	 * @param array<string, list<int>> $in
	 * @return list<int>
	 */
	public function selectIds(string $table, string $column, array $equals, array $in = []): array;

	/**
	 * @param array<string, string> $equals
	 * @param array<string, list<int>> $in
	 * @return int Anzahl gelöschter Zeilen
	 */
	public function delete(string $table, array $equals = [], array $in = []): int;

	/**
	 * DELETE FROM $table WHERE $column IN (SELECT id FROM $subTable WHERE $subEquals).
	 *
	 * @param array<string, string> $subEquals
	 */
	public function deleteWhereInSelect(string $table, string $column, string $subTable, array $subEquals): int;

	/**
	 * DELETE FROM $table WHERE $column NOT IN (SELECT id FROM $subTable).
	 */
	public function deleteWhereNotInSelect(string $table, string $column, string $subTable): int;
}
