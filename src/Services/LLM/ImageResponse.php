<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLM;

/**
 * Provider-agnostic output of image generation (`ReleaseNotes_1.9.0.md` §1.3). An adapter
 * returns one only when at least one image came back; a reply carrying no image is an
 * LLMException that quotes whatever the model said instead, never an empty $images.
 *
 * @package Monad\Clarity\Services\LLM
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class ImageResponse
{
    /**
     * @param list<GeneratedImage> $images Every image the model returned, in the order it
     *     returned them — not only the last.
     * @param ?string $text Any prose the model interleaved with its images, joined; null
     *     when there was none.
     * @param array{inputTokens: int, outputTokens: int} $usage The same shape LLMResponse
     *     carries.
     * @param array<string, mixed> $raw The provider's full decoded response, base64 image
     *     data included, for anything this contract doesn't surface.
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $images,
        public ?string $text,
        public array $usage,
        public ?string $providerRequestId,
        public array $raw,
    ) {
    }
}
