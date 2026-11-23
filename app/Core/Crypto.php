<?php

declare(strict_types=1);

namespace App\Core;

/** Symmetric encryption for secrets stored in the DB (SMTP passwords). */
final class Crypto
{
    private static function key(): string
    {
        $k = (string) config('app_key', '');
        if (str_starts_with($k, 'base64:')) {
            $k = base64_decode(substr($k, 7), true) ?: '';
        }
        if (strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid app_key in config: run bin/install.php or generate one with bin/keygen.php');
        }
        return $k;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(sodium_crypto_secretbox_keygen());
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $cipher): string
    {
        if ($cipher === null || $cipher === '') {
            return '';
        }
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? '' : $plain;
    }
}
