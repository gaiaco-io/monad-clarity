<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\MailAdapters;

use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\MailAdapters\Mailtrap;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MailtrapTest extends TestCase
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
            'success' => true,
            'message_ids' => ['0c7fd939-02cf-11ed-88c2-0a58a9feac02'],
        ], JSON_THROW_ON_ERROR));
    }

    public function testSendingHitsTheLiveEndpoint(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());
        $sent = Mailtrap::sending('token', $fake)->send(self::message());

        $request = $fake->lastRequest();
        self::assertSame('https://send.api.mailtrap.io/api/send', (string) $request->getUri());
        self::assertSame('token', $request->getHeaderLine('Api-Token'));

        self::assertSame('mailtrap', $sent->mailer);
        self::assertSame('0c7fd939-02cf-11ed-88c2-0a58a9feac02', $sent->providerMessageId);
    }

    public function testSandboxHitsTheInboxEndpointOnADifferentHost(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());
        $sent = Mailtrap::sandbox('token', '3000123', $fake)->send(self::message());

        self::assertSame(
            'https://sandbox.api.mailtrap.io/api/send/3000123',
            (string) $fake->lastRequest()->getUri()
        );

        // The trail must say the sandbox took it — "it sent fine in staging" is a shorter
        // conversation when the record names which one sent it.
        self::assertSame('mailtrap_sandbox', $sent->mailer);
        self::assertSame('mailtrap_sandbox', $sent->attempts[0]->mailer);
    }

    public function testSendsAddressesAsObjects(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        Mailtrap::sending('t', $fake)->send(self::message(
            cc: [new Address('c@example.com', 'Cee')],
            replyTo: new Address('support@example.com'),
        ));

        $body = $fake->decodedLastRequestBody();
        self::assertSame(['email' => 'app@example.com', 'name' => 'App'], $body['from']);
        self::assertSame([['email' => 'someone@example.com', 'name' => 'Someone']], $body['to']);
        self::assertSame([['email' => 'c@example.com', 'name' => 'Cee']], $body['cc']);
        self::assertSame(['email' => 'support@example.com'], $body['reply_to']);
    }

    public function testTakesTheFirstTagAsTheCategory(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::accepted());

        Mailtrap::sending('t', $fake)->send(self::message(tags: ['welcome', 'onboarding']));

        self::assertSame('welcome', $fake->decodedLastRequestBody()['category']);
    }

    public function testSurvivesAResponseWithNoMessageIds(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            200,
            [],
            json_encode(['success' => true], JSON_THROW_ON_ERROR)
        ));

        self::assertNull(Mailtrap::sending('t', $fake)->send(self::message())->providerMessageId);
    }

    public function testUnauthorisedIsTheMailersFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(401, [], '{"errors":["Incorrect API token"]}'));

        try {
            Mailtrap::sending('bad', $fake)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('mailtrap', $e->getMessage());
        }
    }

    public function testAValidationErrorIsTheMessagesFault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            422,
            [],
            '{"errors":["\'to\' address is invalid"]}'
        ));

        try {
            Mailtrap::sending('t', $fake)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
        }
    }
}
