<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLM;

use InvalidArgumentException;

/**
 * Provider-agnostic input every LLM adapter's complete() accepts (ReleaseNotes
 * §11.3.1-8). Immutable and validated at construction, so a malformed request fails
 * before any adapter spends a network round trip on it.
 *
 * @package Monad\Clarity\Services\LLM
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class LLMRequest
{
    /**
     * @param list<array{role: string, content: string, images?: list<ReferenceImage>}> $messages
     *     Conversation history, oldest first, each role either 'user' or 'assistant'. The
     *     system instruction is a separate field, not a message with role 'system' — providers
     *     disagree on whether/how a system message belongs in the message list (Anthropic
     *     rejects one outright), so every adapter places $systemInstruction correctly regardless.
     *
     *     A user message may also carry `images` (1.10.0): a non-empty list of ReferenceImage
     *     for the model to look at, sent ahead of that message's text. `content` stays a string
     *     whatever else a message carries, so code that reads it is never handed an array
     *     (`ReleaseNotes_1.10.0.md` §2.1). Images are user-only because no provider accepts them
     *     in an assistant turn; how many, and how large, is the provider's to refuse (§2.6).
     * @param ?array<string, mixed> $responseSchema A JSON Schema the model's output must
     *     conform to. Null means a plain-text response — "structured JSON response"
     *     (§11.3.8) is this field's presence, not a separate boolean: a schema you
     *     configured but didn't ask to be enforced isn't a real state worth representing.
     */
    public function __construct(
        public readonly string $model,
        public readonly array $messages,
        public readonly ?string $systemInstruction = null,
        public readonly float $temperature = 1.0,
        public readonly int $maxOutputTokens = 1024,
        public readonly int $timeoutSeconds = 30,
        public readonly ?array $responseSchema = null,
    ) {
        if ($model === '') {
            throw new InvalidArgumentException('LLMRequest requires a non-empty $model.');
        }

        if ($messages === []) {
            throw new InvalidArgumentException('LLMRequest requires at least one message.');
        }

        foreach ($messages as $index => $message) {
            if (
                !is_array($message)
                || !isset($message['role'], $message['content'])
                || !in_array($message['role'], ['user', 'assistant'], true)
                || !is_string($message['content'])
            ) {
                throw new InvalidArgumentException(
                    'Each message must be an array with a "role" of "user" or "assistant" and a string "content".'
                );
            }

            if (array_key_exists('images', $message)) {
                self::assertValidImages($message, $index);
            }
        }

        if ($temperature < 0.0 || $temperature > 2.0) {
            throw new InvalidArgumentException('LLMRequest temperature must be between 0.0 and 2.0.');
        }

        if ($maxOutputTokens < 1) {
            throw new InvalidArgumentException('LLMRequest maxOutputTokens must be at least 1.');
        }

        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('LLMRequest timeoutSeconds must be at least 1.');
        }
    }

    /**
     * Guarantees adapters can rely on: when `images` is present it is a non-empty list of
     * ReferenceImage on a user message, so `isset($message['images'])` is the whole test.
     *
     * @param array<string, mixed> $message
     */
    private static function assertValidImages(array $message, int|string $index): void
    {
        $images = $message['images'];

        if ($message['role'] !== 'user') {
            throw new InvalidArgumentException(sprintf(
                'Message %s is an assistant turn carrying images; only user messages may carry images, because no provider accepts them from the assistant.',
                $index
            ));
        }

        if (!is_array($images) || $images === [] || !array_is_list($images)) {
            throw new InvalidArgumentException(sprintf(
                'Message %s "images" must be a non-empty list of ReferenceImage; leave the key out for a text-only message.',
                $index
            ));
        }

        foreach ($images as $image) {
            if (!$image instanceof ReferenceImage) {
                throw new InvalidArgumentException(sprintf(
                    'Message %s "images" must contain only ReferenceImage instances; found %s.',
                    $index,
                    get_debug_type($image)
                ));
            }
        }
    }
}
