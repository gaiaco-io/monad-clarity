<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLM;

/**
 * Provider-agnostic output every LLM adapter's complete() returns (ReleaseNotes
 * §11.3.1,2,9,10). $content is the model's text reply, or the schema-decoded value when
 * the request's $responseSchema was set and the provider honoured it.
 *
 * @package Monad\Clarity\Services\LLM
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class LLMResponse
{
    /**
     * @param string|array<string, mixed> $content
     * @param array{inputTokens: int, outputTokens: int} $usage
     * @param array<string, mixed> $raw The provider's full decoded response body, for
     *     anything this contract doesn't surface — an escape hatch, not a substitute for
     *     the typed fields above.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string|array $content,
        public readonly array $usage,
        public readonly ?string $providerRequestId,
        public readonly array $raw,
    ) {
    }
}
