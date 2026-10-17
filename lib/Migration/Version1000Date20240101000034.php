<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Markierungen in PDFs: pdf_page (Seite ab 1) und pdf_rects (JSON-Liste
 * normierter Rechtecke [x, y, w, h], siehe CommentRules::normalizePdfRects()).
 * Beide null bei Markierungen im Artikel-HTML.
 */
class Version1000Date20240101000034 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('merlin_highlights')) {
			return null;
		}
		$table = $schema->getTable('merlin_highlights');
		$changed = false;
		if (!$table->hasColumn('pdf_page')) {
			$table->addColumn('pdf_page', Types::INTEGER, [
				'notnull'  => false,
				'default'  => null,
				'unsigned' => true,
			]);
			$changed = true;
		}
		if (!$table->hasColumn('pdf_rects')) {
			$table->addColumn('pdf_rects', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
