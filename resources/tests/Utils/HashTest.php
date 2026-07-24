<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Utils;

use Monad\Clarity\Utils\Hash;
use PHPUnit\Framework\TestCase;

/**
 * Tests target the make/verify/needsRehash contract, not a specific algorithm —
 * whether the runtime resolves to Argon2id or bcrypt depends on how PHP was built.
 */
final class HashTest extends TestCase
{
    public function testMakeProducesVerifiableHash(): void
    {
        $hash = Hash::make('correct horse battery staple');

        self::assertTrue(Hash::verify('correct horse battery staple', $hash));
    }

    public function testVerifyFailsForWrongValue(): void
    {
        $hash = Hash::make('correct horse battery staple');

        self::assertFalse(Hash::verify('wrong password', $hash));
    }

    public function testMakeProducesDifferentHashesForSameValue(): void
    {
        self::assertNotSame(Hash::make('same-password'), Hash::make('same-password'));
    }

    public function testNeedsRehashIsFalseForAFreshHash(): void
    {
        self::assertFalse(Hash::needsRehash(Hash::make('correct horse battery staple')));
    }

    public function testNeedsRehashIsTrueWhenCostOptionsChange(): void
    {
        // Which options matter depends on whether this PHP build resolved to Argon2id
        // or bcrypt — pick the option key that actually affects the active algorithm.
        $usesArgon2id = in_array(PASSWORD_ARGON2ID, password_algos(), true);
        $weakOptions = $usesArgon2id ? ['memory_cost' => 1 << 10, 'time_cost' => 1, 'threads' => 1] : ['cost' => 4];
        $strongOptions = $usesArgon2id ? ['memory_cost' => 1 << 17, 'time_cost' => 4, 'threads' => 2] : ['cost' => 12];

        $hash = Hash::make('correct horse battery staple', $weakOptions);

        self::assertTrue(Hash::needsRehash($hash, $strongOptions));
    }
}
