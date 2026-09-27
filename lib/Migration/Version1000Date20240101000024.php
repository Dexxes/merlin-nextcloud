<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fügt merlin_articles eine Spalte hinzu, mit der ArticleController/
 * ExtensionController ein wegen content-filters/$unsupported.xml gar nicht
 * erst versuchtes Speichern signalisieren (siehe
 * Service\UnsupportedSiteException): unsupported_site_domain ist NULL im
 * Normalfall, sonst die Domain, deren Seite Merlin grundsätzlich nicht
 * scrapen kann - Grundlage für einen erklärenden Hinweis in den Clients,
 * analog zu requires_login_domain (Version1000Date20240101000022).
 */
class Version1000Date20240101000024 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('unsupported_site_domain')) {
			$table->addColumn('unsupported_site_domain', Types::STRING, [
				'notnull' => false,
				'length'  => 255,
			]);
		}

		return $schema;
	}
}
