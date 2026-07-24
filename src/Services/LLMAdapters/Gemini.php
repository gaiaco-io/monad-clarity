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
 * Google Gemini Generative Language API adapter
 * (`POST /v1beta/models/{model}:generateContent`).
 *
 * Two real differences from the other three adapters: the model is part of the URL path,
 * not the request body, and the API key is a query parameter (`?key=...`) rather than an
 * Authorization/x-api-key header — both are Gemini's own documented auth convention, not
 * a Clarity choice. Assistant turns use role "model", not "assistant" — translated here
 * so LLMRequest's own message shape stays provider-neutral.
 *
 * Structured responses (§11.3.8) use `generationConfig.responseMimeType:
 * "application/json"` plus `responseSchema` — Gemini's own server-enforced
 * schema-constrained decoding, the same guarantee OpenAI's json_schema mode makes.
 *
 * @package Monad\Clarity\Services\LLMAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Gemini extends LLM
{
    private const DEFAULT_BASE_URI = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        string $apiKey,
        HttpClient $httpClient,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        parent::__construct($apiKey, $httpClient);
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $body = [
            'contents' => array_map(
                static fn (array $message): array => [
                    'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $message['content']]],
                ],
                $request->messages
            ),
            'generationConfig' => [
                'temperature' => $request->temperature,
                'maxOutputTokens' => $request->maxOutputTokens,
            ],
        ];

        if ($request->systemInstruction !== null) {
            $body['systemInstruction'] = ['parts' => [['text' => $request->systemInstruction]]];
        }

        if ($request->responseSchema !== null) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema'] = $request->responseSchema;
        }

        $uri = sprintf(
            '%s/models/%s:generateContent?key=%s',
            $this->baseUri,
            rawurlencode($request->model),
            rawurlencode($this->apiKey)
        );

        $response = $this->httpClient->withTimeoutSeconds($request->timeoutSeconds)->postJson($uri, $body);

        $this->assertSuccessful($response);
        $decoded = $this->decodeJsonBody($response);

        return new LLMResponse(
            provider: $this->providerName(),
            model: (string) ($decoded['modelVersion'] ?? $request->model),
            content: $this->extractContent($decoded, $request),
            usage: [
                'inputTokens' => (int) ($decoded['usageMetadata']['promptTokenCount'] ?? 0),
                'outputTokens' => (int) ($decoded['usageMetadata']['candidatesTokenCount'] ?? 0),
            ],
            providerRequestId: isset($decoded['responseId']) ? (string) $decoded['responseId'] : null,
            raw: $decoded,
        );
    }

    /**
     * @param array<string, mixed> $decoded
     * @return string|array<string, mixed>
     */
    private function extractContent(array $decoded, LLMRequest $request): string|array
    {
        $parts = $decoded['candidates'][0]['content']['parts'] ?? null;

        if (!is_array($parts)) {
            throw new LLMException('Gemini response did not include any candidate content parts.');
        }

        $text = implode('', array_map(
            static fn (array $part): string => (string) ($part['text'] ?? ''),
            $parts
        ));

        if ($request->responseSchema === null) {
            return $text;
        }

        try {
            $decodedContent = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LLMException('Gemini structured response content was not valid JSON: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decodedContent)) {
            throw new LLMException('Gemini structured response content was not a JSON object.');
        }

        return $decodedContent;
    }

    protected function providerName(): string
    {
        return 'gemini';
    }
}
