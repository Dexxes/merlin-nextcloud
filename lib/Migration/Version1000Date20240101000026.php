<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fügt merlin_articles die Spalte author_url hinzu: der Link zum Profil des
 * Autors (Autorenseite der Quelle), beim Extrahieren aus dem HTML gelesen
 * (siehe ContentExtractorService::resolveAuthorMetadata()). NULL, wenn kein
 * Profil-Link erkennbar war, es mehrere Autoren gibt, oder bei Artikeln, die
 * vor dieser Migration gespeichert wurden.
 */
class Version1000Date20240101000026 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('author_url')) {
			$table->addColumn('author_url', Types::STRING, [
				'notnull' => false,
				'length'  => 2048,
			]);
		}

		return $schema;
	}
}
