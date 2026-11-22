<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Verschachtelte Tags: merlin_tags bekommt parent_id (NULL = oberste Ebene).
 * Bestehende Tags bleiben auf oberster Ebene; die Baumregeln stehen in
 * Service\TagTree.
 */
class Version1000Date20240101000031 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('merlin_tags')) {
			return null;
		}
		$table = $schema->getTable('merlin_tags');
		if ($table->hasColumn('parent_id')) {
			return null;
		}
		$table->addColumn('parent_id', Types::BIGINT, [
			'notnull' => false,
			'default' => null,
			'length'  => 20,
		]);
		$table->addIndex(['user_id', 'parent_id'], 'merlin_tags_user_parent');
		return $schema;
	}
}
