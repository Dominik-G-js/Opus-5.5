<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Autentizované šifrování (XSalsa20-Poly1305, libsodium) pro tokeny API a TOTP tajemství v DB.
 */
final class Crypto
{
    private string $key;

    public function __construct(string $encodedKey)
    {
        if (!str_starts_with($encodedKey, 'base64:')) {
            throw new RuntimeException('app_key musí mít tvar base64:… (vygeneruj přes php bin/console install).');
        }
        $key = base64_decode(substr($encodedKey, 7), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('app_key musí obsahovat 32 bajtů.');
        }
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, 'v1:')) {
            throw new RuntimeException('Neznámý formát šifrovaných dat.');
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Poškozená šifrovaná data.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plaintext === false) {
            throw new RuntimeException('Data nelze dešifrovat (změnil se app_key?).');
        }

        return $plaintext;
    }

    /** @param array<string, mixed> $data */
    public function encryptJson(array $data): string
    {
        return $this->encrypt(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function decryptJson(string $payload): array
    {
        $data = json_decode($this->decrypt($payload), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Šifrovaná data nejsou objekt.');
        }

        return $data;
    }

    /** Deterministický HMAC (např. pro anonymní deduplikační klíče). */
    public function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key);
    }
}
