<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create articles table
 */
class Version1000Date20240101000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			$table = $schema->createTable('merlin_articles');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('url', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('content', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('excerpt', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('author', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('site_name', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('image_url', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('is_read', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);
			$table->addColumn('is_favorite', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);
			$table->addColumn('is_archived', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);
			$table->addColumn('reading_time', Types::INTEGER, [
				'notnull' => false,
				'default' => 0,
			]);
			$table->addColumn('feed_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'merlin_articles_user_id');
			$table->addIndex(['is_read'], 'merlin_articles_is_read');
			$table->addIndex(['is_favorite'], 'merlin_articles_is_favorite');
			$table->addIndex(['is_archived'], 'merlin_articles_is_archived');
			$table->addIndex(['feed_id'], 'merlin_articles_feed_id');
			$table->addIndex(['created_at'], 'merlin_articles_created_at');
		}

		return $schema;
	}
}
