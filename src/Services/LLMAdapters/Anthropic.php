<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLMAdapters;

use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\LLM;
use Monad\Clarity\Services\LLM\LLMException;
use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLM\LLMResponse;

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
 * Known limitation, not fixable here: the forced-tool structured-output pattern below is
 * rejected by a small number of the newest Anthropic models, which refuse `tool_choice`
 * of type "tool" or "any". Those models offer a native JSON-schema response mode instead,
 * but it does not reach the models this pattern still serves, and it constrains the
 * schema the caller may write. Choosing between them is a release decision, not something
 * this adapter can decide per request from a model string.
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

    public function __construct(
        string $apiKey,
        HttpClient $httpClient,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
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
            $body['tools'] = [[
                'name' => self::STRUCTURED_TOOL_NAME,
                'input_schema' => $request->responseSchema,
            ]];
            $body['tool_choice'] = ['type' => 'tool', 'name' => self::STRUCTURED_TOOL_NAME];
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

        if ($request->responseSchema !== null) {
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
