<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add is_processing column to merlin_articles.
 *
 * Set to 1 immediately when an article is saved so clients can show
 * a loading indicator; flipped to 0 once content extraction finishes.
 * Existing rows default to 0 (assumed already processed).
 */
class Version1000Date20240101000007 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('is_processing')) {
			$table->addColumn('is_processing', Types::INTEGER, [
				'notnull'  => true,
				'default'  => 0,
				'unsigned' => true,
			]);
		}

		return $schema;
	}
}
