<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create merlin_shares table for public article share links.
 *
 * Ein Datensatz pro Artikel (nicht pro Empfänger): "Regenerieren" überschreibt
 * `token` im bestehenden Datensatz statt einen neuen anzulegen; "Widerrufen"
 * löscht die Zeile. `token` ist die einzige Möglichkeit für einen anonymen
 * Besucher, an den Artikel zu gelangen — daher UNIQUE + eigener Index, damit
 * die Auflösung `/s/{token}` ohne Kenntnis von `article_id`/`user_id` funktioniert.
 */
class Version1000Date20240101000019 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('merlin_shares')) {
			return null;
		}

		$table = $schema->createTable('merlin_shares');
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
		// 32 Hex-Zeichen = bin2hex(random_bytes(16)), 128 Bit Entropie.
		$table->addColumn('token', Types::STRING, [
			'notnull' => true,
			'length'  => 64,
		]);
		// NULL = kein Passwort (offener Link); sonst password_hash()-Bcrypt-Hash.
		$table->addColumn('password_hash', Types::STRING, [
			'notnull' => false,
			'length'  => 255,
			'default' => null,
		]);
		// NULL = kein Ablauf.
		$table->addColumn('expires_at', Types::DATETIME, [
			'notnull' => false,
			'default' => null,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('updated_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		// Ein Share pro Artikel: article_id eindeutig statt (user_id, article_id),
		// da ein Artikel ohnehin genau einem Nutzer gehört.
		$table->addUniqueIndex(['article_id'], 'merlin_shares_article_uidx');
		$table->addUniqueIndex(['token'], 'merlin_shares_token_uidx');

		return $schema;
	}
}
