<?php

declare(strict_types=1);

namespace Gaia\Clarity\Utils;

/**
 * Constant-time string comparison, used anywhere a timing side-channel could leak
 * information about a secret value (tokens, HMACs, password reset codes).
 *
 * @package Gaia\Clarity\Utils
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class ConstantTime
{
    private function __construct()
    {
    }

    /**
     * Compare two strings without leaking, via response time, where they first differ.
     */
    public static function equals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }
}
