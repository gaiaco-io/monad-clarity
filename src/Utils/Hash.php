<?php

declare(strict_types=1);

namespace Gaia\Clarity\Utils;

/**
 * Password hashing. Uses Argon2id where the runtime supports it, falling back to
 * bcrypt otherwise — never a fixed algorithm that could quietly outlive its safety.
 *
 * @package Gaia\Clarity\Utils
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Hash
{
    private function __construct()
    {
    }

    /**
     * Hash a value (password) for storage.
     */
    public static function make(string $value, array $options = []): string
    {
        return password_hash($value, self::algorithm(), $options);
    }

    /**
     * Verify a value against a stored hash, in constant time.
     */
    public static function verify(string $value, string $hash): bool
    {
        return password_verify($value, $hash);
    }

    /**
     * Whether a stored hash should be regenerated (weaker algorithm/cost than current default).
     */
    public static function needsRehash(string $hash, array $options = []): bool
    {
        return password_needs_rehash($hash, self::algorithm(), $options);
    }

    private static function algorithm(): string
    {
        return in_array(PASSWORD_ARGON2ID, password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
