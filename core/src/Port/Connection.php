<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * SQL-Verbindung für die Repositories des Kerns (Merlin\Core\Persistence).
 *
 * Tabellennamen stehen im SQL mit Präfix-Platzhalter, wie in Nextcloud:
 * `SELECT * FROM *PREFIX*merlin_comments WHERE id = :id`. Die Implementierung
 * ersetzt `*PREFIX*` (Nextcloud: Tabellenpräfix der Instanz, Standalone: leer).
 *
 * Parameter sind benannt (`:name`) und haben PHP-Werte: int, string, null
 * oder bool (als 0/1). Zeitpunkte formatiert der Aufrufer mit
 * Persistence\SqlValue::dateTime(). Das SQL muss auf SQLite, MySQL/MariaDB und
 * PostgreSQL laufen (keine Upserts, keine datenbankeigenen Funktionen).
 */
interface Connection {
	/** @return list<array<string, mixed>> */
	public function fetchAll(string $sql, array $params = []): array;

	/** @return array<string, mixed>|null erste Zeile oder null */
	public function fetchRow(string $sql, array $params = []): ?array;

	/** Erste Spalte der ersten Zeile, null ohne Treffer. */
	public function fetchValue(string $sql, array $params = []): mixed;

	/** @return int Anzahl betroffener Zeilen */
	public function execute(string $sql, array $params = []): int;

	/**
	 * Fügt eine Zeile ein (Tabellenname ohne Präfix) und gibt die neue ID der
	 * Autoincrement-Spalte `id` zurück (0, wenn die Tabelle keine hat).
	 *
	 * @param array<string, mixed> $values Spalte => Wert
	 * @throws DuplicateEntryException bei Verletzung eines eindeutigen Index
	 */
	public function insert(string $table, array $values, bool $returnsId = true): int;

	/**
	 * Führt $fn in einer Transaktion aus; bei einer Ausnahme wird
	 * zurückgerollt und die Ausnahme weitergeworfen.
	 */
	public function transactional(callable $fn): mixed;

	public function tableExists(string $table): bool;
}
