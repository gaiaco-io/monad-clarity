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
 * OpenAI Chat Completions API adapter (`POST /v1/chat/completions`).
 *
 * $systemInstruction becomes a leading message with role "system" — OpenAI's message
 * list is the only place it can go, unlike Anthropic's separate top-level field.
 *
 * Structured responses (§11.3.8) use `response_format: {type: "json_schema", ...,
 * strict: true}` — OpenAI's own server-enforced schema-constrained decoding, so the
 * returned content is guaranteed valid JSON matching the schema rather than merely
 * requested.
 *
 * The output cap is sent as `max_completion_tokens`, not `max_tokens`. Every current
 * OpenAI chat model — `chat-latest` included — rejects `max_tokens` outright with
 * `"Unsupported parameter … Use 'max_completion_tokens' instead"`, while the older models
 * that still accept `max_tokens` accept `max_completion_tokens` too. One name therefore
 * reaches every model and the other reaches only the legacy ones, which makes this a
 * straight substitution rather than something the caller should have to configure
 * (`ReleaseNotes_1.8.0.md` §1.4). Measured against a live key, not inferred.
 *
 * @package Monad\Clarity\Services\LLMAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class OpenAI extends LLM
{
    private const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const STRUCTURED_SCHEMA_NAME = 'structured_response';

    public function __construct(
        string $apiKey,
        HttpClient $httpClient,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
        parent::__construct($apiKey, $httpClient);
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $messages = [];

        if ($request->systemInstruction !== null) {
            $messages[] = ['role' => 'system', 'content' => $request->systemInstruction];
        }

        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        $body = [
            'model' => $request->model,
            'messages' => $messages,
            'temperature' => $request->temperature,
            'max_completion_tokens' => $request->maxOutputTokens,
        ];

        if ($request->responseSchema !== null) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => self::STRUCTURED_SCHEMA_NAME,
                    'schema' => $request->responseSchema,
                    'strict' => true,
                ],
            ];
        }

        $response = $this->httpClient->withTimeoutSeconds($request->timeoutSeconds)->postJson(
            $this->endpoint,
            $body,
            ['Authorization' => 'Bearer ' . $this->apiKey]
        );

        $this->assertSuccessful($response);
        $decoded = $this->decodeJsonBody($response);

        if (!isset($decoded['choices'][0]['message']['content']) || !is_string($decoded['choices'][0]['message']['content'])) {
            throw new LLMException('OpenAI response did not include the expected choices[0].message.content.');
        }

        $content = $decoded['choices'][0]['message']['content'];

        return new LLMResponse(
            provider: $this->providerName(),
            model: (string) ($decoded['model'] ?? $request->model),
            content: $request->responseSchema !== null ? $this->decodeStructuredContent($content) : $content,
            usage: [
                'inputTokens' => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
                'outputTokens' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
            ],
            providerRequestId: isset($decoded['id']) ? (string) $decoded['id'] : null,
            raw: $decoded,
        );
    }

    /**
     * @return array<string, mixed>
     * @throws LLMException if $content isn't valid JSON — shouldn't happen given
     *     `strict: true` above, but this is a provider guarantee, not a language one.
     */
    private function decodeStructuredContent(string $content): array
    {
        try {
            $decoded = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LLMException('OpenAI structured response content was not valid JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new LLMException('OpenAI structured response content was not a JSON object.');
        }

        return $decoded;
    }

    protected function providerName(): string
    {
        return 'openai';
    }
}
