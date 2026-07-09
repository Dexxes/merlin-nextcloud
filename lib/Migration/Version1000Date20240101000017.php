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
 * Schritt 3/4 des Umbaus von `is_favorite` auf einen Favorisierungs-Zeitstempel
 * (siehe Version...000015/000016 für den vollen Kontext).
 *
 * Legt `is_favorite` frisch als nullable DATETIME an — die alte SMALLINT-Spalte
 * wurde in Version...000016 bereits gedroppt und committed, Doctrine sieht hier
 * also ein sauberes ADD COLUMN (keine Typ-Änderung, kein Cast-Problem). Kopiert
 * anschließend die Werte aus der temporären `favorited_at`-Spalte (Schritt 1)
 * zurück nach `is_favorite`.
 */
class Version1000Date20240101000017 extends SimpleMigrationStep {
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

		if (!$table->hasColumn('is_favorite')) {
			$table->addColumn('is_favorite', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
		}

		// DROP COLUMN (Version...000016) nahm den ursprünglichen Index implizit
		// mit; für den Favoriten-Filter (WHERE is_favorite IS NOT NULL) wieder anlegen.
		if (!$table->hasIndex('merlin_articles_is_favorite')) {
			$table->addIndex(['is_favorite'], 'merlin_articles_is_favorite');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update('merlin_articles')
			->set('is_favorite', 'favorited_at');
		$qb->executeStatement();
	}
}
