<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLMAdapters;

use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\LLM;
use Monad\Clarity\Services\LLM\LLMException;
use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLM\LLMResponse;
use JsonException;

/**
 * Anthropic Messages API adapter (`POST /v1/messages`).
 *
 * Anthropic had no server-enforced JSON-schema output mode when this was written, so
 * structured responses (§11.3.8) are obtained by defining a single synthetic tool whose
 * `input_schema` is the caller's requested schema and forcing `tool_choice` to that tool,
 * then reading the resulting `tool_use` content block's `input` back out. This is
 * Anthropic's own documented pattern for schema-constrained output, not a Clarity
 * invention.
 *
 * $systemInstruction is a top-level `system` field, never a message — Anthropic rejects
 * a message with role "system" outright, unlike OpenAI/DeepSeek/Gemini.
 *
 * `temperature` is sent only when the caller moved it off `LLMRequest`'s default of 1.0,
 * which is also Anthropic's own default — so omitting it changes nothing about the reply.
 * Anthropic's newer models reject a non-default `temperature` outright, and a request
 * that never mentions the parameter is accepted everywhere. A caller who does set one is
 * asking for it by name, and gets whatever the model they named makes of it.
 *
 * Structured responses have two wire mechanisms, chosen with `$structuredOutput` and
 * defaulting to the forced tool every caller has had since 1.0.0. Neither reaches every
 * model: the newest ones refuse forced `tool_choice`, and several older ones have no
 * native mode. A model id does not say which it is, and only Anthropic knows — so this is
 * the caller's choice to make once, per adapter, not something guessed per request
 * (`ReleaseNotes_1.8.0.md` §2.1).
 *
 * @package Monad\Clarity\Services\LLMAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Anthropic extends LLM
{
    private const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const STRUCTURED_TOOL_NAME = 'structured_response';

    /**
     * Anthropic's own default, and `LLMRequest`'s. Sending it explicitly and omitting it
     * are the same request to the model — but only omitting it is accepted everywhere.
     */
    private const DEFAULT_TEMPERATURE = 1.0;

    /**
     * $structuredOutput is last because `$endpoint` shipped in 1.0.0, and reordering a
     * positional caller's arguments to make room would break them — 1.7.1's rule for
     * `forCatalogPrice()`, applied again.
     */
    public function __construct(
        string $apiKey,
        HttpClient $httpClient,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
        private readonly AnthropicStructuredOutput $structuredOutput = AnthropicStructuredOutput::ForcedTool,
    ) {
        parent::__construct($apiKey, $httpClient);
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $body = [
            'model' => $request->model,
            'messages' => array_map(
                static fn (array $message): array => ['role' => $message['role'], 'content' => $message['content']],
                $request->messages
            ),
            'max_tokens' => $request->maxOutputTokens,
        ];

        if ($request->temperature !== self::DEFAULT_TEMPERATURE) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->systemInstruction !== null) {
            $body['system'] = $request->systemInstruction;
        }

        if ($request->responseSchema !== null) {
            $body += match ($this->structuredOutput) {
                AnthropicStructuredOutput::ForcedTool => [
                    'tools' => [[
                        'name' => self::STRUCTURED_TOOL_NAME,
                        'input_schema' => $request->responseSchema,
                    ]],
                    'tool_choice' => ['type' => 'tool', 'name' => self::STRUCTURED_TOOL_NAME],
                ],
                AnthropicStructuredOutput::NativeSchema => [
                    'output_config' => [
                        'format' => ['type' => 'json_schema', 'schema' => $request->responseSchema],
                    ],
                ],
            };
        }

        $response = $this->httpClient->withTimeoutSeconds($request->timeoutSeconds)->postJson(
            $this->endpoint,
            $body,
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ]
        );

        $this->assertSuccessful($response);
        $decoded = $this->decodeJsonBody($response);

        return new LLMResponse(
            provider: $this->providerName(),
            model: (string) ($decoded['model'] ?? $request->model),
            content: $this->extractContent($decoded, $request),
            usage: [
                'inputTokens' => (int) ($decoded['usage']['input_tokens'] ?? 0),
                'outputTokens' => (int) ($decoded['usage']['output_tokens'] ?? 0),
            ],
            providerRequestId: isset($decoded['id']) ? (string) $decoded['id'] : null,
            raw: $decoded,
        );
    }

    /**
     * @param array<string, mixed> $decoded
     * @return string|array<string, mixed>
     */
    private function extractContent(array $decoded, LLMRequest $request): string|array
    {
        $blocks = $decoded['content'] ?? [];

        if (!is_array($blocks)) {
            throw new LLMException('Anthropic response "content" was not an array of content blocks.');
        }

        if ($request->responseSchema === null) {
            return self::joinTextBlocks($blocks, $decoded);
        }

        return match ($this->structuredOutput) {
            AnthropicStructuredOutput::ForcedTool => self::readForcedToolInput($blocks, $decoded),
            AnthropicStructuredOutput::NativeSchema => self::decodeNativeSchemaJson(
                self::joinTextBlocks($blocks, $decoded),
                $decoded
            ),
        };
    }

    /**
     * Every `text` block, in order. Shared by the plain-text path and by native structured
     * mode, whose answer arrives as ordinary JSON text rather than in a block of its own.
     *
     * @param array<array-key, mixed> $blocks
     * @param array<string, mixed> $decoded
     */
    private static function joinTextBlocks(array $blocks, array $decoded): string
    {
        $text = '';
        $sawTextBlock = false;

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $sawTextBlock = true;
                $text .= (string) ($block['text'] ?? '');
            }
        }

        if (!$sawTextBlock) {
            throw new LLMException(sprintf(
                'Anthropic returned no text content block (stop_reason: %s). A reply truncated or '
                . 'declined before any text existed is a failure, not an empty answer — raise '
                . 'maxOutputTokens if the model spent them all before speaking.',
                self::stopReason($decoded)
            ));
        }

        return $text;
    }

    /**
     * @param array<array-key, mixed> $blocks
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private static function readForcedToolInput(array $blocks, array $decoded): array
    {
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::STRUCTURED_TOOL_NAME) {
                return is_array($block['input'] ?? null) ? $block['input'] : [];
            }
        }

        throw new LLMException(sprintf(
            'Anthropic returned no structured tool_use block (stop_reason: %s). The model was '
            . 'given no choice but to call the tool, so a reply without it was cut short or declined.',
            self::stopReason($decoded)
        ));
    }

    /**
     * Native mode's schema constraint is Anthropic's to enforce, not ours — but "may not match
     * your schema" on a refusal and "may be incomplete" on max_tokens are both documented, and
     * both arrive here as text that will not parse. So the stop_reason is named, exactly as the
     * other two failures name it: it is the difference between a declined answer and a
     * truncated one, and json_decode cannot tell them apart.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private static function decodeNativeSchemaJson(string $text, array $decoded): array
    {
        try {
            $content = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LLMException(
                sprintf(
                    'Anthropic structured response content was not valid JSON (stop_reason: %s): %s',
                    self::stopReason($decoded),
                    $e->getMessage()
                ),
                previous: $e
            );
        }

        if (!is_array($content)) {
            throw new LLMException(sprintf(
                'Anthropic structured response content was not a JSON object (stop_reason: %s).',
                self::stopReason($decoded)
            ));
        }

        return $content;
    }

    /**
     * Why a response arrived without the content it should have carried. Named in both
     * failures above because it is the one field that distinguishes a truncated reply from
     * a declined one, and the caller cannot read it off an exception that doesn't say it.
     *
     * @param array<string, mixed> $decoded
     */
    private static function stopReason(array $decoded): string
    {
        $reason = $decoded['stop_reason'] ?? null;

        return is_string($reason) ? $reason : 'none reported';
    }

    protected function providerName(): string
    {
        return 'anthropic';
    }
}
