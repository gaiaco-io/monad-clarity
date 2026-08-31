<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Mail;

use DateTimeImmutable;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\MimeMessage;
use PHPUnit\Framework\TestCase;

final class MimeMessageTest extends TestCase
{
    private const SENT_AT = '2026-08-31 09:30:00';

    private static function build(mixed ...$overrides): string
    {
        $message = new Message(...[
            'from' => new Address('app@example.com', 'App'),
            'to' => [new Address('someone@example.com', 'Someone')],
            'subject' => 'Hello',
            'text' => 'Hello there.',
            ...$overrides,
        ]);

        return MimeMessage::build($message, new DateTimeImmutable(self::SENT_AT));
    }

    /**
     * The boundary is random per build, so structural assertions read it back rather than
     * hard-coding it. Everything else in the document is asserted literally.
     */
    private static function boundaryOf(string $mime, string $subtype): string
    {
        self::assertMatchesRegularExpression(
            sprintf('#Content-Type: multipart/%s; boundary="([^"]+)"#', preg_quote($subtype, '#')),
            $mime
        );

        preg_match(
            sprintf('#Content-Type: multipart/%s; boundary="([^"]+)"#', preg_quote($subtype, '#')),
            $mime,
            $matches
        );

        return $matches[1];
    }

    private static function decodePart(string $mime, string $contentType): string
    {
        $pattern = sprintf(
            '#Content-Type: %s; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+/=\r\n]+?)(?:\r\n--|$)#',
            preg_quote($contentType, '#')
        );

        self::assertMatchesRegularExpression($pattern, $mime);
        preg_match($pattern, $mime, $matches);

        return base64_decode(str_replace("\r\n", '', $matches[1]), true);
    }

    public function testWritesTheStructuralHeaders(): void
    {
        $mime = self::build();

        self::assertStringContainsString("Date: Mon, 31 Aug 2026 09:30:00 +0000\r\n", $mime);
        self::assertStringContainsString("From: App <app@example.com>\r\n", $mime);
        self::assertStringContainsString("To: Someone <someone@example.com>\r\n", $mime);
        self::assertStringContainsString("Subject: Hello\r\n", $mime);
        self::assertStringContainsString("MIME-Version: 1.0\r\n", $mime);
        self::assertMatchesRegularExpression('#Message-ID: <[0-9a-f]{32}@example\.com>\r\n#', $mime);
    }

    public function testUsesCrlfThroughout(): void
    {
        $mime = self::build(html: '<p>Hello</p>', attachments: [
            new Attachment('invoice.pdf', 'application/pdf', '%PDF-1.4'),
        ]);

        // Every LF must be preceded by CR, and every CR followed by LF. SMTP correctness
        // rests on this, and Phase 3's dot-stuffing reads the document line by line.
        self::assertSame(0, preg_match('/(?<!\r)\n/', $mime), 'Found an LF not preceded by CR.');
        self::assertSame(0, preg_match('/\r(?!\n)/', $mime), 'Found a CR not followed by LF.');
    }

    /**
     * Security-critical, and the reason this class exists rather than a sprintf at each call
     * site: ReleaseNotes_1.6.0.md §2.12.
     */
    public function testNeverEmitsABccHeader(): void
    {
        $mime = self::build(
            cc: [new Address('cc@example.com')],
            bcc: [new Address('blind@example.com'), new Address('alsoblind@example.com')],
        );

        self::assertStringContainsString("Cc: cc@example.com\r\n", $mime);

        // Scoped to the header block: base64 body content can legitimately contain the
        // substring "bcc", and a whole-document assertion would fail mysteriously the day
        // someone adds a body to this case.
        $headerBlock = substr($mime, 0, (int) strpos($mime, "\r\n\r\n"));

        self::assertStringNotContainsStringIgnoringCase('bcc', $headerBlock);
        self::assertStringNotContainsString('blind@example.com', $mime);
        self::assertStringNotContainsString('alsoblind@example.com', $mime);
    }

    public function testWritesReplyToAndExtraHeaders(): void
    {
        $mime = self::build(
            replyTo: new Address('support@example.com', 'Support'),
            headers: ['X-Campaign' => 'spring'],
        );

        self::assertStringContainsString("Reply-To: Support <support@example.com>\r\n", $mime);
        self::assertStringContainsString("X-Campaign: spring\r\n", $mime);
    }

    public function testEncodesNonAsciiSubjectAsAnEncodedWord(): void
    {
        $mime = self::build(subject: 'Réservation — confirmée');

        self::assertStringContainsString(
            'Subject: =?UTF-8?B?' . base64_encode('Réservation — confirmée') . "?=\r\n",
            $mime
        );
    }

    public function testEncodesNonAsciiDisplayNameWithoutQuotingIt(): void
    {
        $mime = self::build(to: [new Address('jose@example.com', 'José Ferreira')]);

        self::assertStringContainsString(
            'To: =?UTF-8?B?' . base64_encode('José Ferreira') . "?= <jose@example.com>\r\n",
            $mime
        );
    }

