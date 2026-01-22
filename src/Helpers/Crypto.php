<?php

namespace AperturePro\Helpers;

/**
 * Crypto helper
 *
 * Provides simple symmetric encryption/decryption helpers for storing secrets in the DB.
 *
 * Implementation notes:
 *  - Uses OpenSSL AES-256-CBC when available.
 *  - Falls back to Sodium (libsodium) if available.
 *  - Derives an encryption key from WordPress secret constants (AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY).
 *  - Output format: base64(iv . ':' . ciphertext) for OpenSSL; base64('sodium:' . ciphertext) for sodium.
 *
 * Security notes:
 *  - The derived key is site-specific (depends on WP salts). If you migrate the DB to another site,
 *    encrypted values will not decrypt unless the same salts are used.
 *  - For production-grade security, consider using a dedicated secrets manager (KMS, Vault).
 */
class Crypto
{
    const OPENSSL_CIPHER = 'AES-256-CBC';
    const SODIUM_PREFIX = 'sodium:';

    protected static $_key = null;

    /**
     * Derive a 32-byte key from WordPress secret constants.
     *
     * @return string raw binary key (32 bytes)
     */
    protected static function deriveKey(): string
    {
        if (self::$_key !== null) {
            return self::$_key;
        }

        // Use WP salts if available; fall back to AUTH_KEY constant or site URL
        $parts = [];
        if (defined('AUTH_KEY')) {
            $parts[] = AUTH_KEY;
        }
        if (defined('SECURE_AUTH_KEY')) {
            $parts[] = SECURE_AUTH_KEY;
        }
        if (defined('LOGGED_IN_KEY')) {
            $parts[] = LOGGED_IN_KEY;
        }
        if (empty($parts)) {
            // Fallback: use a generated salt stored in the database
            $salt = get_option('aperture_generated_salt');

            if (!$salt) {
                // Generate a secure random salt
                try {
                    $bytes = random_bytes(32);
                } catch (\Exception $e) {
                    $bytes = openssl_random_pseudo_bytes(32);
                }
                $salt = bin2hex($bytes);
                update_option('aperture_generated_salt', $salt);
            }
            $parts[] = $salt;
        }

        $seed = implode('|', $parts);
        // Use SHA-256 to derive 32 bytes
        self::$_key = hash('sha256', $seed, true);

        return self::$_key;
    }

    /**
     * Encrypt plaintext and return a base64-encoded payload.
     *
     * @param string $plaintext
     * @return string base64-encoded payload
     */
    public static function encrypt(string $plaintext): string
    {
        if (empty($plaintext)) {
            return '';
        }

        // Prefer sodium if available and PHP version supports it
        if (function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_keygen')) {
            try {
                $key = self::deriveKey();
                // sodium expects 32-byte key
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
                $payload = self::SODIUM_PREFIX . base64_encode($nonce . $cipher);
                return base64_encode($payload);
            } catch (\Throwable $e) {
                // fallback to openssl
            }
        }

        // OpenSSL AES-256-CBC
        if (function_exists('openssl_encrypt')) {
            $key = self::deriveKey();
            $ivlen = openssl_cipher_iv_length(self::OPENSSL_CIPHER);
            $iv = openssl_random_pseudo_bytes($ivlen);
            $ciphertext = openssl_encrypt($plaintext, self::OPENSSL_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
            if ($ciphertext === false) {
                return '';
            }
            $payload = base64_encode($iv . ':' . $ciphertext);
            return $payload;
        }

        // As a last resort, store plaintext (not recommended) — but we avoid this path
        return base64_encode('plain:' . $plaintext);
    }

    /**
     * Decrypt a payload produced by encrypt().
     *
     * @param string $payload base64-encoded payload
     * @return string|null plaintext or null on failure
     */
    public static function decrypt(string $payload): ?string
    {
        if (empty($payload)) {
            return null;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        // If sodium format
        if (strpos($decoded, self::SODIUM_PREFIX) === 0) {
            $inner = substr($decoded, strlen(self::SODIUM_PREFIX));
            $raw = base64_decode($inner, true);
            if ($raw === false) {
                return null;
            }
            if (!function_exists('sodium_crypto_secretbox_open')) {
                return null;
            }
            $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $nonce = substr($raw, 0, $nonceLen);
            $cipher = substr($raw, $nonceLen);
            $key = self::deriveKey();
            try {
                $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
                if ($plain === false) {
                    return null;
                }
                return $plain;
            } catch (\Throwable $e) {
                return null;
            }
        }

        // OpenSSL format: iv:ciphertext (both binary) base64-encoded
        if (function_exists('openssl_decrypt')) {
            $parts = explode(':', $decoded, 2);
            if (count($parts) !== 2) {
                return null;
            }
            $iv = $parts[0];
            $ciphertext = $parts[1];
            $key = self::deriveKey();
            $plain = openssl_decrypt($ciphertext, self::OPENSSL_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
            if ($plain === false) {
                return null;
            }
            return $plain;
        }

        // Fallback: check for 'plain:' prefix
        if (strpos($decoded, 'plain:') === 0) {
            return substr($decoded, 6);
        }

        return null;
    }
}
