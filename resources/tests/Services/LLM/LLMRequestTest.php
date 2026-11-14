<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\LLM;

use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLM\ReferenceImage;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ------------------------------------------------------------- images (1.10.0)

    public function testAUserMessageMayCarryImagesAndKeepsItsStringContent(): void
    {
        $images = [new ReferenceImage('image/jpeg', 'before'), new ReferenceImage('image/png', 'after')];
        $request = new LLMRequest(model: 'gemini-3.5-flash', messages: [
            ['role' => 'user', 'content' => 'What changed?', 'images' => $images],
        ]);

        self::assertSame('What changed?', $request->messages[0]['content']);
        self::assertSame($images, $request->messages[0]['images']);
    }

    public function testAnImageMessageMayHaveEmptyContent(): void
    {
        $request = new LLMRequest(model: 'm', messages: [
            ['role' => 'user', 'content' => '', 'images' => [new ReferenceImage('image/jpeg', 'x')]],
        ]);

        self::assertSame('', $request->messages[0]['content']);
    }

    public function testMessagesWithoutImagesAreUnchanged(): void
    {
        $request = new LLMRequest(model: 'gpt-5', messages: self::validMessages());

        self::assertArrayNotHasKey('images', $request->messages[0]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidImageMessages(): iterable
    {
        yield 'assistant turn' => [
            ['role' => 'assistant', 'content' => 'x', 'images' => [new ReferenceImage('image/jpeg', 'x')]],
            'Message 1 is an assistant turn carrying images',
        ];
        yield 'empty list' => [['role' => 'user', 'content' => 'x', 'images' => []], 'must be a non-empty list'];
        yield 'keyed array' => [
            ['role' => 'user', 'content' => 'x', 'images' => ['a' => new ReferenceImage('image/jpeg', 'x')]],
            'must be a non-empty list',
        ];
        yield 'null' => [['role' => 'user', 'content' => 'x', 'images' => null], 'must be a non-empty list'];
        yield 'raw bytes' => [['role' => 'user', 'content' => 'x', 'images' => ['jpeg-bytes']], 'found string'];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('invalidImageMessages')]
    public function testRejectsInvalidImagesNamingTheMessage(array $message, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        new LLMRequest(model: 'm', messages: [['role' => 'user', 'content' => 'first'], $message]);
    }
}
