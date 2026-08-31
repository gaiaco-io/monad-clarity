<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Mail;

use InvalidArgumentException;
use Monad\Clarity\Services\Mail\Attachment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    public function testHoldsRawBytesAndDefaultsToAttachmentDisposition(): void
    {
        $attachment = new Attachment('invoice.pdf', 'application/pdf', '%PDF-1.4');

        self::assertSame('invoice.pdf', $attachment->filename);
        self::assertSame('application/pdf', $attachment->contentType);
        self::assertSame('%PDF-1.4', $attachment->contents);
        self::assertNull($attachment->contentId);
        self::assertFalse($attachment->isInline());
        self::assertSame('attachment', $attachment->disposition());
    }

    public function testInlineCarriesAContentIdAndDisposition(): void
    {
        $logo = Attachment::inline('logo.png', 'image/png', 'PNGDATA', 'logo');

        self::assertTrue($logo->isInline());
        self::assertSame('inline', $logo->disposition());
        self::assertSame('logo', $logo->contentId);
    }

    public function testStripsAngleBracketsFromContentId(): void
    {
        self::assertSame('logo', Attachment::inline('l.png', 'image/png', 'D', '<logo>')->contentId);
    }

    public function testRejectsEmptyFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a filename');

        new Attachment('   ', 'application/pdf', 'data');
    }

    #[DataProvider('traversalFilenames')]
    public function testRejectsDirectorySeparatorInFilename(string $filename): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a directory separator');

        new Attachment($filename, 'application/pdf', 'data');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalFilenames(): iterable
    {
        yield 'posix traversal' => ['../../etc/passwd'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'windows path' => ['..\\..\\windows\\system32\\config'];
    }

    public function testRejectsHeaderInjectionInFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line break or NUL byte');

        new Attachment("invoice.pdf\r\nBcc: attacker@evil.test", 'application/pdf', 'data');
    }

    public function testRejectsMalformedContentType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid MIME type');

        new Attachment('invoice.pdf', 'not-a-mime-type', 'data');
    }

    public function testRejectsEmptyContents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is empty');

        new Attachment('invoice.pdf', 'application/pdf', '');
    }

    public function testRejectsBlankContentId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty content ID');

        new Attachment('logo.png', 'image/png', 'PNGDATA', '<>');
    }
}
