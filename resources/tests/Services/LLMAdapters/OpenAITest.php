<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\LLMAdapters;

use Monad\Clarity\Services\LLM\LLMException;
use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLM\ReferenceImage;
use Monad\Clarity\Services\LLMAdapters\OpenAI;
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
        // Not `max_tokens`: every current OpenAI chat model rejects that name outright, and
        // the older models that accept it accept this one too (ReleaseNotes_1.8.0.md §1.4).
        self::assertSame(512, $body['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $body);
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

    // ------------------------------------------------------------- image input (1.10.0)

    public function testOnlyTheImageBearingMessageChangesShapeWithImagesBeforeText(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());

        (new OpenAI('test-key', $fake))->complete(new LLMRequest(
            model: 'vision-model',
            messages: [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi'],
                [
                    'role' => 'user',
                    'content' => 'Image 1 is BEFORE, image 2 is AFTER. What changed?',
                    'images' => [new ReferenceImage('image/jpeg', 'before-bytes'), new ReferenceImage('image/png', 'after-bytes')],
                ],
            ],
        ));

        self::assertSame([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi'],
            ['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode('before-bytes')]],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('after-bytes')]],
                ['type' => 'text', 'text' => 'Image 1 is BEFORE, image 2 is AFTER. What changed?'],
            ]],
        ], $fake->decodedLastRequestBody()['messages']);
    }

    public function testAnImageMessageWithEmptyContentSendsNoTextPart(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());

        (new OpenAI('test-key', $fake))->complete(new LLMRequest(
            model: 'vision-model',
            messages: [['role' => 'user', 'content' => '', 'images' => [new ReferenceImage('image/jpeg', 'only-bytes')]]],
        ));

        self::assertSame([
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode('only-bytes')]],
        ], $fake->decodedLastRequestBody()['messages'][0]['content']);
    }
}
