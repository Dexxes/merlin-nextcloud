<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fügt merlin_articles zwei Spalten hinzu, mit denen ArticleController ein
 * an einer Paywall gescheitertes Speichern signalisiert (siehe
 * Service\Login\PaywallLoginRequiredException): requires_login_domain ist
 * NULL im Normalfall, sonst die Domain, für die der Nutzer Zugangsdaten
 * hinterlegen muss - Grundlage für den Login-Dialog in den Clients (siehe
 * PLATFORMS.md).
 */
class Version1000Date20240101000022 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('requires_login_domain')) {
			$table->addColumn('requires_login_domain', Types::STRING, [
				'notnull' => false,
				'length'  => 255,
			]);
		}
		if (!$table->hasColumn('requires_login_page')) {
			$table->addColumn('requires_login_page', Types::STRING, [
				'notnull' => false,
				'length'  => 2048,
			]);
		}

		return $schema;
	}
}
