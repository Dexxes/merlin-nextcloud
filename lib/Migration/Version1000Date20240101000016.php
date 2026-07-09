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
 * Schritt 2/3 des Umbaus von `is_favorite` auf einen Favorisierungs-Zeitstempel
 * (siehe Version...000015 für den vollen Kontext).
 *
 * `is_favorite` (SMALLINT) wird gedroppt und im selben changeSchema()-Aufruf
 * mit identischem Namen, aber als nullable DATETIME neu angelegt. Für Doctrine
 * sind das zwei physische Spalten (DROP + ADD), kein Rename — die Daten dazu
 * liegen bereits sicher in `favorited_at` (Schritt 1) und werden hier per
 * postSchemaChange zurückkopiert, sobald die neue Spalte existiert.
 */
class Version1000Date20240101000016 extends SimpleMigrationStep {
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

		if ($table->hasColumn('is_favorite') && $table->getColumn('is_favorite')->getType()->getName() !== Types::DATETIME) {
			$table->dropColumn('is_favorite');
		}

		if (!$table->hasColumn('is_favorite')) {
			$table->addColumn('is_favorite', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
		}

		// DROP COLUMN nimmt implizit angehängte Indizes mit; für den
		// Favoriten-Filter (WHERE is_favorite IS NOT NULL) wieder anlegen.
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
