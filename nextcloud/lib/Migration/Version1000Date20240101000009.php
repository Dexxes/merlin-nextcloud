<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add category column to merlin_articles for domain-based auto-classification
 * (e.g. "Video" for YouTube, ARD Mediathek, etc.).
 */
class Version1000Date20240101000009 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('merlin_articles');

		if ($table->hasColumn('category')) {
			return null;
		}

		$table->addColumn('category', Types::STRING, [
			'notnull' => false,
			'length'  => 64,
			'default' => null,
		]);

		$table->addIndex(['user_id', 'category'], 'merlin_art_user_cat_idx');

		return $schema;
	}
}
