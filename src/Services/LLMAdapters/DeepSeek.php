<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services\LLMAdapters;

use Gaia\Clarity\Services\HttpClient;
use Gaia\Clarity\Services\LLM;
use Gaia\Clarity\Services\LLM\LLMException;
use Gaia\Clarity\Services\LLM\LLMRequest;
use Gaia\Clarity\Services\LLM\LLMResponse;
use JsonException;

/**
 * DeepSeek chat completions API adapter (`POST /chat/completions`) — DeepSeek's API
 * deliberately mirrors OpenAI's Chat Completions wire format at its own base URL, so this
 * adapter's request/response shape closely follows `LLMAdapters\OpenAI`'s.
 *
 * One real difference: DeepSeek's `response_format` only offers `{"type": "json_object"}`
 * ("JSON mode" — valid JSON guaranteed, but no server-enforced schema), not OpenAI's
 * schema-constrained `json_schema` mode. So structured responses (§11.3.8) here are
 * best-effort: the schema is both set as `response_format: json_object` *and* appended to
 * the system instruction as an explicit instruction, since DeepSeek (like OpenAI's own
 * older JSON mode) only reliably produces JSON when a message actually asks for it.
 *
 * @package Gaia\Clarity\Services\LLMAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class DeepSeek extends LLM
{
    private const DEFAULT_ENDPOINT = 'https://api.deepseek.com/chat/completions';

    public function __construct(
        string $apiKey,
        HttpClient $httpClient,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
        parent::__construct($apiKey, $httpClient);
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $systemInstruction = $request->systemInstruction;

        if ($request->responseSchema !== null) {
            $systemInstruction = trim(
                ($systemInstruction !== null ? $systemInstruction . "\n\n" : '')
                . 'Respond only with valid JSON matching this schema: '
                . json_encode($request->responseSchema, JSON_THROW_ON_ERROR)
            );
        }

        $messages = [];

        if ($systemInstruction !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }

        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        $body = [
            'model' => $request->model,
            'messages' => $messages,
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxOutputTokens,
        ];

        if ($request->responseSchema !== null) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->httpClient->withTimeoutSeconds($request->timeoutSeconds)->postJson(
            $this->endpoint,
            $body,
            ['Authorization' => 'Bearer ' . $this->apiKey]
        );

        $this->assertSuccessful($response);
        $decoded = $this->decodeJsonBody($response);

        if (!isset($decoded['choices'][0]['message']['content']) || !is_string($decoded['choices'][0]['message']['content'])) {
            throw new LLMException('DeepSeek response did not include the expected choices[0].message.content.');
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
     * @throws LLMException if $content isn't valid JSON — a real possibility here, unlike
     *     OpenAI's strict mode, since DeepSeek's json_object mode doesn't enforce the
     *     schema server-side.
     */
    private function decodeStructuredContent(string $content): array
    {
        try {
            $decoded = json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LLMException('DeepSeek structured response content was not valid JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new LLMException('DeepSeek structured response content was not a JSON object.');
        }

        return $decoded;
    }

    protected function providerName(): string
    {
        return 'deepseek';
    }
}
