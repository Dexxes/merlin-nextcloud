<?php

declare(strict_types=1);

namespace Merlin\Core\Extraction\Login;

/**
 * Login gegen die Paywall-Seite ist fehlgeschlagen. $reason unterscheidet
 * "Zugangsdaten falsch" (LoginStatus::INVALID_CREDENTIALS, Nutzer
 * muss Passwort korrigieren) von "Login-Ablauf der Seite kaputt"
 * (LoginStatus::LOGIN_FLOW_BROKEN, Wartungsfall - siehe
 * PianoJsonFormLoginProvider-Docblock).
 */
class LoginFailedException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly string $reason,
	) {
		parent::__construct($message);
	}
}
