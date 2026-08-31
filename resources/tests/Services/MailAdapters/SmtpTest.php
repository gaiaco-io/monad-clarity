<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\MailAdapters;

use InvalidArgumentException;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\SmtpEncryption;
use Monad\Clarity\Services\MailAdapters\Smtp;
use PHPUnit\Framework\TestCase;

final class SmtpTest extends TestCase
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

    /**
     * A relay that offers STARTTLS and AUTH, replying successfully to the whole exchange.
     *
     * @param list<string> $extra Replies appended before the envelope stage.
     * @return list<string>
     */
    private static function script(array $extra = []): array
    {
        return [
            '220 mail.example.com ESMTP ready',
            '250-mail.example.com Hello',
            '250-STARTTLS',
            '250 AUTH PLAIN LOGIN',
            '220 Ready to start TLS',
            '250-mail.example.com Hello',
            '250 AUTH PLAIN LOGIN',
            ...$extra,
        ];
    }

    /** @return list<string> */
    private static function fullScript(int $recipients = 1): array
    {
        return [
            ...self::script(),
            '235 Authentication succeeded',
            '250 OK',
            ...array_fill(0, $recipients, '250 Accepted'),
            '354 End data with <CR><LF>.<CR><LF>',
            '250 Ok: queued as 4B2C3D9F',
        ];
    }

    private static function smtp(ScriptedTransport $transport, mixed ...$overrides): Smtp
    {
        return new Smtp(...[
            'host' => 'mail.example.com',
            'username' => 'user',
            'password' => 'secret-password',
            'transport' => $transport,
            ...$overrides,
        ]);
    }

    public function testRunsTheWholeConversationInOrder(): void
    {
        $transport = new ScriptedTransport(self::fullScript());
        $sent = self::smtp($transport)->send(self::message());

        self::assertSame([
            'EHLO example.com',
            'STARTTLS',
            'EHLO example.com',
            'AUTH PLAIN ' . base64_encode("\0user\0secret-password"),
            'MAIL FROM:<app@example.com>',
            'RCPT TO:<someone@example.com>',
            'DATA',
            'QUIT',
        ], $transport->commands());

        self::assertTrue($transport->opened);
        self::assertTrue($transport->tlsStarted);
        self::assertTrue($transport->closed);
        self::assertFalse($transport->implicitTls);
        self::assertSame(587, $transport->openedPort);

        self::assertSame('smtp', $sent->mailer);
        self::assertSame('4B2C3D9F', $sent->providerMessageId);
    }

    public function testFallsBackToHeloWhenEhloIsRefused(): void
    {
        $transport = new ScriptedTransport([
            '220 mail.example.com ready',
            '500 Command not recognised',
            '250 mail.example.com',
            '250 OK',
            '250 Accepted',
            '354 Go ahead',
            '250 Ok',
        ]);

        self::smtp($transport, username: null, password: null, encryption: SmtpEncryption::None)
            ->send(self::message());

        self::assertSame([
            'EHLO example.com',
            'HELO example.com',
            'MAIL FROM:<app@example.com>',
            'RCPT TO:<someone@example.com>',
            'DATA',
            'QUIT',
        ], $transport->commands());
    }

    public function testUsesAuthLoginWhenPlainIsNotOffered(): void
    {
        $transport = new ScriptedTransport([
            '220 ready',
            '250-Hello',
            '250-STARTTLS',
            '250 AUTH LOGIN',
            '220 Go ahead',
            '250-Hello',
            '250 AUTH LOGIN',
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 Authenticated',
            '250 OK',
            '250 Accepted',
            '354 Go ahead',
            '250 Ok',
        ]);

        self::smtp($transport)->send(self::message());

        $commands = $transport->commands();
        self::assertContains('AUTH LOGIN', $commands);
        self::assertContains(base64_encode('user'), $commands);
        self::assertContains(base64_encode('secret-password'), $commands);
    }

    /** A stripped STARTTLS advertisement is what an interception looks like. */
    public function testRefusesToContinueWhenStartTlsIsNotOffered(): void
    {
        $transport = new ScriptedTransport([
            '220 ready',
            '250-Hello',
            '250 AUTH PLAIN',
        ]);

        try {
            self::smtp($transport)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('did not offer STARTTLS', $e->getMessage());
            self::assertFalse($transport->tlsStarted);
            self::assertTrue($transport->closed, 'The socket must still be released.');
        }
    }

    public function testImplicitTlsOpensTheSocketEncryptedAndSkipsStartTls(): void
    {
        $transport = new ScriptedTransport([
            '220 ready',
            '250-Hello',
            '250 AUTH PLAIN',
            '235 Authenticated',
            '250 OK',
            '250 Accepted',
            '354 Go ahead',
            '250 Ok',
        ]);

        self::smtp($transport, port: 465, encryption: SmtpEncryption::ImplicitTls)->send(self::message());

        self::assertTrue($transport->implicitTls);
        self::assertSame(465, $transport->openedPort);
        self::assertFalse($transport->tlsStarted, 'STARTTLS must not be issued on an implicit-TLS connection.');
        self::assertNotContains('STARTTLS', $transport->commands());
    }

    /**
     * A relay advertises different capabilities once the connection is private — AUTH is
     * commonly absent beforehand precisely so clients do not offer credentials in the clear.
     * The post-TLS EHLO must replace the earlier list, not merge into it.
     */
    public function testTheSecondEhloReplacesTheExtensionList(): void
    {
        $transport = new ScriptedTransport([
            '220 ready',
            '250-Hello',
            '250 STARTTLS',
            '220 Go ahead',
            '250-Hello',
            '250 AUTH PLAIN',
            '235 Authenticated',
            '250 OK',
            '250 Accepted',
            '354 Go ahead',
            '250 Ok',
        ]);

        // AUTH appears only after TLS; the send succeeds because the second list is used.
        self::smtp($transport)->send(self::message());

        self::assertContains('AUTH PLAIN ' . base64_encode("\0user\0secret-password"), $transport->commands());
    }

    public function testNamesEveryRecipientIncludingBccInTheEnvelope(): void
    {
        $transport = new ScriptedTransport(self::fullScript(recipients: 3));

        self::smtp($transport)->send(self::message(
            to: [new Address('to@example.com')],
            cc: [new Address('cc@example.com')],
            bcc: [new Address('blind@example.com')],
        ));

        $commands = $transport->commands();
        self::assertContains('RCPT TO:<to@example.com>', $commands);
        self::assertContains('RCPT TO:<cc@example.com>', $commands);
        self::assertContains('RCPT TO:<blind@example.com>', $commands);

        // §2.12, both halves visible at once: the blind recipient is in the envelope and
        // nowhere in the transmitted document.
        $conversation = $transport->conversation();
        $body = substr($conversation, (int) strpos($conversation, 'DATA'));

        self::assertStringNotContainsString('blind@example.com', $body);
        self::assertStringNotContainsStringIgnoringCase("\r\nBcc:", $body);
    }

    public function testTerminatesTheBodyWithADotOnItsOwnLine(): void
    {
        $transport = new ScriptedTransport(self::fullScript());

        self::smtp($transport)->send(self::message());

        self::assertStringEndsWith("\r\n.\r\nQUIT\r\n", $transport->conversation());
    }

    public function testDotStuffsABodyLineBeginningWithADot(): void
    {
        // MimeMessage base64-encodes bodies, so a raw dot cannot reach the wire today. The
        // guard is asserted directly rather than left to a future encoder change.
        $stuff = new \ReflectionMethod(Smtp::class, 'stuff');

        self::assertSame("..hidden", $stuff->invoke(null, '.hidden'));
        self::assertSame("a\r\n..b", $stuff->invoke(null, "a\r\n.b"));
        self::assertSame("a\r\nb", $stuff->invoke(null, "a\r\nb"));
    }

    public function testARefusedRecipientAbandonsTheWholeMessage(): void
    {
        $transport = new ScriptedTransport([
            ...self::script(),
            '235 Authenticated',
            '250 OK',
            '250 Accepted',
            '550 No such user here',
        ]);

        try {
            self::smtp($transport)->send(self::message(
                to: [new Address('good@example.com'), new Address('nobody@example.com')],
            ));
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
            self::assertStringContainsString('nobody@example.com', $e->getMessage());
            self::assertStringContainsString('No part of the message was sent', $e->getMessage());

            // DATA must never have been issued — a partial send is what would create the
            // duplicate on a later failover.
            self::assertNotContains('DATA', $transport->commands());
            self::assertTrue($transport->closed);
        }
    }

    /** §2.4: bad credentials are exactly when another mailer should take the message. */
    public function testRejectedCredentialsAreTheMailersFault(): void
    {
        $transport = new ScriptedTransport([
            ...self::script(),
            '535 5.7.8 Authentication credentials invalid',
        ]);

        try {
            self::smtp($transport)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('rejected the credentials', $e->getMessage());
        }
    }

    /**
     * Security-critical: AUTH PLAIN transmits base64 of the password, so an adapter that
     * reported the command it sent would write the password into every log that caught this.
     */
    public function testAFailedAuthenticationNeverLeaksTheCredential(): void
    {
        $transport = new ScriptedTransport([
            ...self::script(),
            '535 Authentication failed',
        ]);

        try {
            self::smtp($transport)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            $rendered = $e->getMessage() . ' ' . $e->getTraceAsString();

            self::assertStringNotContainsString('secret-password', $rendered);
            self::assertStringNotContainsString(base64_encode('secret-password'), $rendered);
            self::assertStringNotContainsString(base64_encode("\0user\0secret-password"), $rendered);
        }
    }

    public function testATransientFailureIsTheMailersFault(): void
    {
        $transport = new ScriptedTransport([
            ...self::script(),
            '235 Authenticated',
            '421 4.7.0 Too many connections, try again later',
        ]);

        try {
            self::smtp($transport)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
        }
    }

    /** Size limits are near-universal, so the next relay would refuse it too. */
    public function testAnOversizeMessageIsTheMessagesFault(): void
    {
        $transport = new ScriptedTransport([
            ...self::script(),
            '235 Authenticated',
            '250 OK',
            '250 Accepted',
            '354 Go ahead',
            '552 Message size exceeds fixed limit',
        ]);

        try {
            self::smtp($transport)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
        }
    }

    public function testARejectedSenderIsTheMessagesFault(): void
    {
        $transport = new ScriptedTransport([
            ...self::script(),
            '235 Authenticated',
            '550 Sender address rejected',
        ]);

        try {
            self::smtp($transport)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
        }
    }

    /** A content rejection after DATA varies by relay, so it takes §2.4's unrecognised default. */
    public function testAContentRejectionAfterDataIsTheMailersFault(): void
    {
        $transport = new ScriptedTransport([
            ...self::script(),
            '235 Authenticated',
            '250 OK',
            '250 Accepted',
            '354 Go ahead',
            '554 5.7.1 Message rejected as spam',
        ]);

        try {
            self::smtp($transport)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
        }
    }

    public function testAHangUpMidConversationIsTheMailersFault(): void
    {
        $transport = new ScriptedTransport(['220 ready']);

        try {
            self::smtp($transport)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertTrue($transport->closed, 'The socket must be released even here.');
        }
    }

    public function testAnUnparsableReplyIsRejectedRatherThanGuessedAt(): void
    {
        $transport = new ScriptedTransport(['220 ready', 'not an smtp reply']);

        try {
            self::smtp($transport)->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('could not parse', $e->getMessage());
        }
    }

    public function testSkipsAuthenticationEntirelyWhenNoCredentialsAreConfigured(): void
    {
        $transport = new ScriptedTransport([
            '220 ready',
            '250 Hello',
            '250 OK',
            '250 Accepted',
            '354 Go ahead',
            '250 Ok',
        ]);

        self::smtp($transport, username: null, password: null, encryption: SmtpEncryption::None)
            ->send(self::message());

        foreach ($transport->commands() as $command) {
            self::assertStringNotContainsString('AUTH', $command);
        }
    }

    public function testUsesAnExplicitEhloDomainWhenGiven(): void
    {
        $transport = new ScriptedTransport(self::fullScript());

        self::smtp($transport, ehloDomain: 'relay.internal')->send(self::message());

        self::assertContains('EHLO relay.internal', $transport->commands());
    }

    /** Most relays name no queue id at all, and null is the honest answer. */
    public function testAReplyWithNoQueueIdYieldsANullMessageId(): void
    {
        $transport = new ScriptedTransport([
            ...self::script(),
            '235 Authenticated',
            '250 OK',
            '250 Accepted',
            '354 Go ahead',
            '250 Ok',
        ]);

        self::assertNull(self::smtp($transport)->send(self::message())->providerMessageId);
    }

    /**
     * `SIZE 35882577` is one capability with a limit, not two capabilities. Parsing the
     * flattened reply text rather than its lines would register the number as a capability
     * of its own, which reads fine until someone adds a SIZE check.
     */
    public function testParsesAnExtensionsParametersRatherThanTreatingThemAsExtensions(): void
    {
        $transport = new ScriptedTransport([
            '220 ready',
            '250-mail.example.com Hello',
            '250-SIZE 35882577',
            '250-STARTTLS',
            '250 AUTH=PLAIN LOGIN',
            '220 Go ahead',
            '250-Hello',
            '250 AUTH=PLAIN LOGIN',
            '235 Authenticated',
            '250 OK',
            '250 Accepted',
            '354 Go ahead',
            '250 Ok',
        ]);

        // The AUTH=PLAIN form is the older advertisement; PLAIN must still be recognised, and
        // SIZE's limit must not have been mistaken for a mechanism on the way.
        self::smtp($transport)->send(self::message());

        self::assertContains(
            'AUTH PLAIN ' . base64_encode("\0user\0secret-password"),
            $transport->commands()
        );

        $parse = new \ReflectionMethod(Smtp::class, 'parseExtensions');

        self::assertSame(
            [
                'SIZE' => ['35882577'],
                'STARTTLS' => [],
                'AUTH' => ['PLAIN', 'LOGIN'],
            ],
            $parse->invoke(null, [
                'mail.example.com Hello',
                'SIZE 35882577',
                'STARTTLS',
                'AUTH=PLAIN LOGIN',
            ])
        );
    }

    public function testRefusesAUsernameWithoutAPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both a username and a password, or neither');

        new Smtp('mail.example.com', username: 'user');
    }

    public function testRefusesAnEmptyHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a host');

        new Smtp('  ');
    }

    public function testRefusesAnImpossiblePort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port must be 1-65535');

        new Smtp('mail.example.com', 70000);
    }
}
