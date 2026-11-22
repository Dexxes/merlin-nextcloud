<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Symmetrische Verschlüsselung für gespeicherte Geheimnisse (Paywall-
 * Zugangsdaten, Session-Cookies). Der Schlüssel gehört der Instanz.
 *
 * Nextcloud: ICrypto (Schlüssel aus config.php "secret").
 */
interface Crypto {
	public function encrypt(string $plaintext): string;

	/**
	 * @throws \Throwable wenn der Text nicht entschlüsselt werden kann
	 */
	public function decrypt(string $ciphertext): string;
}
