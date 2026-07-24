<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Utils;

use Monad\Clarity\Utils\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function testRedactsKnownSensitiveKeys(): void
    {
        $redacted = Redactor::redact([
            'username' => 'marshal',
            'password' => 'hunter2',
        ]);

        self::assertSame('marshal', $redacted['username']);
        self::assertSame('[REDACTED]', $redacted['password']);
    }

    public function testPreservesNonSensitiveValues(): void
    {
        $redacted = Redactor::redact(['email' => 'marshal@gaiaco.io']);

        self::assertSame('marshal@gaiaco.io', $redacted['email']);
    }

    public function testRedactsNestedArrays(): void
    {
        $redacted = Redactor::redact([
            'user' => [
                'name' => 'marshal',
                'credentials' => ['api_key' => 'sk-live-abc123'],
            ],
        ]);

        self::assertSame('marshal', $redacted['user']['name']);
        self::assertSame('[REDACTED]', $redacted['user']['credentials']['api_key']);
    }

    public function testKeyMatchingIsCaseInsensitiveAndBySubstring(): void
    {
        $redacted = Redactor::redact([
            'Authorization' => 'Bearer abc123',
            'X-OpenAI-Api-Key' => 'sk-live-abc123',
        ]);

        self::assertSame('[REDACTED]', $redacted['Authorization']);
        self::assertSame('[REDACTED]', $redacted['X-OpenAI-Api-Key']);
    }

    public function testCustomSensitiveKeyList(): void
    {
        $redacted = Redactor::redact(['password' => 'hunter2', 'nickname' => 'nick'], ['nickname']);

        self::assertSame('hunter2', $redacted['password']);
        self::assertSame('[REDACTED]', $redacted['nickname']);
    }

    public function testEmptyArrayReturnsEmptyArray(): void
    {
        self::assertSame([], Redactor::redact([]));
    }
}
