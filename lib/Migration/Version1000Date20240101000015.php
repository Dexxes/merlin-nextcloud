<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Schritt 1/3 des Umbaus von `is_favorite` (SMALLINT-Flag) auf einen
 * Favorisierungs-Zeitstempel: Favoriten sollen chronologisch nach dem
 * Zeitpunkt der Favorisierung sortierbar sein, ohne ein zweites Feld
 * (analog `is_archived`/`archived_at`) einzuführen — `is_favorite` selbst
 * wird künftig entweder NULL (nicht favorisiert) oder ein Zeitstempel sein.
 *
 * Ein direkter Typwechsel SMALLINT → DATETIME in einer einzigen Migration
 * ist nicht portabel (MySQL/PostgreSQL/SQLite können 0/1 nicht automatisch
 * in ein Datum casten). Daher der Umweg über eine temporäre Spalte:
 *
 *   1. (dieses File)               temporäre Spalte `favorited_at` anlegen,
 *                                  bestehende Favoriten (`is_favorite = 1`)
 *                                  mit `updated_at` als bestem verfügbaren
 *                                  Näherungswert befüllen.
 *   2. (Version...000016)          alte `is_favorite`-Spalte droppen, neu als
 *                                  DATETIME anlegen, aus `favorited_at` kopieren.
 *   3. (Version...000017)          temporäre Spalte `favorited_at` aufräumen.
 */
class Version1000Date20240101000015 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('favorited_at')) {
			$table->addColumn('favorited_at', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Bestehende Favoriten bekommen updated_at als Favorisierungszeitpunkt
		// (toggleFavorite() aktualisierte bisher immer updated_at beim Favorisieren
		// — der genaue Zeitpunkt ist nicht rekonstruierbar, das ist die beste
		// verfügbare Näherung). Spalte-zu-Spalte-Kopie in einem UPDATE, kein PHP-Loop.
		$qb = $this->connection->getQueryBuilder();
		$qb->update('merlin_articles')
			->set('favorited_at', 'updated_at')
			->where($qb->expr()->eq('is_favorite', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
