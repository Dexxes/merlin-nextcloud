<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Geräteübergreifende Lese-/Scroll-Position.
 *
 * Bisher speicherte jede Plattform die Scroll-Position nur lokal (iOS
 * UserDefaults, Web localStorage, Android DataStore). Diese Migration legt die
 * serverseitige Basis für die Synchronisation zwischen Geräten an:
 *
 * - `scroll_progress`     – Lesefortschritt 0..1. Bewusst der *Prozentwert*,
 *                           nicht der Pixel-Offset: Pixel sind gerätespezifisch
 *                           (Bildschirmbreite/Schriftgröße ändern die
 *                           Inhaltshöhe), der Prozentwert ist portabel.
 * - `scroll_updated_at`   – Epoch-Millis des letzten Speicherns. Treibt die
 *                           Last-Write-Wins-Konfliktauflösung: beim Öffnen
 *                           gewinnt die Position mit dem neueren Zeitstempel.
 *                           Vom Client gesetzt (verbatim gespeichert), damit
 *                           auch Offline-Writes einen monoton steigenden Wert
 *                           bekommen.
 */
class Version1000Date20240101000014 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');

		if (!$table->hasColumn('scroll_progress')) {
			$table->addColumn('scroll_progress', Types::FLOAT, [
				'notnull' => false,
				'default' => 0,
			]);
		}

		if (!$table->hasColumn('scroll_updated_at')) {
			$table->addColumn('scroll_updated_at', Types::BIGINT, [
				'notnull' => false,
				'default' => 0,
				// Epoch-Millis brauchen mehr als 32 Bit – BIGINT erzwingen.
				'length'  => 20,
			]);
		}

		return $schema;
	}
}
