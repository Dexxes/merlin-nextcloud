<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Entfernt den nie fertiggestellten RSS-Feed-Support wieder aus dem Schema.
 *
 * Die App hatte noch keinen echten Feed-Support (Controller/Service waren
 * Totcode, keine Clients haben das je benutzt) – statt das halbfertige
 * Feature weiterzuschleppen, wird es hier vollständig zurückgerollt:
 * - merlin_feeds-Tabelle (aus Version1000Date20240101000003) komplett droppen
 * - feed_id-Spalte + Index in merlin_articles (aus Version1000Date20240101000000) droppen
 *
 * Frühere Migrationen werden dabei bewusst nicht editiert, da sie auf
 * bestehenden Installationen bereits gelaufen sind.
 */
class Version1000Date20240101000012 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('merlin_feeds')) {
			$schema->dropTable('merlin_feeds');
		}

		if ($schema->hasTable('merlin_articles')) {
			$table = $schema->getTable('merlin_articles');

			if ($table->hasIndex('merlin_articles_feed_id')) {
				$table->dropIndex('merlin_articles_feed_id');
			}
			if ($table->hasColumn('feed_id')) {
				$table->dropColumn('feed_id');
			}
		}

		return $schema;
	}
}
