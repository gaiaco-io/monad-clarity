<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\MailAdapters;

use InvalidArgumentException;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\MailAdapters\Mailgun;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MailgunTest extends TestCase
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

    private static function accepted(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => '<20260831093000.1.abc@mg.example.com>',
            'message' => 'Queued. Thank you.',
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Parse a url-encoded body into name/value pairs, preserving repeats — the whole point
     * of Mailgun's encoding is that `to` may appear more than once, which parse_str destroys.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(string $body): array
    {
        return array_map(
            static function (string $pair): array {
                [$name, $value] = explode('=', $pair, 2) + [1 => ''];

                return [rawurldecode($name), rawurldecode($value)];
            },
            explode('&', $body)
        );
    }

    public function testPostsFormEncodedWithBasicAuthAndTheDomainInThePath(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());
        $sent = (new Mailgun('key-123', 'mg.example.com', $fake))->send(self::message());

        $request = $fake->lastRequest();
        self::assertSame(
            'https://api.mailgun.net/v3/mg.example.com/messages',
            (string) $request->getUri()
        );
        self::assertSame(
            'Basic ' . base64_encode('api:key-123'),
            $request->getHeaderLine('Authorization')
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

        self::assertContains(['subject', 'Hello'], self::pairs($fake->lastBody()));
        self::assertContains(['text', 'Hello there.'], self::pairs($fake->lastBody()));

        // Mailgun brackets its ids; the bare value is more useful to store.
        self::assertSame('mailgun', $sent->mailer);
        self::assertSame('20260831093000.1.abc@mg.example.com', $sent->providerMessageId);
    }

    /** Mailgun repeats the field per recipient rather than taking an array. */
    public function testRepeatsRecipientFieldsOncePerAddress(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Mailgun('k', 'mg.example.com', $fake))->send(self::message(
            to: [new Address('a@example.com'), new Address('b@example.com', 'Bee')],
            cc: [new Address('c@example.com')],
            bcc: [new Address('d@example.com')],
        ));

        $pairs = self::pairs($fake->lastBody());
        $tos = array_values(array_filter($pairs, static fn (array $p): bool => $p[0] === 'to'));

        self::assertCount(2, $tos);
        self::assertSame('a@example.com', $tos[0][1]);
        self::assertSame('Bee <b@example.com>', $tos[1][1]);

        self::assertContains(['cc', 'c@example.com'], $pairs);
        self::assertContains(['bcc', 'd@example.com'], $pairs);
    }

    public function testPrefixesCustomHeadersAndTags(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Mailgun('k', 'mg.example.com', $fake))->send(self::message(
            replyTo: new Address('support@example.com'),
            headers: ['X-Campaign' => 'spring'],
            tags: ['welcome', 'onboarding'],
        ));

        $pairs = self::pairs($fake->lastBody());

        self::assertContains(['h:Reply-To', 'support@example.com'], $pairs);
        self::assertContains(['h:X-Campaign', 'spring'], $pairs);
        self::assertContains(['o:tag', 'welcome'], $pairs);
        self::assertContains(['o:tag', 'onboarding'], $pairs);
    }

    public function testUsesTheEuHostWhenAsked(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Mailgun('k', 'mg.example.com', $fake, Mailgun::REGION_EU))->send(self::message());

        self::assertSame(
            'https://api.eu.mailgun.net/v3/mg.example.com/messages',
            (string) $fake->lastRequest()->getUri()
        );
    }

    public function testSwitchesToMultipartWhenThereAreAttachments(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Mailgun('k', 'mg.example.com', $fake))->send(self::message(
            html: '<img src="cid:logo">',
            attachments: [
                new Attachment('invoice.pdf', 'application/pdf', '%PDF-1.4 body'),
                Attachment::inline('logo.png', 'image/png', 'PNGDATA', 'logo'),
            ],
        ));

        $contentType = $fake->lastRequest()->getHeaderLine('Content-Type');
        self::assertStringStartsWith('multipart/form-data; boundary=', $contentType);

        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));
        $body = $fake->lastBody();

        self::assertStringEndsWith('--' . $boundary . "--\r\n", $body);

        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"subject\"\r\n\r\nHello\r\n",
            $body
        );

        // A file part is "attachment"; an inline one must be posted as "inline" or its
        // cid: reference will not resolve.
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"attachment\"; filename=\"invoice.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n%PDF-1.4 body\r\n",
            $body
        );
        self::assertStringContainsString(
            "Content-Disposition: form-data; name=\"inline\"; filename=\"logo.png\"\r\n"
            . "Content-Type: image/png\r\n\r\nPNGDATA\r\n",
            $body
        );

        // Attachment bytes are posted raw, so the boundary must not occur inside them: one
        // delimiter per part (from, to, subject, text, html, and the two attachments) plus
        // the closing one, and not a single occurrence more.
        self::assertSame(7 + 1, substr_count($body, '--' . $boundary));
    }

    public function testBinaryAttachmentIsPostedUnencoded(): void
    {
        $bytes = random_bytes(512);
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Mailgun('k', 'mg.example.com', $fake))->send(self::message(
            attachments: [new Attachment('blob.bin', 'application/octet-stream', $bytes)],
        ));

        self::assertStringContainsString($bytes, $fake->lastBody());
    }

    public function testRequiresASendingDomain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires the verified sending domain');

        new Mailgun('k', '   ', new FakeHttpClient(static fn (): Response => self::accepted()));
    }

    public function testUnauthorisedIsTheMailersFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(401, [], 'Forbidden'));

        try {
            (new Mailgun('bad', 'mg.example.com', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
        }
    }

    public function testAValidationErrorIsTheMessagesFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            400,
            [],
            '{"message":"to parameter is not a valid address."}'
        ));

        try {
            (new Mailgun('k', 'mg.example.com', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
        }
    }
}
