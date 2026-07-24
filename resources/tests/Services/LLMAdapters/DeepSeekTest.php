<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\LLMAdapters;

use Monad\Clarity\Services\LLM\LLMException;
use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLMAdapters\DeepSeek;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DeepSeekTest extends TestCase
{
    private static function textResponse(): Response
    {
        return new Response(200, [], json_encode([
            'id' => 'ds-abc123',
            'model' => 'deepseek-chat',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello back!'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4, 'total_tokens' => 12],
        ], JSON_THROW_ON_ERROR));
    }

    public function testCompleteSendsCorrectRequestAndParsesTextResponse(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());
        $adapter = new DeepSeek('test-key', $fake);

        $response = $adapter->complete(new LLMRequest(
            model: 'deepseek-chat',
            messages: [['role' => 'user', 'content' => 'Hello']],
            systemInstruction: 'Be nice.',
        ));

        self::assertSame('deepseek', $response->provider);
        self::assertSame('Hello back!', $response->content);
        self::assertSame(['inputTokens' => 8, 'outputTokens' => 4], $response->usage);
        self::assertSame('ds-abc123', $response->providerRequestId);

        $request = $fake->lastRequest();
        self::assertSame('https://api.deepseek.com/chat/completions', (string) $request->getUri());
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));

        $body = $fake->decodedLastRequestBody();
        self::assertSame('Be nice.', $body['messages'][0]['content']);
        self::assertArrayNotHasKey('response_format', $body);
    }

    public function testResponseSchemaSetsJsonObjectModeAndAppendsSchemaToSystemInstruction(): void
    {
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'integer']]];
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'ds-def456',
            'choices' => [['message' => ['content' => '{"answer":42}']]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new DeepSeek('test-key', $fake);

        $response = $adapter->complete(new LLMRequest(
            model: 'deepseek-chat',
            messages: [['role' => 'user', 'content' => 'What is the answer?']],
            systemInstruction: 'Be terse.',
            responseSchema: $schema,
        ));

        self::assertSame(['answer' => 42], $response->content);

        $body = $fake->decodedLastRequestBody();
        self::assertSame(['type' => 'json_object'], $body['response_format']);
        self::assertStringStartsWith('Be terse.', $body['messages'][0]['content']);
        self::assertStringContainsString('"answer"', $body['messages'][0]['content']);
    }

    public function testResponseSchemaWithNoSystemInstructionStillPrependsTheSchemaInstruction(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => '{"answer":1}']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new DeepSeek('test-key', $fake);

        $adapter->complete(new LLMRequest(
            model: 'deepseek-chat',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));

        $body = $fake->decodedLastRequestBody();
        self::assertStringContainsString('Respond only with valid JSON', $body['messages'][0]['content']);
    }

    public function testMalformedStructuredContentThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'not json']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new DeepSeek('test-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(
            model: 'deepseek-chat',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));
    }

    public function testMissingChoicesContentThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode(['choices' => []])));
        $adapter = new DeepSeek('test-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(model: 'deepseek-chat', messages: [['role' => 'user', 'content' => 'x']]));
    }

    public function testNon2xxStatusThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(401, [], json_encode(['error' => 'bad key'])));
        $adapter = new DeepSeek('bad-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(model: 'deepseek-chat', messages: [['role' => 'user', 'content' => 'x']]));
    }
}
