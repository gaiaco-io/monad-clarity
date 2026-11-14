<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLM;

use InvalidArgumentException;

/**
 * One image handed to a model alongside text. For image generation it is the picture to
 * edit, or a reference for subject, style or composition (`ReleaseNotes_1.9.0.md` §1.2);
 * since 1.10.0 it is also what a user message carries in `LLMRequest`'s `images` key, for
 * any adapter's complete() to look at (`ReleaseNotes_1.10.0.md` §2.3).
 *
 * Holds raw bytes, never base64: encoding for the wire is the adapter's job, so a caller
 * passes `file_get_contents($path)` and nothing else.
 *
 * @package Monad\Clarity\Services\LLM
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class ReferenceImage
{
    /**
     * @param string $mimeType The image's media type, e.g. 'image/png'. Which types a model
     *     accepts is the provider's to say and changes by model, so only the shape is checked.
     * @param string $bytes The image file's raw contents.
     */
    public function __construct(
        public string $mimeType,
        public string $bytes,
    ) {
        if (!str_starts_with($mimeType, 'image/')) {
            throw new InvalidArgumentException(sprintf(
                'ReferenceImage $mimeType must be an image media type such as "image/png"; "%s" is not one.',
                $mimeType
            ));
        }

        if ($bytes === '') {
            throw new InvalidArgumentException('ReferenceImage $bytes is empty; pass the image file\'s contents.');
        }
    }
}
