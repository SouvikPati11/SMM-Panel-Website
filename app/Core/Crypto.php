<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Symmetric encryption for secrets stored in the database (gateway and
 * provider credentials). Uses libsodium secretbox with a key derived from APP_KEY.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    private static function key(): string
    {
        $appKey = (string) Config::get('key', '');
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is not configured.');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }
        return sodium_crypto_generichash($appKey, 'smm-panel-secretbox', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $cipher): string
    {
        if ($cipher === null || $cipher === '') {
            return '';
        }
        if (!str_starts_with($cipher, self::PREFIX)) {
            throw new \RuntimeException('Unrecognised ciphertext format.');
        }
        $raw = base64_decode(substr($cipher, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupted ciphertext.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Unable to decrypt secret (APP_KEY changed?).');
        }
        return $plain;
    }

    /** Encrypt an array of credentials as JSON. */
    public static function encryptArray(array $data): string
    {
        return self::encrypt(json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public static function decryptArray(?string $cipher): array
    {
        if (!$cipher) {
            return [];
        }
        try {
            $data = json_decode(self::decrypt($cipher), true);
        } catch (\Throwable $e) {
            Logger::error('Credential decrypt failed: ' . $e->getMessage());
            return [];
        }
        return is_array($data) ? $data : [];
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /** Mask a secret for display: "abcd••••••wxyz" */
    public static function mask(string $secret): string
    {
        $len = strlen($secret);
        if ($len === 0) {
            return '';
        }
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($secret, 0, 4) . str_repeat('•', 6) . substr($secret, -4);
    }
}
