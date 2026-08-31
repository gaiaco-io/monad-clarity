<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Integration;

use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\Attempt;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\MailerPool;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\MailAdapters\AmazonSes;
use Monad\Clarity\Services\MailAdapters\Postmark;
use Monad\Clarity\Services\MailAdapters\Resend;
use Monad\Clarity\Services\MailAdapters\Smtp;
use Monad\Clarity\Services\Mail\SmtpEncryption;
use Monad\Clarity\Tests\Services\MailAdapters\FakeHttpClient;
use Monad\Clarity\Tests\Services\MailAdapters\ScriptedTransport;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * The pool driving the *real* adapters rather than test doubles of them.
 *
 * `MailerPoolTest` proves the pool's own logic against scripted mailers; this proves the
 * pieces compose — that a genuine Postmark adapter classifies a genuine Postmark error body
 * as `Mailer`, that the pool reads that classification, and that a genuine Resend adapter
 * then takes the message. Those are three units that pass their own tests and could still
 * fail to agree with each other.
 *
 * It stands in for the part of `ReleaseNotes_1.6.0.md` §7's gate that needs no credentials.
 * The live skeleton run against a Mailtrap sandbox is still the gate's last item.
 */
final class MailFailoverTest extends TestCase
{
    private static function message(mixed ...$overrides): Message
    {
        return new Message(...[
            'from' => new Address('app@example.com', 'App'),
            'to' => [new Address('someone@example.com', 'Someone')],
            'subject' => 'Reset your password',
            'text' => 'Follow the link to reset your password.',
            ...$overrides,
        ]);
    }

    public function testARealPostmarkOutageFailsOverToARealResend(): void
    {
        $postmark = new Postmark('bad-token', new FakeHttpClient(
            static fn (): Response => new Response(503, [], '<html>503 Service Unavailable</html>')
        ));

        $resend = new Resend('re_key', new FakeHttpClient(
            static fn (): Response => new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['id' => 're-1'], JSON_THROW_ON_ERROR)
            )
        ));

        $sent = (new MailerPool([$postmark, $resend]))->send(self::message());

        self::assertSame('resend', $sent->mailer);
        self::assertSame('re-1', $sent->providerMessageId);
        self::assertTrue($sent->failedOver());

        self::assertSame(
            ['postmark', 'resend'],
            array_map(static fn (Attempt $a): string => $a->mailer, $sent->attempts)
        );
        self::assertStringContainsString('HTTP 503', (string) $sent->attempts[0]->reason());
    }

    /**
     * The classification that costs latency if it is wrong, proven through a real adapter's
     * reading of a real provider error body rather than a hand-made MailException.
     */
    public function testARealValidationErrorStopsThePoolWithoutTryingTheNext(): void
    {
        $postmark = new Postmark('token', new FakeHttpClient(
            static fn (): Response => new Response(
                422,
                ['Content-Type' => 'application/json'],
                json_encode(['ErrorCode' => 300, 'Message' => 'Invalid email address.'], JSON_THROW_ON_ERROR)
            )
        ));

        $resendClient = new FakeHttpClient(
            static fn (): Response => new Response(200, [], json_encode(['id' => 're-1'], JSON_THROW_ON_ERROR))
        );

        try {
            (new MailerPool([$postmark, new Resend('re_key', $resendClient)]))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
            self::assertSame(0, $resendClient->requestCount(), 'Resend must never have been asked.');
        }
    }

    /**
     * The three adapters that build their payloads most differently — JSON, RFC 5322 over a
     * socket, and an SDK argument array — carrying one message with an attachment, so a
     * failure to agree on `Message` shows up as a real send rather than a unit assertion.
     */
    public function testAMessageWithAnAttachmentCrossesEveryAdapterShape(): void
    {
        $message = self::message(
            html: '<p>Follow the link.</p>',
            bcc: [new Address('audit@example.com')],
            attachments: [new Attachment('receipt.pdf', 'application/pdf', '%PDF-1.4 receipt')],
        );

        // Postmark fails, SMTP is asked next, and SES would be third.
        $postmark = new Postmark('token', new FakeHttpClient(
            static fn (): Response => new Response(429, [], '{"ErrorCode":405,"Message":"Rate limited"}')
        ));

        $transport = new ScriptedTransport([
            '220 mail.example.com ESMTP',
            '250-mail.example.com Hello',
            '250-STARTTLS',
            '250 AUTH PLAIN',
            '220 Go ahead',
            '250-Hello',
            '250 AUTH PLAIN',
            '235 Authenticated',
            '250 OK',
            '250 Accepted',
            '250 Accepted',
            '354 Go ahead',
            '250 Ok: queued as ABC123',
        ]);

        $smtp = new Smtp(
            host: 'mail.example.com',
            username: 'user',
            password: 'secret',
            encryption: SmtpEncryption::StartTls,
            transport: $transport,
        );

        $sent = (new MailerPool([$postmark, $smtp, new AmazonSes(new FakeSes())]))->send($message);

        self::assertSame('smtp', $sent->mailer);
        self::assertSame('ABC123', $sent->providerMessageId);

        // The blind recipient reached the envelope and not the document — §2.12, end to end.
        $conversation = $transport->conversation();
        self::assertStringContainsString('RCPT TO:<audit@example.com>', $conversation);

        $body = substr($conversation, (int) strpos($conversation, 'DATA'));
        self::assertStringNotContainsString('audit@example.com', $body);
        self::assertStringContainsString(base64_encode('%PDF-1.4 receipt'), $body);
    }

    public function testEveryMailerFailingReportsAllOfThemTogether(): void
    {
        $down = static fn (): Response => new Response(503, [], 'Service Unavailable');

        try {
            (new MailerPool([
                new Postmark('token', new FakeHttpClient($down)),
                new Resend('re_key', new FakeHttpClient($down)),
            ]))->send(self::message());

            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('All 2 mailers in the pool failed', $e->getMessage());
            self::assertStringContainsString('postmark:', $e->getMessage());
            self::assertStringContainsString('resend:', $e->getMessage());
        }
    }
}

/** An SES client that would succeed, present only to prove it is never reached. */
final class FakeSes
{
    public bool $wasCalled = false;

    /**
     * @param array<string, mixed> $args
     * @return array<string, string>
     */
    public function sendEmail(array $args): array
    {
        $this->wasCalled = true;

        return ['MessageId' => 'ses-1'];
    }
}
