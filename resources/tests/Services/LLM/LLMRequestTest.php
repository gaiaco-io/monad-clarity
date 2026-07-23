<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services\LLM;

use Gaia\Clarity\Services\LLM\LLMRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LLMRequestTest extends TestCase
{
    private static function validMessages(): array
    {
        return [['role' => 'user', 'content' => 'Hello']];
    }

    public function testConstructsWithDefaultsFromMinimalArguments(): void
    {
        $request = new LLMRequest(model: 'gpt-5', messages: self::validMessages());

        self::assertSame('gpt-5', $request->model);
        self::assertSame(self::validMessages(), $request->messages);
        self::assertNull($request->systemInstruction);
        self::assertSame(1.0, $request->temperature);
        self::assertSame(1024, $request->maxOutputTokens);
        self::assertSame(30, $request->timeoutSeconds);
        self::assertNull($request->responseSchema);
    }

    public function testRejectsAnEmptyModel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LLMRequest(model: '', messages: self::validMessages());
    }

    public function testRejectsAnEmptyMessageList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LLMRequest(model: 'gpt-5', messages: []);
    }

    public function testRejectsAMessageWithAnInvalidRole(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LLMRequest(model: 'gpt-5', messages: [['role' => 'system', 'content' => 'nope']]);
    }

    public function testRejectsAMessageMissingContent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LLMRequest(model: 'gpt-5', messages: [['role' => 'user']]);
    }

    public function testRejectsTemperatureBelowZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LLMRequest(model: 'gpt-5', messages: self::validMessages(), temperature: -0.1);
    }

    public function testRejectsTemperatureAboveTwo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LLMRequest(model: 'gpt-5', messages: self::validMessages(), temperature: 2.1);
    }

    public function testRejectsZeroMaxOutputTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LLMRequest(model: 'gpt-5', messages: self::validMessages(), maxOutputTokens: 0);
    }

    public function testRejectsZeroTimeoutSeconds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LLMRequest(model: 'gpt-5', messages: self::validMessages(), timeoutSeconds: 0);
    }

    public function testAcceptsAnOptionalResponseSchema(): void
    {
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']]];
        $request = new LLMRequest(model: 'gpt-5', messages: self::validMessages(), responseSchema: $schema);

        self::assertSame($schema, $request->responseSchema);
    }
}
