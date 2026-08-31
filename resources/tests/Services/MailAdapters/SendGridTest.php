<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\MailAdapters;

use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\MailAdapters\SendGrid;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SendGridTest extends TestCase
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

    /** A successful send: 202, empty body, id in a response header. */
    private static function accepted(): Response
    {
        return new Response(202, ['X-Message-Id' => 'kZLGKuGGRJqBIYbmqPKmXw'], '');
    }

    public function testParsesTheMessageIdFromTheResponseHeaderOfAnEmptyTwoOhTwo(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());
        $sent = (new SendGrid('SG.key', $fake))->send(self::message());

        self::assertSame('sendgrid', $sent->mailer);
        self::assertSame('kZLGKuGGRJqBIYbmqPKmXw', $sent->providerMessageId);

        $request = $fake->lastRequest();
        self::assertSame('https://api.sendgrid.com/v3/mail/send', (string) $request->getUri());
        self::assertSame('Bearer SG.key', $request->getHeaderLine('Authorization'));
    }

    public function testSurvivesAResponseCarryingNoMessageIdHeader(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(202, [], ''));

        self::assertNull((new SendGrid('k', $fake))->send(self::message())->providerMessageId);
    }

    /** SendGrid rejects the payload outright if text/html precedes text/plain. */
    public function testPutsPlainTextBeforeHtmlInContent(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new SendGrid('k', $fake))->send(self::message(html: '<p>Hello</p>'));

        self::assertSame(
            [
                ['type' => 'text/plain', 'value' => 'Hello there.'],
                ['type' => 'text/html', 'value' => '<p>Hello</p>'],
            ],
            $fake->decodedLastRequestBody()['content']
        );
    }

    public function testSendsHtmlAloneWhenThereIsNoTextBody(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new SendGrid('k', $fake))->send(self::message(text: null, html: '<p>Hello</p>'));

        self::assertSame(
            [['type' => 'text/html', 'value' => '<p>Hello</p>']],
            $fake->decodedLastRequestBody()['content']
        );
    }

    /** §2.12: one Message is one email, so exactly one personalization. */
    public function testPutsEveryRecipientInASinglePersonalization(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new SendGrid('k', $fake))->send(self::message(
            to: [new Address('a@example.com'), new Address('b@example.com', 'Bee')],
            cc: [new Address('c@example.com')],
            bcc: [new Address('d@example.com')],
        ));

        $personalizations = $fake->decodedLastRequestBody()['personalizations'];

        self::assertCount(1, $personalizations);
        self::assertSame(
            [['email' => 'a@example.com'], ['email' => 'b@example.com', 'name' => 'Bee']],
            $personalizations[0]['to']
        );
        self::assertSame([['email' => 'c@example.com']], $personalizations[0]['cc']);
        self::assertSame([['email' => 'd@example.com']], $personalizations[0]['bcc']);
    }

    public function testSendsFromAndReplyToAsObjects(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new SendGrid('k', $fake))->send(self::message(replyTo: new Address('support@example.com', 'Support')));

        $body = $fake->decodedLastRequestBody();
        self::assertSame(['email' => 'app@example.com', 'name' => 'App'], $body['from']);
        self::assertSame(['email' => 'support@example.com', 'name' => 'Support'], $body['reply_to']);
    }

    public function testMapsTagsToCategories(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new SendGrid('k', $fake))->send(self::message(tags: ['welcome', 'onboarding']));

        self::assertSame(['welcome', 'onboarding'], $fake->decodedLastRequestBody()['categories']);
    }

    public function testSendsAttachmentsWithTheirDisposition(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        (new SendGrid('k', $fake))->send(self::message(attachments: [
            new Attachment('invoice.pdf', 'application/pdf', '%PDF-1.4'),
            Attachment::inline('logo.png', 'image/png', 'PNGDATA', 'logo'),
        ]));

        $attachments = $fake->decodedLastRequestBody()['attachments'];

        self::assertSame('attachment', $attachments[0]['disposition']);
        self::assertSame(base64_encode('%PDF-1.4'), $attachments[0]['content']);

        self::assertSame('inline', $attachments[1]['disposition']);
        self::assertSame('logo', $attachments[1]['content_id']);
    }

    public function testUnauthorisedIsTheMailersFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(401, [], '{"errors":[{"message":"unauthorized"}]}'));

        try {
            (new SendGrid('bad', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
        }
    }

    public function testABadRequestIsTheMessagesFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            400,
            [],
            '{"errors":[{"message":"Does not contain a valid address."}]}'
        ));

        try {
            (new SendGrid('k', $fake))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
            self::assertStringContainsString('valid address', $e->getMessage());
        }
    }
}
