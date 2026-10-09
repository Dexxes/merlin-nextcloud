<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Datei-Einträge: merlin_articles bekommt file_id (Nextcloud-Datei-ID, NULL =
 * normaler Web-Artikel) und file_mime. Gesetzt für Dateien, die vom Handy in
 * „Merlin Dateien“ gespeichert wurden (Service\MerlinFileService).
 */
class Version1000Date20240101000032 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}
		$table = $schema->getTable('merlin_articles');
		if ($table->hasColumn('file_id')) {
			return null;
		}
		$table->addColumn('file_id', Types::BIGINT, [
			'notnull' => false,
			'default' => null,
			'length'  => 20,
		]);
		$table->addColumn('file_mime', Types::STRING, [
			'notnull' => false,
			'default' => null,
			'length'  => 255,
		]);
		$table->addIndex(['user_id', 'file_id'], 'merlin_articles_user_file');
		return $schema;
	}
}
