<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLMAdapters;

use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\LLM;
use Monad\Clarity\Services\LLM\GeneratedImage;
use Monad\Clarity\Services\LLM\ImageRequest;
use Monad\Clarity\Services\LLM\ImageResponse;
use Monad\Clarity\Services\LLM\LLMException;
use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLM\LLMResponse;
use Monad\Clarity\Services\LLM\ReferenceImage;
use JsonException;

/**
 * Google Gemini Generative Language API adapter
 * (`POST /v1beta/models/{model}:generateContent`).
 *
 * The model is part of the URL path, not the request body, and the API key travels in an
 * `x-goog-api-key` header, the form Google's own API-key documentation uses. It never goes
 * in the URL: until 1.12.2 it was a `?key=` query parameter, so any access log, proxy log or
 * error page that printed a request URI printed the key (`ReleaseNotes_1.8.0.md` §2.7 item 3,
 * closed in 1.12.2). Assistant turns use role "model", not "assistant" — translated here so
 * LLMRequest's own message shape stays provider-neutral.
 *
 * Structured responses (§11.3.8) use `generationConfig.responseMimeType:
 * "application/json"` plus `responseJsonSchema` — Gemini's own server-enforced
 * schema-constrained decoding, the same guarantee OpenAI's json_schema mode makes.
 *
 * `responseJsonSchema`, not `responseSchema` (1.8.2). `responseSchema` takes Google's
 * OpenAPI-style subset and refuses `additionalProperties`, which OpenAI's strict mode
 * requires, so a schema written once for every adapter could not reach Gemini.
 * `responseJsonSchema` takes JSON Schema and, verified live, still takes the OpenAPI-style
 * form, so no schema that worked before is refused now.
 *
 * Image generation (1.9.0) is generateImage(), a public method on this adapter rather than
 * a method on the LLM contract, because no other adapter has it — the rule Checkout applies
 * to subscriptions (`ReleaseNotes_1.9.0.md` §2.1). It speaks Google's Interactions API
 * (`POST /v1beta/interactions`), the path Google now documents for image models, while
 * complete() stays on generateContent (§2.2). Both send the key the same way, in the
 * `x-goog-api-key` header (§2.3; complete() joined it in 1.12.2).
 *
 * @package Monad\Clarity\Services\LLMAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Gemini extends LLM
{
    private const DEFAULT_BASE_URI = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Pins the Interactions response shape this adapter was written against, as Google's own
     * image-generation examples do, so a later revision cannot reshape it underneath us.
     */
    private const INTERACTIONS_API_REVISION = '2026-05-20';

    /**
     * The one output type the Interactions reference lists for images; used only if an image
     * block ever arrives without its own `mime_type`.
     */
    private const INTERACTIONS_DEFAULT_IMAGE_TYPE = 'image/jpeg';

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
                    'parts' => isset($message['images']) ? self::imageParts($message) : [['text' => $message['content']]],
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
            $body['generationConfig']['responseJsonSchema'] = $request->responseSchema;
        }

        $uri = sprintf('%s/models/%s:generateContent', $this->baseUri, rawurlencode($request->model));

        $response = $this->httpClient->withTimeoutSeconds($request->timeoutSeconds)->postJson(
            $uri,
            $body,
            ['x-goog-api-key' => $this->apiKey]
        );

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

    /**
     * Generates an image from a prompt, or edits the request's reference images per the prompt.
     *
     * The request is sent with `store: false`, so Google keeps neither the prompt nor the
     * reference images for later retrieval (§2.6). It sends no `delivery` field: Google
     * refuses one for image models ("Image delivery mode is not supported") and returns the
     * bytes inline without being asked, verified live (§3).
     *
     * @throws LLMException if the request fails, the interaction does not complete, or it
     *     completes without an image — in which case the message quotes what the model said.
     */
    public function generateImage(ImageRequest $request): ImageResponse
    {
        $body = [
            'model' => $request->model,
            'input' => [
                ['type' => 'text', 'text' => $request->prompt],
                ...array_map(
                    static fn (ReferenceImage $image): array => [
                        'type' => 'image',
                        'mime_type' => $image->mimeType,
                        'data' => base64_encode($image->bytes),
                    ],
                    $request->referenceImages
                ),
            ],
            'response_format' => array_filter([
                'type' => 'image',
                'mime_type' => $request->outputMimeType,
                'aspect_ratio' => $request->aspectRatio,
                'image_size' => $request->imageSize,
            ], static fn (?string $value): bool => $value !== null),
            'store' => false,
        ];

        $response = $this->httpClient->withTimeoutSeconds($request->timeoutSeconds)->postJson(
            $this->baseUri . '/interactions',
            $body,
            ['x-goog-api-key' => $this->apiKey, 'Api-Revision' => self::INTERACTIONS_API_REVISION]
        );

        $this->assertSuccessful($response);
        $decoded = $this->decodeJsonBody($response);

        $this->assertInteractionCompleted($decoded);
        [$images, $text] = $this->extractImagesAndText($decoded);

        if ($images === []) {
            throw new LLMException($text === null
                ? 'Gemini completed the interaction but returned no image and no text.'
                : sprintf('Gemini returned no image. The model replied instead: "%s"', $text));
        }

        return new ImageResponse(
            provider: $this->providerName(),
            model: (string) ($decoded['model'] ?? $request->model),
            images: $images,
            text: $text,
            usage: [
                'inputTokens' => (int) ($decoded['usage']['total_input_tokens'] ?? 0),
                'outputTokens' => (int) ($decoded['usage']['total_output_tokens'] ?? 0),
            ],
            providerRequestId: isset($decoded['id']) ? (string) $decoded['id'] : null,
            raw: $decoded,
        );
    }

    /**
     * An Interaction carries no finish reason or safety verdict of its own: a refusal or a
     * platform fault surfaces only as a status other than "completed", with any diagnosis in
     * `errors[].message` — so that is all this names, rather than inventing codes Google
     * does not document (§2.7).
     *
     * @param array<string, mixed> $decoded
     */
    private function assertInteractionCompleted(array $decoded): void
    {
        $status = $decoded['status'] ?? null;

        if ($status === 'completed') {
            return;
        }

        $reasons = array_values(array_filter(array_map(
            static fn (mixed $error): string => is_array($error) ? (string) ($error['message'] ?? '') : '',
            is_array($decoded['errors'] ?? null) ? $decoded['errors'] : []
        )));

        throw new LLMException(sprintf(
            'Gemini image generation did not complete (status "%s"). %s',
            is_string($status) ? $status : 'missing',
            $reasons === [] ? 'Google gave no reason.' : 'Google said: ' . implode(' ', $reasons)
        ));
    }

    /**
     * Walks every model_output step, not only the last, because a model may interleave
     * several images with prose and Google's own convenience accessors keep only the final
     * image (§2.4). Thought steps are skipped.
     *
     * @param array<string, mixed> $decoded
     * @return array{0: list<GeneratedImage>, 1: ?string}
     */
    private function extractImagesAndText(array $decoded): array
    {
        $images = [];
        $text = '';

        foreach (is_array($decoded['steps'] ?? null) ? $decoded['steps'] : [] as $step) {
            if (!is_array($step) || ($step['type'] ?? null) !== 'model_output' || !is_array($step['content'] ?? null)) {
                continue;
            }

            foreach ($step['content'] as $block) {
                match (is_array($block) ? ($block['type'] ?? null) : null) {
                    'text' => $text .= (string) ($block['text'] ?? ''),
                    'image' => $images[] = $this->decodeImageBlock($block),
                    default => null,
                };
            }
        }

        return [$images, $text === '' ? null : $text];
    }

    /**
     * @param array<string, mixed> $block
     */
    private function decodeImageBlock(array $block): GeneratedImage
    {
        if (!isset($block['data'])) {
            throw new LLMException(isset($block['uri'])
                ? 'Gemini returned an image by URI rather than inline, which this adapter does not fetch.'
                : 'Gemini returned an image block with no image data.');
        }

        $bytes = base64_decode((string) $block['data'], strict: true);

        if ($bytes === false || $bytes === '') {
            throw new LLMException('Gemini returned image data that was not valid base64.');
        }

        return new GeneratedImage(
            mimeType: (string) ($block['mime_type'] ?? self::INTERACTIONS_DEFAULT_IMAGE_TYPE),
            bytes: $bytes,
        );
    }

    /**
     * A user message carrying images (1.10.0) becomes generateContent `parts`: each image as
     * `inline_data`, then the text, if any. Google's own guidance puts the text after the image
     * (`ReleaseNotes_1.10.0.md` §2.5), and this shape was verified live before it was written.
     *
     * @param array{role: string, content: string, images: list<ReferenceImage>} $message
     * @return list<array<string, mixed>>
     */
    private static function imageParts(array $message): array
    {
        $parts = array_map(
            static fn (ReferenceImage $image): array => [
                'inline_data' => ['mime_type' => $image->mimeType, 'data' => base64_encode($image->bytes)],
            ],
            $message['images']
        );

        if ($message['content'] !== '') {
            $parts[] = ['text' => $message['content']];
        }

        return $parts;
    }

    protected function providerName(): string
    {
        return 'gemini';
    }
}
