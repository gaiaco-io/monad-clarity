<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\MailAdapters;

use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\MailAdapters\Postmark;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class PostmarkTest extends TestCase
{
    private static function message(mixed ...$overrides): Message
    {
        return new Message(...[
            'from' => new Address('app@example.com', 'App'),
            'to' => [new Address('someone@example.com', 'Someone')],
            'subject' => 'Hello',
            'text' => 'Hello there.',
            ...$overrides,
        ]);
    }

    private static function accepted(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'To' => 'someone@example.com',
            'SubmittedAt' => '2026-08-31T09:30:00Z',
            'MessageID' => 'b7bc2f4a-e38e-4336-af7d-e6c392c2f817',
            'ErrorCode' => 0,
            'Message' => 'OK',
        ], JSON_THROW_ON_ERROR));
    }

    public function testSendsTheExpectedRequestAndParsesTheResponse(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());
        $sent = (new Postmark('server-token', $fake))->send(self::message());

        $request = $fake->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.postmarkapp.com/email', (string) $request->getUri());
        self::assertSame('server-token', $request->getHeaderLine('X-Postmark-Server-Token'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = $fake->decodedLastRequestBody();
        self::assertSame('App <app@example.com>', $body['From']);
        self::assertSame('Someone <someone@example.com>', $body['To']);
        self::assertSame('Hello', $body['Subject']);
        self::assertSame('Hello there.', $body['TextBody']);
        self::assertSame('outbound', $body['MessageStream']);
        self::assertArrayNotHasKey('HtmlBody', $body);

        self::assertSame('postmark', $sent->mailer);
        self::assertSame('b7bc2f4a-e38e-4336-af7d-e6c392c2f817', $sent->providerMessageId);
        self::assertFalse($sent->failedOver());
        self::assertCount(1, $sent->attempts);
    }

    public function testJoinsRecipientFieldsAsCommaSeparatedStrings(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Postmark('t', $fake))->send(self::message(
            to: [new Address('a@example.com'), new Address('b@example.com')],
            cc: [new Address('c@example.com')],
            bcc: [new Address('d@example.com')],
            replyTo: new Address('support@example.com'),
        ));

        $body = $fake->decodedLastRequestBody();
        self::assertSame('a@example.com, b@example.com', $body['To']);
        self::assertSame('c@example.com', $body['Cc']);
        self::assertSame('d@example.com', $body['Bcc']);
        self::assertSame('support@example.com', $body['ReplyTo']);
    }

    public function testSendsCustomHeadersAsNameValuePairs(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Postmark('t', $fake))->send(self::message(headers: ['X-Campaign' => 'spring']));

        self::assertSame(
            [['Name' => 'X-Campaign', 'Value' => 'spring']],
            $fake->decodedLastRequestBody()['Headers']
        );
    }

    public function testBase64EncodesAttachments(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Postmark('t', $fake))->send(self::message(attachments: [
            new Attachment('invoice.pdf', 'application/pdf', '%PDF-1.4'),
            Attachment::inline('logo.png', 'image/png', 'PNGDATA', 'logo'),
        ]));

        $attachments = $fake->decodedLastRequestBody()['Attachments'];

        self::assertSame('invoice.pdf', $attachments[0]['Name']);
        self::assertSame(base64_encode('%PDF-1.4'), $attachments[0]['Content']);
        self::assertArrayNotHasKey('ContentID', $attachments[0]);

        self::assertSame('cid:logo', $attachments[1]['ContentID']);
    }

    public function testRefusesMoreThanOneTagRatherThanDroppingOne(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('carries a single tag');

        (new Postmark('t', $fake))->send(self::message(tags: ['welcome', 'onboarding']));
    }

    /**
     * Postmark's second failure channel: HTTP 200 with a non-zero ErrorCode. An adapter
     * trusting the status alone would return a SentMessage for a refused message.
     */
    public function testTreatsATwoHundredWithAnErrorCodeAsAFailure(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['ErrorCode' => 406, 'Message' => 'You tried to send to a recipient that has been marked as inactive.'], JSON_THROW_ON_ERROR)
        ));

        try {
            (new Postmark('t', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertStringContainsString('code 406', $e->getMessage());
            self::assertStringContainsString('marked as inactive', $e->getMessage());
            // An inactive recipient is refused by every provider — the message's fault.
            self::assertSame(FailureScope::Message, $e->scope);
        }
    }

    public function testAnUnknownErrorCodeOnATwoHundredIsTheMailersFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['ErrorCode' => 405, 'Message' => 'Account not approved for sending.'], JSON_THROW_ON_ERROR)
        ));

        try {
            (new Postmark('t', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
        }
    }

    /** §2.4: bad credentials are exactly when another mailer should get its turn. */
    public function testUnauthorisedIsTheMailersFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(401, [], 'Unauthorized'));

        try {
            (new Postmark('wrong', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertTrue($e->isMailerFault());
        }
    }

    public function testAValidationErrorIsTheMessagesFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            422,
            ['Content-Type' => 'application/json'],
            json_encode(['ErrorCode' => 300, 'Message' => 'Invalid email address.'], JSON_THROW_ON_ERROR)
        ));

        try {
            (new Postmark('t', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
        }
    }

    /**
     * Proves the `scopeFromErrorBody()` seam is genuinely dispatched to the adapter's own
     * override rather than the trait's null default. A 401's status default is Mailer, so
     * only a real override can turn this into Message — a weaker test using a 422 would pass
     * either way, because 422 already defaults to Message.
     */
    public function testTheAdaptersOwnReadingOfTheBodyBeatsTheStatusDefault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            401,
            [],
            json_encode(['ErrorCode' => 300, 'Message' => 'Invalid email address.'], JSON_THROW_ON_ERROR)
        ));

        try {
            (new Postmark('t', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
        }
    }

    /** The path a real outage takes: no ErrorCode to read, so the status default applies. */
    public function testAnErrorBodyWithNoErrorCodeFallsBackToTheStatusDefault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            503,
            ['Content-Type' => 'text/html'],
            '<html><body>503 Service Unavailable</body></html>'
        ));

        try {
            (new Postmark('t', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('HTTP 503', $e->getMessage());
        }
    }

    public function testHonoursACustomMessageStream(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Postmark('t', $fake, 'broadcast'))->send(self::message());

        self::assertSame('broadcast', $fake->decodedLastRequestBody()['MessageStream']);
    }
}
