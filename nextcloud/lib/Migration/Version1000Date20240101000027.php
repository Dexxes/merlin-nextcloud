<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fügt merlin_articles die Spalte authors hinzu: JSON-Liste aus
 * {"name": …, "url": …|null} je Autor, damit auch bei Co-Autoren jeder Name
 * auf sein eigenes Profil verlinken kann (author_url trägt nur den Link bei
 * genau einem Autor). NULL, wenn für keinen Autor ein Profil-Link erkannt
 * wurde, und bei Artikeln, die vor dieser Migration gespeichert wurden.
 */
class Version1000Date20240101000027 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('authors')) {
			$table->addColumn('authors', Types::TEXT, [
				'notnull' => false,
			]);
		}

		return $schema;
	}
}
