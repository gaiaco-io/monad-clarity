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
     * @param list<array{role: string, content: string}> $messages Conversation history,
     *     oldest first, each role either 'user' or 'assistant'. The system instruction is
     *     a separate field, not a message with role 'system' — providers disagree on
     *     whether/how a system message belongs in the message list (Anthropic rejects one
     *     outright), so every adapter places $systemInstruction correctly regardless.
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

        foreach ($messages as $message) {
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
}
