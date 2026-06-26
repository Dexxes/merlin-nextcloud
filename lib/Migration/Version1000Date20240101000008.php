<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create merlin_highlights table for text highlights inside articles.
 */
class Version1000Date20240101000008 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('merlin_highlights')) {
			return null;
		}

		$table = $schema->createTable('merlin_highlights');
		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull'       => true,
			'unsigned'      => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length'  => 64,
		]);
		$table->addColumn('article_id', Types::INTEGER, [
			'notnull'  => true,
			'unsigned' => true,
		]);
		$table->addColumn('highlighted_text', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('start_xpath', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('start_offset', Types::INTEGER, [
			'notnull' => true,
		]);
		$table->addColumn('end_xpath', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('end_offset', Types::INTEGER, [
			'notnull' => true,
		]);
		$table->addColumn('color', Types::STRING, [
			'notnull' => true,
			'length'  => 32,
			'default' => 'yellow',
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id', 'article_id'], 'merlin_hl_user_article_idx');

		return $schema;
	}
}
