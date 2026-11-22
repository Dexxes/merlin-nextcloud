<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Crypto;
use Merlin\Core\Port\InstanceSecret;

/**
 * Crypto-Port mit sodium_crypto_secretbox (in Nextcloud: ICrypto). Der
 * Schlüssel wird aus dem Instanz-Geheimnis abgeleitet; Format: base64 von
 * Nonce + Chiffrat, wie CredentialCipher in merlin-standalone-server.
 */
final class SodiumCrypto implements Crypto {
	private ?string $key = null;

	public function __construct(private InstanceSecret $secret) {
	}

	public function encrypt(string $plaintext): string {
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key()));
	}

	public function decrypt(string $ciphertext): string {
		$raw = base64_decode($ciphertext, true);
		if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			throw new \RuntimeException('Ungültiges verschlüsseltes Feld.');
		}
		$plaintext = sodium_crypto_secretbox_open(
			substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			$this->key(),
		);
		if ($plaintext === false) {
			throw new \RuntimeException('Entschlüsselung fehlgeschlagen (falscher Schlüssel oder veränderte Daten).');
		}
		return $plaintext;
	}

	private function key(): string {
		return $this->key ??= sodium_crypto_generichash(
			'merlin-crypto',
			$this->secret->get(),
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
		);
	}
}
