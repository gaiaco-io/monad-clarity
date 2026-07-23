<?php

declare(strict_types=1);

namespace Gaia\Clarity\Utils;

use InvalidArgumentException;

/**
 * Cryptographically secure random token generation, for session identifiers, CSRF
 * tokens, password reset codes, API keys, and anything else that must not be guessable.
 *
 * @package Gaia\Clarity\Utils
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class CryptographicToken
{
    private const DEFAULT_BYTES = 32;

    private function __construct()
    {
    }

    /**
     * Generate a token as a hex string. Length in the returned string is $bytes * 2.
     */
    public static function generate(int $bytes = self::DEFAULT_BYTES): string
    {
        return bin2hex(random_bytes(self::assertPositive($bytes)));
    }

    /**
     * Generate a token safe for use in URLs and cookies without further encoding
     * (base64url, per RFC 4648 §5, unpadded).
     */
    public static function generateUrlSafe(int $bytes = self::DEFAULT_BYTES): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::assertPositive($bytes))), '+/', '-_'), '=');
    }

    private static function assertPositive(int $bytes): int
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException(sprintf('Token byte length must be positive, %d given.', $bytes));
        }

        return $bytes;
    }
}
