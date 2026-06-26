<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add pdf_path column to merlin_articles.
 *
 * Stores the filename of a locally cached PDF binary inside the app's
 * IAppData folder ("pdfs/{userId}_{md5(url)}.pdf").  Nullable – only set
 * for articles whose category is 'PDF'.
 */
class Version1000Date20240101000011 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('merlin_articles');

		if ($table->hasColumn('pdf_path')) {
			return null;
		}

		$table->addColumn('pdf_path', Types::STRING, [
			'notnull' => false,
			'length'  => 512,
			'default' => null,
		]);

		return $schema;
	}
}
