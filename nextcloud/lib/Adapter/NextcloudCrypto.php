<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\Crypto;
use OCP\Security\ICrypto;

/**
 * Crypto-Port des Kerns über ICrypto (Schlüssel: "secret" aus config.php).
 */
class NextcloudCrypto implements Crypto {
	public function __construct(
		private ICrypto $crypto,
	) {
	}

	public function encrypt(string $plaintext): string {
		return $this->crypto->encrypt($plaintext);
	}

	public function decrypt(string $ciphertext): string {
		return $this->crypto->decrypt($ciphertext);
	}
}
