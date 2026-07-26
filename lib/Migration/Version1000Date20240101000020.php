<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create merlin_cfilter table: DB-Speicher für Content-Filter-Custom-Layer.
 *
 * Löst den dateibasierten Custom-Ordner aus tasks/content-filter-admin-todo.md ab
 * und führt eine dritte, private Nutzer-Ebene ein (siehe
 * tasks/content-filter-db-scopes-todo.md). Bundle-Filter bleiben Dateien
 * (content-filters/*.xml, Git) — nur die beiden Custom-Layer (Admin, User)
 * wandern hierher.
 *
 * Eine Tabelle mit scope-Spalte statt zweier getrennter Tabellen: Repository-
 * Methoden für Admin- und User-Ebene unterscheiden sich nur im WHERE, nicht im
 * Schema. user_id bekommt für Admin-Zeilen einen festen Sentinel ('') statt
 * NULL, weil ein Unique-Index über (scope, domain, user_id) mit NULL je nach
 * DB-Backend (MySQL/PostgreSQL/SQLite laufen alle bei Merlin) unterschiedlich
 * behandelt wird — mit dem Sentinel deckt ein einziger Index beide Fälle
 * einheitlich ab, ganz ohne Sonderfall in der Query.
 */
class Version1000Date20240101000020 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('merlin_cfilter')) {
			return null;
		}

		$table = $schema->createTable('merlin_cfilter');
		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull'       => true,
			'unsigned'      => true,
		]);
		// 'admin' oder 'user' (ContentFilterRepository::SCOPE_ADMIN/SCOPE_USER).
		$table->addColumn('scope', Types::STRING, [
			'notnull' => true,
			'length'  => 8,
		]);
		// Sentinel '' für scope='admin' (siehe Klassen-Docblock), sonst die UID.
		// Länge 64 wie merlin_shares.user_id.
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length'  => 64,
		]);
		$table->addColumn('domain', Types::STRING, [
			'notnull' => true,
			'length'  => 255,
		]);
		$table->addColumn('xml', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('updated_at', Types::DATETIME, [
			'notnull' => true,
		]);
		// UID des letzten Schreibers. Bei scope='admin' nicht zwingend gleich
		// user_id (das ist dort der Sentinel) — hier steht der tatsächliche
		// Admin, der zuletzt gespeichert hat, für Audit-Zwecke.
		$table->addColumn('updated_by', Types::STRING, [
			'notnull' => true,
			'length'  => 64,
		]);

		$table->setPrimaryKey(['id']);
		// Ein Admin-Override pro Domain (user_id='') UND ein User-Override pro
		// (Domain, Nutzer) — beide Fälle über denselben Index abgedeckt.
		$table->addUniqueIndex(['scope', 'domain', 'user_id'], 'merlin_cfilter_sdu_uidx');
		// Für listFilters()/Badge-Aggregation: "hat dieser Nutzer irgendwo
		// Overrides?" ohne Tabellenscan.
		$table->addIndex(['scope', 'user_id'], 'merlin_cfilter_su_idx');

		return $schema;
	}
}
