<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Datei-Einträge: file_text für Text, den die iOS-Share-Extension in Bildern
 * erkannt hat (OCR). Wird von der Suche durchsucht und im Content angezeigt
 * (Service\MerlinFileService).
 */
class Version1000Date20240101000033 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}
		$table = $schema->getTable('merlin_articles');
		if ($table->hasColumn('file_text')) {
			return null;
		}
		$table->addColumn('file_text', Types::TEXT, [
			'notnull' => false,
			'default' => null,
		]);
		return $schema;
	}
}
