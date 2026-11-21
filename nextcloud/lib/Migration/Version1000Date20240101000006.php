<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add archived_at column to merlin_articles for storing the archive timestamp
 */
class Version1000Date20240101000006 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('archived_at')) {
			$table->addColumn('archived_at', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
		}

		return $schema;
	}
}
