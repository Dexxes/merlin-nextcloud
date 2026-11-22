<?php

declare(strict_types=1);

namespace Merlin\Core\Extraction\Login;

/**
 * Ergebnis des letzten Login-Versuchs für eine Paywall-Domain. Die Werte
 * stehen so in der Datenbank (merlin_site_credentials.last_login_status) und
 * gehen an die Clients; OCA\Merlin\Db\SiteCredential übernimmt sie als
 * STATUS_*-Konstanten.
 */
final class LoginStatus {
	/** Login erfolgreich, Cookies gültig. */
	public const OK = 'ok';
	/** Zugangsdaten falsch, der Nutzer muss sie korrigieren. */
	public const INVALID_CREDENTIALS = 'invalid_credentials';
	/** Login-Ablauf der Seite hat sich geändert (Wartungsfall). */
	public const LOGIN_FLOW_BROKEN = 'login_flow_broken';
	/** Zugangsdaten gespeichert, noch kein Login-Versuch. */
	public const PENDING = 'pending';

	private function __construct() {
	}
}
