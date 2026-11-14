<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\LLM;

use InvalidArgumentException;
use Monad\Clarity\Services\LLM\ReferenceImage;
use PHPUnit\Framework\TestCase;

final class ReferenceImageTest extends TestCase
{
    public function testHoldsTheRawBytesUnencoded(): void
    {
        $image = new ReferenceImage('image/webp', "\x00\xFFraw");

        self::assertSame('image/webp', $image->mimeType);
        self::assertSame("\x00\xFFraw", $image->bytes);
    }

    public function testRejectsANonImageMediaType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"text/plain" is not one');

        new ReferenceImage('text/plain', 'bytes');
    }

    public function testRejectsEmptyBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$bytes is empty');

        new ReferenceImage('image/png', '');
    }
}
