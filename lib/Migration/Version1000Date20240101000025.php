<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Geräteübergreifende Abspielposition für Audio/Video (MediaPlayer.vue),
 * analog zur Lese-/Scroll-Position aus Version1000Date20240101000014:
 *
 * - `media_position`            – Abspielposition in Sekunden. Anders als beim
 *                                 Scrollen bewusst absolut statt als Anteil:
 *                                 die Zeitachse eines Mediums ist auf allen
 *                                 Geräten dieselbe, und bei HLS-Streams steht
 *                                 die Gesamtdauer beim Wiederherstellen noch
 *                                 nicht fest. 0 = von vorn (auch nach dem
 *                                 vollständigen Abspielen).
 * - `media_position_updated_at` – Epoch-Millis des letzten Speicherns, vom
 *                                 Client gesetzt; treibt die Last-Write-Wins-
 *                                 Auflösung gegen den lokal gespeicherten Wert.
 */
class Version1000Date20240101000025 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('media_position')) {
			$table->addColumn('media_position', Types::FLOAT, [
				'notnull' => false,
				'default' => 0,
			]);
		}

		if (!$table->hasColumn('media_position_updated_at')) {
			$table->addColumn('media_position_updated_at', Types::BIGINT, [
				'notnull' => false,
				'default' => 0,
				// Epoch-Millis brauchen mehr als 32 Bit – BIGINT erzwingen.
				'length'  => 20,
			]);
		}

		return $schema;
	}
}