    // --- The five body shapes -------------------------------------------------------

    public function testShapeOneTextOnly(): void
    {
        $mime = self::build();

        self::assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\n", $mime);
        self::assertStringNotContainsString('multipart', $mime);
        self::assertSame('Hello there.', self::decodePart($mime, 'text/plain'));
    }

    public function testShapeTwoHtmlOnly(): void
    {
        $mime = self::build(text: null, html: '<p>Hello</p>');

        self::assertStringContainsString("Content-Type: text/html; charset=UTF-8\r\n", $mime);
        self::assertStringNotContainsString('multipart', $mime);
        self::assertSame('<p>Hello</p>', self::decodePart($mime, 'text/html'));
    }

    public function testShapeThreeBothBodiesIsAlternativeWithTextFirst(): void
    {
        $mime = self::build(html: '<p>Hello</p>');

        $boundary = self::boundaryOf($mime, 'alternative');

        self::assertStringContainsString('--' . $boundary . "\r\n", $mime);
        self::assertStringEndsWith('--' . $boundary . '--', $mime);

        // RFC 2046 §5.1.4: clients render the LAST part they understand, so text must precede
        // HTML for HTML to win.
        self::assertLessThan(
            strpos($mime, 'text/html'),
            strpos($mime, 'text/plain'),
            'text/plain must precede text/html inside multipart/alternative.'
        );

        self::assertSame('Hello there.', self::decodePart($mime, 'text/plain'));
        self::assertSame('<p>Hello</p>', self::decodePart($mime, 'text/html'));
    }

    public function testShapeFourFileAttachmentIsMixed(): void
    {
        $mime = self::build(
            html: '<p>Hello</p>',
            attachments: [new Attachment('invoice.pdf', 'application/pdf', '%PDF-1.4 body')],
        );

        $mixed = self::boundaryOf($mime, 'mixed');
        $alternative = self::boundaryOf($mime, 'alternative');

        self::assertNotSame($mixed, $alternative);
        self::assertStringEndsWith('--' . $mixed . '--', $mime);

        self::assertStringContainsString(
            "Content-Type: application/pdf; name=\"invoice.pdf\"\r\n",
            $mime
        );
        self::assertStringContainsString(
            "Content-Disposition: attachment; filename=\"invoice.pdf\"\r\n",
            $mime
        );
        self::assertStringContainsString(base64_encode('%PDF-1.4 body'), $mime);
        self::assertStringNotContainsString('Content-ID', $mime);
    }

    public function testShapeFiveInlineImageIsRelated(): void
    {
        $mime = self::build(
            text: null,
            html: '<img src="cid:logo">',
            attachments: [Attachment::inline('logo.png', 'image/png', 'PNGDATA', 'logo')],
        );

        $related = self::boundaryOf($mime, 'related');

        self::assertStringEndsWith('--' . $related . '--', $mime);
        self::assertStringNotContainsString('multipart/mixed', $mime);

        self::assertStringContainsString("Content-ID: <logo>\r\n", $mime);
        self::assertStringContainsString(
            "Content-Disposition: inline; filename=\"logo.png\"\r\n",
            $mime
        );
        self::assertStringContainsString(base64_encode('PNGDATA'), $mime);
    }

    public function testInlineAndFileAttachmentsNestRelatedInsideMixed(): void
    {
        $mime = self::build(
            html: '<img src="cid:logo">',
            attachments: [
                Attachment::inline('logo.png', 'image/png', 'PNGDATA', 'logo'),
                new Attachment('invoice.pdf', 'application/pdf', '%PDF'),
            ],
        );

        $mixed = self::boundaryOf($mime, 'mixed');

        self::assertStringContainsString('multipart/related', $mime);
        self::assertStringContainsString('multipart/alternative', $mime);
        self::assertStringEndsWith('--' . $mixed . '--', $mime);

        // related must sit inside mixed, not the other way round.
        self::assertLessThan(
            strpos($mime, 'multipart/related'),
            strpos($mime, 'multipart/mixed'),
            'multipart/mixed must enclose multipart/related.'
        );
    }

    public function testBase64BodyIsWrappedAtSeventySixColumns(): void
    {
        $mime = self::build(text: str_repeat('abcdefghij', 40));

        foreach (explode("\r\n", $mime) as $line) {
            self::assertLessThanOrEqual(76, strlen($line));
        }
    }

    public function testBinaryAttachmentSurvivesRoundTrip(): void
    {
        $bytes = random_bytes(2048);

        $mime = self::build(attachments: [new Attachment('blob.bin', 'application/octet-stream', $bytes)]);

        $pattern = "#Content-Disposition: attachment; filename=\"blob.bin\"\r\n\r\n([A-Za-z0-9+/=\r\n]+?)\r\n--#";
        self::assertMatchesRegularExpression($pattern, $mime);
        preg_match($pattern, $mime, $matches);

        self::assertSame($bytes, base64_decode(str_replace("\r\n", '', $matches[1]), true));
    }
}
