<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Schritt 2/4 des Umbaus von `is_favorite` auf einen Favorisierungs-Zeitstempel
 * (siehe Version...000015 für den vollen Kontext).
 *
 * Droppt NUR die alte `is_favorite`-Spalte (SMALLINT). Bewusst eine eigene
 * Migration statt Drop+Add mit gleichem Namen im selben changeSchema()-Aufruf:
 * Doctrine vergleicht Start- und Zielschema der Tabelle, nicht die Abfolge der
 * API-Aufrufe — Drop+Add mit identischem Namen in einem Schritt wird deshalb
 * als `ALTER TABLE ... MODIFY is_favorite DATETIME` diffed, nicht als zwei
 * physische Operationen. MySQL kann bestehende Integer-Werte (0/1) aber nicht
 * automatisch in ein DATETIME casten und bricht mit
 * "Incorrect datetime value: '1'" ab. Der Fix: DROP hier, ADD (mit neuem Typ)
 * erst in Version...000017, wenn die alte Spalte bereits committed weg ist.
 *
 * Die Daten dazu liegen bereits sicher in `favorited_at` (Schritt 1).
 */
class Version1000Date20240101000016 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if ($table->hasColumn('is_favorite')) {
			$table->dropColumn('is_favorite');
		}

		return $schema;
	}
}
