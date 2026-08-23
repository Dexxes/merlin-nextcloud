<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create merlin_site_cred table: verschlüsselte Paywall-Abo-Zugangsdaten je
 * Nutzer/Domain (z. B. Tagesspiegel Plus), siehe Service\SiteCredentialService
 * und Service\Login\*. username/password und der gewonnene Session-Cookie-Satz
 * liegen verschlüsselt (OCP\Security\ICrypto) in *_enc-Spalten - nie im
 * Klartext in der DB.
 */
class Version1000Date20240101000021 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('merlin_site_cred')) {
			return null;
		}

		$table = $schema->createTable('merlin_site_cred');
		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull'       => true,
			'unsigned'      => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length'  => 64,
		]);
		$table->addColumn('domain', Types::STRING, [
			'notnull' => true,
			'length'  => 255,
		]);
		$table->addColumn('username_enc', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('password_enc', Types::TEXT, [
			'notnull' => true,
		]);
		// JSON-Objekt (Cookie-Name => Wert) der zuletzt per Login gewonnenen
		// Session-Cookies, verschlüsselt. NULL, solange noch kein Login geglückt ist.
		$table->addColumn('session_cookies_enc', Types::TEXT, [
			'notnull' => false,
		]);
		// Ablaufzeit des kürzesten der gespeicherten Cookies (Max-Age der
		// Login-Response) - danach gilt der Satz als abgelaufen und ein
		// erneuter Login wird versucht, siehe SiteCredentialService.
		$table->addColumn('cookie_expires_at', Types::DATETIME, [
			'notnull' => false,
		]);
		// SiteCredential::STATUS_* - Grund für UI-Statusanzeige ("Zugangsdaten
		// prüfen") ohne dass das Passwort erneut angezeigt werden müsste.
		$table->addColumn('last_login_status', Types::STRING, [
			'notnull' => true,
			'length'  => 32,
			'default' => 'pending',
		]);
		$table->addColumn('last_login_at', Types::DATETIME, [
			'notnull' => false,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		// Ein Eintrag pro Nutzer und Domain.
		$table->addUniqueIndex(['user_id', 'domain'], 'merlin_site_cred_ud_uidx');

		return $schema;
	}
}
