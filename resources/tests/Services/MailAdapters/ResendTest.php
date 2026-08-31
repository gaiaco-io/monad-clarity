<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\MailAdapters;

use Monad\Clarity\Services\HttpClientException;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\MailAdapters\Resend;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ResendTest extends TestCase
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
            'id' => '4ef9a417-02e9-4d39-ad75-9611e0fcc33c',
        ], JSON_THROW_ON_ERROR));
    }

    public function testSendsTheExpectedRequestAndParsesTheResponse(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());
        $sent = (new Resend('re_key', $fake))->send(self::message(html: '<p>Hello</p>'));

        $request = $fake->lastRequest();
        self::assertSame('https://api.resend.com/emails', (string) $request->getUri());
        self::assertSame('Bearer re_key', $request->getHeaderLine('Authorization'));

        $body = $fake->decodedLastRequestBody();
        self::assertSame('App <app@example.com>', $body['from']);
        self::assertSame(['someone@example.com'], $body['to']);
        self::assertSame('Hello there.', $body['text']);
        self::assertSame('<p>Hello</p>', $body['html']);

        self::assertSame('resend', $sent->mailer);
        self::assertSame('4ef9a417-02e9-4d39-ad75-9611e0fcc33c', $sent->providerMessageId);
    }

    public function testSendsRecipientsAsArrays(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Resend('k', $fake))->send(self::message(
            to: [new Address('a@example.com'), new Address('b@example.com', 'Bee')],
            cc: [new Address('c@example.com')],
            bcc: [new Address('d@example.com')],
        ));

        $body = $fake->decodedLastRequestBody();
        self::assertSame(['a@example.com', 'Bee <b@example.com>'], $body['to']);
        self::assertSame(['c@example.com'], $body['cc']);
        self::assertSame(['d@example.com'], $body['bcc']);
    }

    public function testExpandsTagsIntoNameValuePairs(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Resend('k', $fake))->send(self::message(tags: ['welcome', 'onboarding']));

        self::assertSame(
            [['name' => 'welcome', 'value' => 'welcome'], ['name' => 'onboarding', 'value' => 'onboarding']],
            $fake->decodedLastRequestBody()['tags']
        );
    }

    public function testSendsAttachments(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new Resend('k', $fake))->send(self::message(attachments: [
            new Attachment('invoice.pdf', 'application/pdf', '%PDF-1.4'),
        ]));

        $attachment = $fake->decodedLastRequestBody()['attachments'][0];
        self::assertSame('invoice.pdf', $attachment['filename']);
        self::assertSame(base64_encode('%PDF-1.4'), $attachment['content']);
        self::assertSame('application/pdf', $attachment['content_type']);
    }

    public function testUnauthorisedIsTheMailersFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(401, [], '{"message":"Invalid API key"}'));

        try {
            (new Resend('bad', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
        }
    }

    public function testAValidationErrorIsTheMessagesFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            422,
            [],
            '{"message":"Invalid `to` field."}'
        ));

        try {
            (new Resend('k', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
        }
    }

    /**
     * The translation a pool depends on: without it a transport failure escapes as an
     * HttpClientException, which MailerPool does not catch and cannot classify.
     */
    public function testATransportFailureBecomesAMailerFault(): void
    {
        $fake = new FakeHttpClient(static function (): Response {
            throw new HttpClientException(new Request('POST', 'https://api.resend.com/emails'), 'cURL error 28: Operation timed out', 28);
        });

        try {
            (new Resend('k', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('could not be reached', $e->getMessage());
            self::assertInstanceOf(HttpClientException::class, $e->getPrevious());
        }
    }

    public function testAResponseThatIsNotJsonIsTheMailersFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(200, [], '<html>502 Bad Gateway</html>'));

        try {
            (new Resend('k', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('not valid JSON', $e->getMessage());
        }
    }
}
