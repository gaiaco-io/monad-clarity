<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\LLMAdapters;

use Monad\Clarity\Services\LLM\GeneratedImage;
use Monad\Clarity\Services\LLM\ImageRequest;
use Monad\Clarity\Services\LLM\LLMException;
use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLM\ReferenceImage;
use Monad\Clarity\Services\LLMAdapters\Gemini;
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

    // ------------------------------------------------------------- generateImage (1.9.0)

    /**
     * @param list<array<string, mixed>> $steps
     * @param array<string, mixed> $extra
     */
    private static function interaction(array $steps, string $status = 'completed', array $extra = []): Response
    {
        return new Response(200, [], json_encode(array_merge([
            'id' => 'v1_interaction123',
            'object' => 'interaction',
            'model' => 'gemini-nano-banana-2.1',
            'status' => $status,
            'steps' => $steps,
            'usage' => ['total_input_tokens' => 11, 'total_output_tokens' => 1290, 'total_tokens' => 1301],
        ], $extra), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function imageBlock(string $bytes, ?string $mimeType = 'image/jpeg'): array
    {
        return array_filter(['type' => 'image', 'data' => base64_encode($bytes), 'mime_type' => $mimeType], static fn ($v) => $v !== null);
    }

    public function testGenerateImageSendsAnInteractionWithTheKeyInAHeaderNotTheUrl(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [self::imageBlock('jpeg-bytes')]],
        ]));
        $adapter = new Gemini('test-key', $fake);

        $adapter->generateImage(new ImageRequest(model: 'gemini-nano-banana-2.1', prompt: 'A lighthouse'));

        $request = $fake->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/interactions', (string) $request->getUri());
        self::assertStringNotContainsString('test-key', (string) $request->getUri());
        self::assertSame('test-key', $request->getHeaderLine('x-goog-api-key'));
        self::assertSame('2026-05-20', $request->getHeaderLine('Api-Revision'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testATextOnlyRequestSendsThePromptAndOmitsEveryUnsetFormatField(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [self::imageBlock('jpeg-bytes')]],
        ]));
        $adapter = new Gemini('test-key', $fake);

        $adapter->generateImage(new ImageRequest(model: 'gemini-nano-banana-2.1', prompt: 'A lighthouse'));

        self::assertSame([
            'model' => 'gemini-nano-banana-2.1',
            'input' => [['type' => 'text', 'text' => 'A lighthouse']],
            'response_format' => ['type' => 'image', 'delivery' => 'inline'],
            'store' => false,
        ], $fake->decodedLastRequestBody());
    }

    public function testAnEditSendsEachReferenceImageBase64EncodedAfterThePromptWithEveryFormatField(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [self::imageBlock('jpeg-bytes')]],
        ]));
        $adapter = new Gemini('test-key', $fake);

        $adapter->generateImage(new ImageRequest(
            model: 'gemini-nano-banana-2.1',
            prompt: 'Put the cat on the sofa',
            referenceImages: [new ReferenceImage('image/png', 'cat-bytes'), new ReferenceImage('image/jpeg', 'sofa-bytes')],
            aspectRatio: '16:9',
            imageSize: '2K',
            outputMimeType: 'image/jpeg',
        ));

        $body = $fake->decodedLastRequestBody();
        self::assertSame([
            ['type' => 'text', 'text' => 'Put the cat on the sofa'],
            ['type' => 'image', 'mime_type' => 'image/png', 'data' => base64_encode('cat-bytes')],
            ['type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode('sofa-bytes')],
        ], $body['input']);
        self::assertSame(
            ['type' => 'image', 'mime_type' => 'image/jpeg', 'aspect_ratio' => '16:9', 'image_size' => '2K', 'delivery' => 'inline'],
            $body['response_format']
        );
    }

    public function testEveryImageIsReturnedDecodedInOrderWithTextJoinedAndThoughtsSkipped(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'thought', 'signature' => 'sig', 'summary' => [['type' => 'text', 'text' => 'planning…']]],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => 'Here is the first. '],
                self::imageBlock('first-bytes', 'image/png'),
            ]],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => 'And the second.'],
                self::imageBlock('second-bytes'),
            ]],
        ]));
        $adapter = new Gemini('test-key', $fake);

        $response = $adapter->generateImage(new ImageRequest(model: 'gemini-nano-banana-2.1', prompt: 'Two lighthouses'));

        self::assertEquals(
            [new GeneratedImage('image/png', 'first-bytes'), new GeneratedImage('image/jpeg', 'second-bytes')],
            $response->images
        );
        self::assertSame('Here is the first. And the second.', $response->text);
        self::assertSame('gemini', $response->provider);
        self::assertSame('gemini-nano-banana-2.1', $response->model);
        self::assertSame(['inputTokens' => 11, 'outputTokens' => 1290], $response->usage);
        self::assertSame('v1_interaction123', $response->providerRequestId);
        self::assertSame('completed', $response->raw['status']);
    }

    public function testAnImageOnlyReplyHasNullText(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [self::imageBlock('jpeg-bytes')]],
        ]));

        $response = (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));

        self::assertNull($response->text);
    }

    public function testAnImageBlockWithoutAMimeTypeTakesTheInteractionsDefault(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [self::imageBlock('bytes', null)]],
        ]));

        $response = (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));

        self::assertSame('image/jpeg', $response->images[0]->mimeType);
    }

    public function testACompletedInteractionWithNoImageQuotesWhatTheModelSaidInstead(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'I can\'t draw that.']]],
        ]));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('Gemini returned no image. The model replied instead: "I can\'t draw that."');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));
    }

    public function testACompletedInteractionWithNothingAtAllSaysSo(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([]));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('returned no image and no text');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));
    }

    public function testAnInteractionThatDidNotCompleteNamesItsStatusAndGooglesReasons(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([], 'failed', ['errors' => [
            ['code' => 'https://example.test/errors/safety', 'message' => 'The request was blocked.'],
            ['code' => 'https://example.test/errors/other', 'message' => 'Try a different prompt.'],
        ]]));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('Gemini image generation did not complete (status "failed"). Google said: The request was blocked. Try a different prompt.');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));
    }

    public function testAnIncompleteInteractionWithNoErrorsSaysGoogleGaveNoReason(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction(
            [['type' => 'model_output', 'content' => [self::imageBlock('partial')]]],
            'incomplete'
        ));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('(status "incomplete"). Google gave no reason.');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));
    }

    public function testAMissingStatusIsTreatedAsNotCompleted(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode(['steps' => []], JSON_THROW_ON_ERROR)));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('(status "missing")');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));
    }

    public function testImageDataThatIsNotBase64IsRefusedRatherThanDropped(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [['type' => 'image', 'data' => '***not base64***', 'mime_type' => 'image/jpeg']]],
        ]));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('not valid base64');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));
    }

    public function testAnImageDeliveredByUriDespiteTheInlineRequestIsRefused(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [['type' => 'image', 'uri' => 'https://example.test/img.jpg', 'mime_type' => 'image/jpeg']]],
        ]));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('by URI rather than inline');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));
    }

    public function testAnImageBlockWithNoDataAtAllIsRefused(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [['type' => 'image', 'mime_type' => 'image/jpeg']]],
        ]));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('image block with no image data');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));
    }

    public function testGenerateImageNon2xxStatusThrowsWithGooglesBody(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(400, [], '{"error":{"message":"Invalid aspect_ratio"}}'));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('gemini request failed with HTTP 400');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p', aspectRatio: '7:3'));
    }

    public function testGenerateImageNonJsonBodyThrows(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], '<html>gateway</html>'));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('not valid JSON');

        (new Gemini('test-key', $fake))->generateImage(new ImageRequest(model: 'm', prompt: 'p'));
    }

    public function testGenerateImageHonoursACustomBaseUri(): void
    {
        $fake = new FakeHttpClient(static fn () => self::interaction([
            ['type' => 'model_output', 'content' => [self::imageBlock('jpeg-bytes')]],
        ]));
        $adapter = new Gemini('test-key', $fake, baseUri: 'https://proxy.test/v1beta');

        $adapter->generateImage(new ImageRequest(model: 'm', prompt: 'p'));

        self::assertSame('https://proxy.test/v1beta/interactions', (string) $fake->lastRequest()->getUri());
    }
}
