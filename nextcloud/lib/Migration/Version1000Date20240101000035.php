<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Markierungen lassen sich ändern (Länge, Farbe): revision zählt jede
 * Änderung hoch und geht in die Änderungsmarke des Push-Kanals ein
 * (HighlightMapper::signature()), denn ein Update ändert weder Anzahl noch
 * höchste id. Ein Zähler statt eines Zeitstempels, weil zwei Änderungen in
 * derselben Sekunde sonst dieselbe Marke hätten.
 */
class Version1000Date20240101000035 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('merlin_highlights')) {
			return null;
		}
		$table = $schema->getTable('merlin_highlights');
		if ($table->hasColumn('revision')) {
			return null;
		}
		$table->addColumn('revision', Types::INTEGER, [
			'notnull'  => true,
			'default'  => 0,
			'unsigned' => true,
		]);
		return $schema;
	}
}
