<?php

declare(strict_types=1);

namespace Gaia\Clarity\Utils;

use InvalidArgumentException;

/**
 * HMAC signing and verification, for session-less integrity checks (e.g. CSRF tokens
 * before a session exists, signed URL payloads).
 *
 * @package Gaia\Clarity\Utils
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class HMAC
{
    private const DEFAULT_ALGORITHM = 'sha256';

    private function __construct()
    {
    }

    /**
     * Sign data with a key, returning a hex-encoded digest.
     */
    public static function sign(string $data, string $key, string $algorithm = self::DEFAULT_ALGORITHM): string
    {
        self::assertSupportedAlgorithm($algorithm);

        return hash_hmac($algorithm, $data, $key);
    }

    /**
     * Verify a signature against data and a key, in constant time.
     */
    public static function verify(
        string $data,
        string $signature,
        string $key,
        string $algorithm = self::DEFAULT_ALGORITHM
    ): bool {
        return ConstantTime::equals(self::sign($data, $key, $algorithm), $signature);
    }

    private static function assertSupportedAlgorithm(string $algorithm): void
    {
        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            throw new InvalidArgumentException(sprintf('Unsupported HMAC algorithm "%s".', $algorithm));
        }
    }
}
