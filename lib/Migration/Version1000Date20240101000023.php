<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fügt merlin_articles zwei Spalten hinzu, mit denen der Extractor einen per
 * <paywall><marker xpath="…"> erkannten Bezahlartikel signalisiert (siehe
 * ContentExtractorService::detectPaywall()): is_paywalled ist 0 im
 * Normalfall, paywall_subscribe_url der zugehörige <subscribe url="…">-Wert
 * aus der Content-Filter-Config, falls hinterlegt.
 *
 * Anders als requires_login_domain/requires_login_page (siehe
 * Version1000Date20240101000022) löst dies KEINEN Login-Versuch aus - die
 * Domain hat keine <login>-Konfiguration, Merlin kann den Artikel also
 * grundsätzlich nicht automatisch freischalten. Der Client zeigt stattdessen
 * einen Hinweis mit den Optionen "Abo abschliessen" (paywall_subscribe_url)
 * oder "Archivieren".
 */
class Version1000Date20240101000023 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('is_paywalled')) {
			$table->addColumn('is_paywalled', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
		}
		if (!$table->hasColumn('paywall_subscribe_url')) {
			$table->addColumn('paywall_subscribe_url', Types::STRING, [
				'notnull' => false,
				'length'  => 2048,
			]);
		}

		return $schema;
	}
}
