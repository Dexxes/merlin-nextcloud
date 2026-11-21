<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add published_at column to merlin_articles for storing article publication date
 */
class Version1000Date20240101000005 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('published_at')) {
			$table->addColumn('published_at', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
		}

		return $schema;
	}
}
