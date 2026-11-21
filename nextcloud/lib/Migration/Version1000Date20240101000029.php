<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kommentare an Artikeln (CommentService):
 *
 * 1. Neue Tabelle merlin_comments. Thread-Wurzeln und Antworten liegen in
 *    derselben Tabelle; Antworten zeigen per parent_id auf die Wurzel (genau
 *    eine Antwortebene), reply_to_id merkt sich, wem direkt geantwortet wurde.
 *    user_id ist wie in merlin_highlights der Besitzer des Artikels, nicht der
 *    Verfasser – der steht in author_type/author_name.
 * 2. merlin_highlights bekommt Verfasser-Spalten, weil auch Gäste über den
 *    öffentlichen Link markieren dürfen. Bestehende Zeilen sind 'owner'.
 * 3. merlin_shares.allow_comments schaltet Markieren und Kommentieren für
 *    Gäste pro Link. Boolean-Spalten müssen in Nextcloud nullable sein; NULL
 *    zählt wie true (ArticleShare::allowsComments()).
 */
class Version1000Date20240101000029 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('merlin_comments')) {
			$table = $schema->createTable('merlin_comments');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull'       => true,
				'unsigned'      => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length'  => 64,
			]);
			$table->addColumn('article_id', Types::INTEGER, [
				'notnull'  => true,
				'unsigned' => true,
			]);
			$table->addColumn('highlight_id', Types::INTEGER, [
				'notnull'  => false,
				'unsigned' => true,
			]);
			$table->addColumn('quoted_text', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('parent_id', Types::INTEGER, [
				'notnull'  => false,
				'unsigned' => true,
			]);
			$table->addColumn('reply_to_id', Types::INTEGER, [
				'notnull'  => false,
				'unsigned' => true,
			]);
			$table->addColumn('author_type', Types::STRING, [
				'notnull' => true,
				'length'  => 8,
			]);
			$table->addColumn('author_name', Types::STRING, [
				'notnull' => true,
				'length'  => 64,
			]);
			$table->addColumn('author_name_key', Types::STRING, [
				'notnull' => true,
				'length'  => 64,
			]);
			$table->addColumn('body', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('deleted_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['article_id', 'user_id'], 'merlin_cmt_article_idx');
			$table->addIndex(['parent_id'], 'merlin_cmt_parent_idx');
			$changed = true;
		}

		if ($schema->hasTable('merlin_highlights')) {
			$table = $schema->getTable('merlin_highlights');
			if (!$table->hasColumn('author_type')) {
				$table->addColumn('author_type', Types::STRING, [
					'notnull' => true,
					'length'  => 8,
					'default' => 'owner',
				]);
				$changed = true;
			}
			if (!$table->hasColumn('author_name')) {
				$table->addColumn('author_name', Types::STRING, [
					'notnull' => false,
					'length'  => 64,
				]);
				$changed = true;
			}
			if (!$table->hasColumn('author_name_key')) {
				$table->addColumn('author_name_key', Types::STRING, [
					'notnull' => false,
					'length'  => 64,
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('merlin_shares')) {
			$table = $schema->getTable('merlin_shares');
			if (!$table->hasColumn('allow_comments')) {
				$table->addColumn('allow_comments', Types::BOOLEAN, [
					'notnull' => false,
					'default' => true,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
