<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Utils;

use Gaia\Clarity\Utils\CryptographicToken;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CryptographicTokenTest extends TestCase
{
    public function testGenerateReturnsHexStringOfExpectedLength(): void
    {
        $token = CryptographicToken::generate(16);

        self::assertSame(32, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    public function testGenerateDefaultLength(): void
    {
        self::assertSame(64, strlen(CryptographicToken::generate()));
    }

    public function testGenerateIsUnpredictable(): void
    {
        $tokens = [];

        for ($i = 0; $i < 200; $i++) {
            $tokens[CryptographicToken::generate()] = true;
        }

        self::assertCount(200, $tokens);
    }

    public function testGenerateUrlSafeContainsOnlyUrlSafeCharacters(): void
    {
        $token = CryptographicToken::generateUrlSafe(32);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $token);
    }

    public function testGenerateUrlSafeIsUnpredictable(): void
    {
        $tokens = [];

        for ($i = 0; $i < 200; $i++) {
            $tokens[CryptographicToken::generateUrlSafe()] = true;
        }

        self::assertCount(200, $tokens);
    }

    public function testGenerateRejectsNonPositiveLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CryptographicToken::generate(0);
    }

    public function testGenerateRejectsNegativeLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CryptographicToken::generate(-1);
    }
}
