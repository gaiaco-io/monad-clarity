<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\LLMAdapters;

use Monad\Clarity\Services\LLM\LLMException;
use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLMAdapters\Anthropic;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class AnthropicTest extends TestCase
{
    private static function textResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_01abc',
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => 'Hello back!']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR));
    }

    public function testCompleteSendsCorrectRequestAndParsesTextResponse(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());
        $adapter = new Anthropic('test-key', $fake);

        $response = $adapter->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'Hello']],
            systemInstruction: 'Be nice.',
            temperature: 0.5,
            maxOutputTokens: 256,
        ));

        self::assertSame('anthropic', $response->provider);
        self::assertSame('claude-sonnet-5', $response->model);
        self::assertSame('Hello back!', $response->content);
        self::assertSame(['inputTokens' => 10, 'outputTokens' => 5], $response->usage);
        self::assertSame('msg_01abc', $response->providerRequestId);

        $request = $fake->lastRequest();
        self::assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        self::assertSame('test-key', $request->getHeaderLine('x-api-key'));
        self::assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));

        $body = $fake->decodedLastRequestBody();
        self::assertSame('claude-sonnet-5', $body['model']);
        self::assertSame('Be nice.', $body['system']);
        self::assertSame(256, $body['max_tokens']);
        self::assertSame(0.5, $body['temperature']);
        self::assertSame([['role' => 'user', 'content' => 'Hello']], $body['messages']);
        self::assertArrayNotHasKey('tools', $body);
    }

    public function testCompleteOmitsSystemFieldWhenNoInstructionGiven(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());
        $adapter = new Anthropic('test-key', $fake);

        $adapter->complete(new LLMRequest(model: 'claude-sonnet-5', messages: [['role' => 'user', 'content' => 'Hi']]));

        self::assertArrayNotHasKey('system', $fake->decodedLastRequestBody());
    }

    public function testResponseSchemaAddsAForcedStructuredTool(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_02def',
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'tool_use', 'name' => 'structured_response', 'input' => ['answer' => 42]]],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Anthropic('test-key', $fake);

        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'integer']]];
        $response = $adapter->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'What is the answer?']],
            responseSchema: $schema,
        ));

        self::assertSame(['answer' => 42], $response->content);

        $body = $fake->decodedLastRequestBody();
        self::assertSame([['name' => 'structured_response', 'input_schema' => $schema]], $body['tools']);
        self::assertSame(['type' => 'tool', 'name' => 'structured_response'], $body['tool_choice']);
    }

    public function testMissingStructuredToolUseBlockThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_03',
            'content' => [['type' => 'text', 'text' => 'oops, ignored the tool']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Anthropic('test-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));
    }

    public function testNon2xxStatusThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(401, [], json_encode(['error' => ['message' => 'bad key']])));
        $adapter = new Anthropic('bad-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(model: 'claude-sonnet-5', messages: [['role' => 'user', 'content' => 'x']]));
    }

    public function testMalformedJsonBodyThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], '{not valid'));
        $adapter = new Anthropic('test-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(model: 'claude-sonnet-5', messages: [['role' => 'user', 'content' => 'x']]));
    }

}
