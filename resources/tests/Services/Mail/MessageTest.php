<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Mail;

use InvalidArgumentException;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    private static function message(mixed ...$overrides): Message
    {
        return new Message(...[
            'from' => new Address('app@example.com', 'App'),
            'to' => [new Address('someone@example.com')],
            'subject' => 'Hello',
            'text' => 'Hello there.',
            ...$overrides,
        ]);
    }

    public function testRequiresAtLeastOneRecipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one "to" recipient');

        self::message(to: []);
    }

    public function testRequiresABody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a text body, an HTML body, or both');

        self::message(text: null, html: null);
    }

    public function testTreatsWhitespaceOnlyBodiesAsAbsent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::message(text: "   \n ", html: '');
    }

    public function testAcceptsHtmlOnly(): void
    {
        $message = self::message(text: null, html: '<p>Hello</p>');

        self::assertNull($message->text);
        self::assertSame('<p>Hello</p>', $message->html);
    }

    /** Security-critical: a newline in the subject forges a header. */
    public function testRejectsSubjectHeaderInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line break or NUL byte');

        self::message(subject: "Hello\r\nBcc: attacker@evil.test");
    }

    /** Security-critical: the front-door route to the same disclosure §2.12 prevents. */
    public function testRefusesBccAsAnExtraHeader(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('part of the message structure');

        self::message(headers: ['Bcc' => 'attacker@evil.test']);
    }

    #[DataProvider('reservedHeaders')]
    public function testRefusesEveryStructuralHeader(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('part of the message structure');

        self::message(headers: [$name => 'anything']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedHeaders(): iterable
    {
        foreach (['From', 'To', 'Cc', 'Bcc', 'Subject', 'Reply-To', 'Date', 'Message-ID', 'MIME-Version', 'Content-Type'] as $name) {
            yield $name => [$name];
            yield strtolower($name) => [strtolower($name)];
        }
    }

    public function testRejectsHeaderValueInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line break or NUL byte');

        self::message(headers: ['X-Campaign' => "spring\r\nBcc: attacker@evil.test"]);
    }

    public function testRejectsInvalidHeaderName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid RFC 5322 field name');

        self::message(headers: ['X Campaign' => 'spring']);
    }

    public function testAcceptsOrdinaryExtraHeader(): void
    {
        self::assertSame(
            ['X-Campaign' => 'spring'],
            self::message(headers: ['X-Campaign' => 'spring'])->headers
        );
    }

    public function testRejectsNonAddressRecipients(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a Mail\Address');

        self::message(to: ['someone@example.com']);
    }

    public function testRecipientsMergesEveryFieldAndDeduplicates(): void
    {
        $message = self::message(
            to: [new Address('a@example.com'), new Address('b@example.com')],
            cc: [new Address('c@example.com')],
            bcc: [new Address('B@example.com'), new Address('d@example.com')],
        );

        self::assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'],
            array_map(static fn (Address $a): string => $a->email, $message->recipients())
        );
    }

    public function testSeparatesInlineFromFileAttachments(): void
    {
        $logo = Attachment::inline('logo.png', 'image/png', 'PNGDATA', 'logo');
        $invoice = new Attachment('invoice.pdf', 'application/pdf', '%PDF-1.4');

        $message = self::message(attachments: [$logo, $invoice]);

        self::assertTrue($message->hasAttachments());
        self::assertSame([$logo], $message->inlineAttachments());
        self::assertSame([$invoice], $message->fileAttachments());
    }

    public function testRefusesTwoInlineAttachmentsSharingAContentId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('share the content ID "logo"');

        self::message(attachments: [
            Attachment::inline('a.png', 'image/png', 'A', 'logo'),
            Attachment::inline('b.png', 'image/png', 'B', 'logo'),
        ]);
    }

    public function testRejectsBlankTag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Every tag must be a non-empty string.');

        self::message(tags: ['welcome', '  ']);
    }
}
