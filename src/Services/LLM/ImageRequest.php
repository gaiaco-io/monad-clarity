<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLM;

use InvalidArgumentException;

/**
 * Provider-agnostic input for image generation and image editing (`ReleaseNotes_1.9.0.md`
 * §1.2). Immutable and validated at construction, like LLMRequest, so a malformed request
 * fails before an adapter spends a slow, billed round trip on it.
 *
 * What is validated is shape, never a provider's list. Aspect ratios, sizes and output
 * types differ by model and grow with each one Google ships (Nano Banana 2.1 added 1:8 and
 * 8:1), so a list kept here would refuse what the provider accepts the day it falls behind.
 * The provider's own 400 is the authority — the rule `ReleaseNotes_1.8.0.md` §2.4 set for
 * schemas, applied again (`ReleaseNotes_1.9.0.md` §2.5).
 *
 * @package Monad\Clarity\Services\LLM
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class ImageRequest
{
    /**
     * @param string $prompt What to draw, or what to change in the reference images.
     * @param list<ReferenceImage> $referenceImages Images to edit or draw from; empty for
     *     pure text-to-image. How many a model accepts is the provider's to enforce.
     * @param ?string $aspectRatio e.g. '16:9'. Null leaves the choice to the model.
     * @param ?string $imageSize e.g. '1K', '2K', '4K'. Null takes the provider's default.
     * @param ?string $outputMimeType e.g. 'image/jpeg'. Null takes the provider's default;
     *     read the type actually returned off GeneratedImage::$mimeType either way.
     * @param int $timeoutSeconds Defaults far above LLMRequest's 30, because a 4K render can
     *     take well over a minute.
     */
    public function __construct(
        public string $model,
        public string $prompt,
        public array $referenceImages = [],
        public ?string $aspectRatio = null,
        public ?string $imageSize = null,
        public ?string $outputMimeType = null,
        public int $timeoutSeconds = 120,
    ) {
        if ($model === '') {
            throw new InvalidArgumentException('ImageRequest requires a non-empty $model.');
        }

        if (trim($prompt) === '') {
            throw new InvalidArgumentException('ImageRequest requires a $prompt describing the image.');
        }

        if (!array_is_list($referenceImages)) {
            throw new InvalidArgumentException('ImageRequest $referenceImages must be a list, not a keyed array.');
        }

        foreach ($referenceImages as $image) {
            if (!$image instanceof ReferenceImage) {
                throw new InvalidArgumentException(sprintf(
                    'ImageRequest $referenceImages must contain only ReferenceImage instances; found %s.',
                    get_debug_type($image)
                ));
            }
        }

        if ($aspectRatio !== null && preg_match('/^[1-9]\d*:[1-9]\d*$/', $aspectRatio) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'ImageRequest $aspectRatio must be width:height in whole numbers, such as "16:9"; "%s" is not.',
                $aspectRatio
            ));
        }

        if ($imageSize !== null && trim($imageSize) === '') {
            throw new InvalidArgumentException('ImageRequest $imageSize must be null or a size such as "2K", not empty.');
        }

        if ($outputMimeType !== null && !str_starts_with($outputMimeType, 'image/')) {
            throw new InvalidArgumentException(sprintf(
                'ImageRequest $outputMimeType must be an image media type such as "image/jpeg"; "%s" is not one.',
                $outputMimeType
            ));
        }

        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('ImageRequest timeoutSeconds must be at least 1.');
        }
    }
}
