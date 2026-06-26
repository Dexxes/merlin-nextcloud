<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create article_tags junction table
 */
class Version1000Date20240101000002 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_article_tags')) {
			$table = $schema->createTable('merlin_article_tags');

			$table->addColumn('article_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('tag_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);

			$table->setPrimaryKey(['article_id', 'tag_id']);
			$table->addIndex(['article_id'], 'merlin_article_tags_article_id');
			$table->addIndex(['tag_id'], 'merlin_article_tags_tag_id');
		}

		return $schema;
	}
}
