<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Schritt 4/4 des Umbaus von `is_favorite` auf einen Favorisierungs-Zeitstempel
 * (siehe Version...000015). Räumt die temporäre Zwischenspalte `favorited_at`
 * wieder auf, nachdem ihr Inhalt in Version...000017 nach `is_favorite`
 * (jetzt DATETIME) zurückkopiert wurde.
 */
class Version1000Date20240101000018 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if ($table->hasColumn('favorited_at')) {
			$table->dropColumn('favorited_at');
		}

		return $schema;
	}
}
