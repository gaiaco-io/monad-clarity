<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Utils;

use Monad\Clarity\Utils\HMAC;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HMACTest extends TestCase
{
    public function testSignIsDeterministicForSameInputs(): void
    {
        self::assertSame(
            HMAC::sign('payload', 'key'),
            HMAC::sign('payload', 'key')
        );
    }

    public function testVerifyTrueForValidSignature(): void
    {
        $signature = HMAC::sign('payload', 'key');

        self::assertTrue(HMAC::verify('payload', $signature, 'key'));
    }

    public function testVerifyFalseWhenDataIsTampered(): void
    {
        $signature = HMAC::sign('payload', 'key');

        self::assertFalse(HMAC::verify('tampered-payload', $signature, 'key'));
    }

    public function testVerifyFalseWhenKeyIsWrong(): void
    {
        $signature = HMAC::sign('payload', 'key');

        self::assertFalse(HMAC::verify('payload', $signature, 'wrong-key'));
    }

    public function testVerifyFalseForGarbageSignature(): void
    {
        self::assertFalse(HMAC::verify('payload', 'not-a-real-signature', 'key'));
    }

    public function testDifferentKeysProduceDifferentSignatures(): void
    {
        self::assertNotSame(
            HMAC::sign('payload', 'key-a'),
            HMAC::sign('payload', 'key-b')
        );
    }

    public function testUnsupportedAlgorithmThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HMAC::sign('payload', 'key', 'not-a-real-algorithm');
    }
}
