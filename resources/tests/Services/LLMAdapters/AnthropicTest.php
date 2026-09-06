<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\LLMAdapters;

use Monad\Clarity\Services\LLM\LLMException;
use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLMAdapters\Anthropic;
use Monad\Clarity\Services\LLMAdapters\AnthropicStructuredOutput;
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

    /**
     * The other half — that a temperature the caller actually chose is still sent — is
     * asserted by testCompleteSendsCorrectRequestAndParsesTextResponse, which passes 0.5.
     */
    public function testTheDefaultTemperatureIsOmittedFromTheRequest(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());
        $adapter = new Anthropic('test-key', $fake);

        $adapter->complete(new LLMRequest(model: 'claude-sonnet-5', messages: [['role' => 'user', 'content' => 'Hi']]));

        self::assertArrayNotHasKey('temperature', $fake->decodedLastRequestBody());
    }

    public function testEveryTextBlockIsConcatenatedRatherThanOnlyTheFirst(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_04',
            'model' => 'claude-sonnet-5',
            'content' => [
                ['type' => 'text', 'text' => 'first half, '],
                ['type' => 'text', 'text' => 'second half'],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Anthropic('test-key', $fake);

        $response = $adapter->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
        ));

        self::assertSame('first half, second half', $response->content);
    }

    /**
     * A reply whose tokens were spent before any text existed — the shape a thinking-enabled
     * model produces when maxOutputTokens is too low. Reporting that as '' would hand the
     * caller an empty answer for a request that failed.
     */
    public function testAResponseCarryingNoTextBlockThrowsAndNamesTheStopReason(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_05',
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'thinking', 'thinking' => 'still working on it']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1024],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Anthropic('test-key', $fake);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessageMatches('/no text content block \(stop_reason: max_tokens\)/');

        $adapter->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
        ));
    }

    /**
     * A thinking block alongside real text is not a failure — the text is the answer.
     */
    public function testANonTextBlockAlongsideTextIsSkipped(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_06',
            'model' => 'claude-sonnet-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'reasoning'],
                ['type' => 'text', 'text' => 'the answer'],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Anthropic('test-key', $fake);

        $response = $adapter->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
        ));

        self::assertSame('the answer', $response->content);
    }

    public function testAMissingStructuredToolBlockAlsoNamesTheStopReason(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_07',
            'stop_reason' => 'refusal',
            'content' => [],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Anthropic('test-key', $fake);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessageMatches('/no structured tool_use block \(stop_reason: refusal\)/');

        $adapter->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));
    }

    public function testAnAbsentStopReasonIsReportedHonestly(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_08',
            'content' => [],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Anthropic('test-key', $fake);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessageMatches('/stop_reason: none reported/');

        $adapter->complete(new LLMRequest(model: 'claude-sonnet-5', messages: [['role' => 'user', 'content' => 'x']]));
    }

    // ---- Structured output: the two wire mechanisms (1.8.0) -------------------------

    private static function nativeAdapter(FakeHttpClient $fake): Anthropic
    {
        return new Anthropic('test-key', $fake, structuredOutput: AnthropicStructuredOutput::NativeSchema);
    }

    private static function jsonTextResponse(string $text, ?string $stopReason = null): Response
    {
        return new Response(200, [], json_encode(array_filter([
            'id' => 'msg_native',
            'model' => 'claude-sonnet-5',
            'stop_reason' => $stopReason,
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 4, 'output_tokens' => 6],
        ], static fn ($value) => $value !== null), JSON_THROW_ON_ERROR));
    }

    /**
     * The default is the mechanism every caller has had since 1.0.0, and the two must stay
     * exclusive — a request carrying both would ask Anthropic for the same thing twice.
     */
    public function testTheDefaultModeForcesAToolAndSendsNoOutputConfig(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_09',
            'content' => [['type' => 'tool_use', 'name' => 'structured_response', 'input' => ['ok' => true]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $adapter = new Anthropic('test-key', $fake);

        $adapter->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));

        $body = $fake->decodedLastRequestBody();
        self::assertArrayHasKey('tool_choice', $body);
        self::assertArrayNotHasKey('output_config', $body);
    }

    public function testNativeSchemaModeSendsOutputConfigAndNoTool(): void
    {
        $fake = new FakeHttpClient(static fn () => self::jsonTextResponse('{"answer":42}'));
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'integer']]];

        self::nativeAdapter($fake)->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: $schema,
        ));

        $body = $fake->decodedLastRequestBody();
        self::assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema]], $body['output_config']);
        self::assertArrayNotHasKey('tools', $body);
        self::assertArrayNotHasKey('tool_choice', $body);
    }

    public function testNativeSchemaModeDecodesJsonOutOfTheTextBlock(): void
    {
        $fake = new FakeHttpClient(static fn () => self::jsonTextResponse('{"answer":42}'));

        $response = self::nativeAdapter($fake)->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));

        self::assertSame(['answer' => 42], $response->content);
    }

    /**
     * The JSON is ordinary text, so it splits across blocks like any other reply — decoding
     * only the first would fail on a perfectly good answer.
     */
    public function testNativeSchemaModeJoinsTextBlocksBeforeDecoding(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_10',
            'content' => [
                ['type' => 'text', 'text' => '{"answer":'],
                ['type' => 'text', 'text' => '42}'],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));

        $response = self::nativeAdapter($fake)->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));

        self::assertSame(['answer' => 42], $response->content);
    }

    public function testNativeSchemaModeNamesTheStopReasonWhenTheJsonIsTruncated(): void
    {
        $fake = new FakeHttpClient(static fn () => self::jsonTextResponse('{"answer":4', 'max_tokens'));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessageMatches('/not valid JSON \(stop_reason: max_tokens\)/');

        self::nativeAdapter($fake)->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));
    }

    public function testNativeSchemaModeRejectsAScalarJsonBody(): void
    {
        $fake = new FakeHttpClient(static fn () => self::jsonTextResponse('42', 'refusal'));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessageMatches('/not a JSON object \(stop_reason: refusal\)/');

        self::nativeAdapter($fake)->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));
    }

    public function testNativeSchemaModeStillRaisesWhenNoTextBlockExists(): void
    {
        $fake = new FakeHttpClient(static fn () => new Response(200, [], json_encode([
            'id' => 'msg_11',
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'thinking', 'thinking' => 'still going']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1024],
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(LLMException::class);
        $this->expectExceptionMessageMatches('/no text content block \(stop_reason: max_tokens\)/');

        self::nativeAdapter($fake)->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'x']],
            responseSchema: ['type' => 'object'],
        ));
    }

    /**
     * The mode says how a schema is expressed. With no schema to express it must do nothing —
     * the easy one to get wrong, and the one that would silently change every plain-text call
     * made through a native-mode adapter.
     */
    public function testTheModeIsInertWhenNoResponseSchemaIsGiven(): void
    {
        $fake = new FakeHttpClient(static fn () => self::textResponse());

        $response = self::nativeAdapter($fake)->complete(new LLMRequest(
            model: 'claude-sonnet-5',
            messages: [['role' => 'user', 'content' => 'Hello']],
        ));

        self::assertSame('Hello back!', $response->content);

        $body = $fake->decodedLastRequestBody();
        self::assertArrayNotHasKey('output_config', $body);
        self::assertArrayNotHasKey('tools', $body);
        self::assertArrayNotHasKey('tool_choice', $body);
    }

}
