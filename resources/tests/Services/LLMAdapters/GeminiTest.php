<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services\LLMAdapters;

use Gaia\Clarity\Services\LLM\LLMException;
use Gaia\Clarity\Services\LLM\LLMRequest;
use Gaia\Clarity\Services\LLMAdapters\Gemini;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GeminiTest extends TestCase
{
    private static function textResponse(): Response
    {
        return new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Hello back!']], 'role' => 'model'], 'finishReason' => 'STOP', 'index' => 0]],
            'usageMetadata' => ['promptTokenCount' => 8, 'candidatesTokenCount' => 4, 'totalTokenCount' => 12],
            'modelVersion' => 'gemini-3-pro',
            'responseId' => 'resp-abc123',
        ], JSON_THROW_ON_ERROR));
    }

    public function testCompleteSendsCorrectRequestAndParsesTextResponse(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());
        $adapter = new Gemini('test-key', $fake);

        $response = $adapter->complete(new LLMRequest(
            model: 'gemini-3-pro',
            messages: [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there'],
                ['role' => 'user', 'content' => 'How are you?'],
            ],
            systemInstruction: 'Be nice.',
            temperature: 0.9,
            maxOutputTokens: 512,
        ));

        self::assertSame('gemini', $response->provider);
        self::assertSame('gemini-3-pro', $response->model);
        self::assertSame('Hello back!', $response->content);
        self::assertSame(['inputTokens' => 8, 'outputTokens' => 4], $response->usage);
        self::assertSame('resp-abc123', $response->providerRequestId);

        $request = $fake->lastRequest();
        self::assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro:generateContent?key=test-key',
            (string) $request->getUri()
        );

        $body = $fake->decodedLastRequestBody();
        self::assertSame(['parts' => [['text' => 'Be nice.']]], $body['systemInstruction']);
        self::assertSame([
            ['role' => 'user', 'parts' => [['text' => 'Hello']]],
            ['role' => 'model', 'parts' => [['text' => 'Hi there']]],
            ['role' => 'user', 'parts' => [['text' => 'How are you?']]],
        ], $body['contents']);
        self::assertSame(0.9, $body['generationConfig']['temperature']);
        self::assertSame(512, $body['generationConfig']['maxOutputTokens']);
        self::assertArrayNotHasKey('responseSchema', $body['generationConfig']);
    }

    public function testApiKeyIsUrlEncodedInTheQueryString(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());
        $adapter = new Gemini('key with spaces', $fake);

        $adapter->complete(new LLMRequest(model: 'gemini-3-pro', messages: [['role' => 'user', 'content' => 'x']]));

        self::assertStringContainsString('key=key%20with%20spaces', (string) $fake->lastRequest()->getUri());
    }

    public function testResponseSchemaSetsJsonMimeTypeAndDecodesTheContent(): void
    {
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'integer']]];
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => '{"answer":42}']]]]],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2],
            'modelVersion' => 'gemini-3-pro',
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Gemini('test-key', $fake);

        $response = $adapter->complete(new LLMRequest(
            model: 'gemini-3-pro',
            messages: [['role' => 'user', 'content' => 'What is the answer?']],
            responseSchema: $schema,
        ));

        self::assertSame(['answer' => 42], $response->content);

        $body = $fake->decodedLastRequestBody();
        self::assertSame('application/json', $body['generationConfig']['responseMimeType']);
        self::assertSame($schema, $body['generationConfig']['responseSchema']);
    }

    public function testMissingCandidateContentThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'candidates' => [],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 0],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Gemini('test-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(model: 'gemini-3-pro', messages: [['role' => 'user', 'content' => 'x']]));
    }

    public function testMalformedStructuredContentThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'not json']]]]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Gemini('test-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(
            model: 'gemini-3-pro',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));
    }

    public function testNon2xxStatusThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(403, [], json_encode(['error' => ['message' => 'bad key']])));
        $adapter = new Gemini('bad-key', $fake);

        $this->expectException(LLMException::class);

        $adapter->complete(new LLMRequest(model: 'gemini-3-pro', messages: [['role' => 'user', 'content' => 'x']]));
    }
}
