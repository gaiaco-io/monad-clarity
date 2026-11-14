<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLM;

/**
 * One image a model produced, already decoded: `file_put_contents($path, $image->bytes)`
 * writes a file that opens (`ReleaseNotes_1.9.0.md` §1.3).
 *
 * $mimeType is what the provider said the image is, not what the request asked for. A
 * provider may honour a requested type, ignore it or substitute its own default, and a file
 * extension chosen from the request rather than from here would then lie.
 *
 * @package Monad\Clarity\Services\LLM
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class GeneratedImage
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
    ) {
    }
}
