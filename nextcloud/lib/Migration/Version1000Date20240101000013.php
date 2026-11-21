<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Entfernt die PDF/EPUB-Export- und Import-Funktionalität wieder aus dem Schema.
 *
 * Sicherheitsaudit 2026-06-25: PdfExtractorService führte unvalidierte
 * file_get_contents()-Aufrufe auf nutzergesteuerten URLs durch (SSRF/Local-
 * File-Read) und deaktivierte die TLS-Zertifikatsprüfung. Statt das Feature
 * abzusichern, wurde es komplett entfernt (PDF-Import, PDF/EPUB-Export).
 * Hier wird nur die pdf_path-Spalte gedroppt – die ursprüngliche Migration
 * (Version1000Date20240101000011) bleibt unverändert, da sie auf bestehenden
 * Installationen bereits gelaufen ist.
 */
class Version1000Date20240101000013 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('merlin_articles')) {
			$table = $schema->getTable('merlin_articles');

			if ($table->hasColumn('pdf_path')) {
				$table->dropColumn('pdf_path');
			}
		}

		return $schema;
	}
}
