<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fügt merlin_articles die Spalte site_icon_url hinzu: das Icon der konkreten
 * Artikelseite (apple-touch-icon / <link rel="icon"> / Tile-Image /
 * favicon.ico), beim Extrahieren aus dem HTML gelesen (siehe
 * ContentExtractorService::extractSiteIconUrl()). NULL bei Artikeln, die vor
 * dieser Migration gespeichert wurden. Wird nicht in Artikellisten
 * ausgeliefert, sondern nur als supportBox.iconUrl (Service\SupportBoxService).
 */
class Version1000Date20240101000025 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('site_icon_url')) {
			$table->addColumn('site_icon_url', Types::STRING, [
				'notnull' => false,
				'length'  => 2048,
			]);
		}

		return $schema;
	}
}
