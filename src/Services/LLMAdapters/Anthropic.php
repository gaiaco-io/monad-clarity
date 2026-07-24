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
 * Anthropic has no server-enforced JSON-schema output mode — structured responses
 * (§11.3.8) are obtained by defining a single synthetic tool whose `input_schema` is the
 * caller's requested schema and forcing `tool_choice` to that tool, then reading the
 * resulting `tool_use` content block's `input` back out. This is Anthropic's own
 * documented pattern for schema-constrained output, not a Clarity invention.
 *
 * $systemInstruction is a top-level `system` field, never a message — Anthropic rejects
 * a message with role "system" outright, unlike OpenAI/DeepSeek/Gemini.
 *
 * @package Monad\Clarity\Services\LLMAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Anthropic extends LLM
{
    private const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const STRUCTURED_TOOL_NAME = 'structured_response';

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
            'temperature' => $request->temperature,
        ];

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

            throw new LLMException('Anthropic response did not include the expected structured tool_use block.');
        }

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }

    protected function providerName(): string
    {
        return 'anthropic';
    }
}
