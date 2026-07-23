<?php

declare(strict_types=1);

namespace Gaia\Clarity\Utils;

use InvalidArgumentException;
use RuntimeException;

/**
 * Authenticated at-rest encryption (AES-256-GCM). Every ciphertext carries its own
 * random IV and authentication tag, so tampering is detected rather than silently
 * decrypted into garbage.
 *
 * @package Gaia\Clarity\Utils
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    private function __construct()
    {
    }

    /**
     * Generate a fresh 256-bit key suitable for this cipher.
     */
    public static function generateKey(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    /**
     * Encrypt plaintext, returning a base64 payload of iv + tag + ciphertext.
     */
    public static function encrypt(string $plaintext, string $key): string
    {
        self::assertKeyLength($key);

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a payload produced by encrypt(). Throws if the key is wrong or the
     * payload has been tampered with — never returns partially-decrypted data.
     */
    public static function decrypt(string $payload, string $key): string
    {
        self::assertKeyLength($key);

        $decoded = base64_decode($payload, true);

        if ($decoded === false || strlen($decoded) < self::IV_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Malformed ciphertext.');
        }

        $iv = substr($decoded, 0, self::IV_BYTES);
        $tag = substr($decoded, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($decoded, self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: ciphertext is invalid or has been tampered with.');
        }

        return $plaintext;
    }

    private static function assertKeyLength(string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new InvalidArgumentException(
                sprintf('Encryption key must be exactly %d bytes, %d given.', self::KEY_BYTES, strlen($key))
            );
        }
    }
}
