<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services\LLMAdapters;

use Gaia\Clarity\Services\LLM\LLMException;
use Gaia\Clarity\Services\LLM\LLMRequest;
use Gaia\Clarity\Services\LLMAdapters\OpenAI;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OpenAITest extends TestCase
{
    private static function textResponse(): Response
    {
        return new Response(200, [], json_encode([
            'id' => 'chatcmpl-abc123',
            'model' => 'gpt-5',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello back!'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4, 'total_tokens' => 12],
        ], JSON_THROW_ON_ERROR));
    }

    public function testCompleteSendsCorrectRequestAndParsesTextResponse(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());
        $adapter = new OpenAI('test-key', $fake);

        $response = $adapter->complete(new LLMRequest(
            model: 'gpt-5',
            messages: [['role' => 'user', 'content' => 'Hello']],
            systemInstruction: 'Be nice.',
            temperature: 0.7,
            maxOutputTokens: 512,
        ));

        self::assertSame('openai', $response->provider);
        self::assertSame('gpt-5', $response->model);
        self::assertSame('Hello back!', $response->content);
        self::assertSame(['inputTokens' => 8, 'outputTokens' => 4], $response->usage);
        self::assertSame('chatcmpl-abc123', $response->providerRequestId);

        $request = $fake->lastRequest();
        self::assertSame('https://api.openai.com/v1/chat/completions', (string) $request->getUri());
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));

        $body = $fake->decodedLastRequestBody();
        self::assertSame([
            ['role' => 'system', 'content' => 'Be nice.'],
            ['role' => 'user', 'content' => 'Hello'],
        ], $body['messages']);
        self::assertSame(512, $body['max_tokens']);
        self::assertSame(0.7, $body['temperature']);
        self::assertArrayNotHasKey('response_format', $body);
    }

    public function testCompleteOmitsSystemMessageWhenNoInstructionGiven(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());
        $adapter = new OpenAI('test-key', $fake);

        $adapter->complete(new LLMRequest(model: 'gpt-5', messages: [['role' => 'user', 'content' => 'Hi']]));

        self::assertSame([['role' => 'user', 'content' => 'Hi']], $fake->decodedLastRequestBody()['messages']);
    }

    public function testResponseSchemaUsesStrictJsonSchemaModeAndDecodesTheContent(): void
    {
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'integer']]];
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'chatcmpl-def456',
            'model' => 'gpt-5',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"answer":42}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new OpenAI('test-key', $fake);

        $response = $adapter->complete(new LLMRequest(
            model: 'gpt-5',
            messages: [['role' => 'user', 'content' => 'What is the answer?']],
            responseSchema: $schema,
        ));

        self::assertSame(['answer' => 42], $response->content);

        $body = $fake->decodedLastRequestBody();
        self::assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'structured_response', 'schema' => $schema, 'strict' => true],
        ], $body['response_format']);
    }

    public function testMalformedStructuredContentThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'not json']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new OpenAI('test-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(
            model: 'gpt-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));
    }

    public function testMissingChoicesContentThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode(['choices' => []])));
        $adapter = new OpenAI('test-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(model: 'gpt-5', messages: [['role' => 'user', 'content' => 'x']]));
    }

    public function testNon2xxStatusThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(401, [], json_encode(['error' => ['message' => 'bad key']])));
        $adapter = new OpenAI('bad-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(model: 'gpt-5', messages: [['role' => 'user', 'content' => 'x']]));
    }

    public function testMalformedJsonBodyThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], '{not valid'));
        $adapter = new OpenAI('test-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(model: 'gpt-5', messages: [['role' => 'user', 'content' => 'x']]));
    }
}
