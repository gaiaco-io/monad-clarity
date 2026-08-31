<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\MailAdapters;

use ArrayObject;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\MailAdapters\AmazonSes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AmazonSesTest extends TestCase
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

    public function testSendsSimpleContentAndParsesTheMessageId(): void
    {
        $client = new FakeSesV2Client(['MessageId' => '0100018f-abc']);
        $sent = (new AmazonSes($client))->send(self::message(html: '<p>Hello</p>'));

        $args = $client->lastArguments;

        self::assertSame('App <app@example.com>', $args['FromEmailAddress']);
        self::assertSame(['Someone <someone@example.com>'], $args['Destination']['ToAddresses']);
        self::assertArrayNotHasKey('CcAddresses', $args['Destination']);

        self::assertSame(
            ['Data' => 'Hello', 'Charset' => 'UTF-8'],
            $args['Content']['Simple']['Subject']
        );
        self::assertSame(
            ['Data' => 'Hello there.', 'Charset' => 'UTF-8'],
            $args['Content']['Simple']['Body']['Text']
        );
        self::assertSame(
            ['Data' => '<p>Hello</p>', 'Charset' => 'UTF-8'],
            $args['Content']['Simple']['Body']['Html']
        );
        self::assertArrayNotHasKey('Raw', $args['Content']);

        self::assertSame('amazon_ses', $sent->mailer);
        self::assertSame('0100018f-abc', $sent->providerMessageId);
    }

    public function testSplitsEveryRecipientFieldIntoTheDestination(): void
    {
        $client = new FakeSesV2Client(['MessageId' => 'id']);

        (new AmazonSes($client))->send(self::message(
            to: [new Address('to@example.com')],
            cc: [new Address('cc@example.com')],
            bcc: [new Address('blind@example.com')],
            replyTo: new Address('support@example.com'),
        ));

        $destination = $client->lastArguments['Destination'];

        self::assertSame(['to@example.com'], $destination['ToAddresses']);
        self::assertSame(['cc@example.com'], $destination['CcAddresses']);
        self::assertSame(['blind@example.com'], $destination['BccAddresses']);
        self::assertSame(['support@example.com'], $client->lastArguments['ReplyToAddresses']);
    }

    /** Simple content cannot carry an attachment, so the whole message becomes RFC 5322. */
    public function testAttachmentsForceRawMimeContent(): void
    {
        $client = new FakeSesV2Client(['MessageId' => 'id']);

        (new AmazonSes($client))->send(self::message(
            attachments: [new Attachment('invoice.pdf', 'application/pdf', '%PDF-1.4')],
        ));

        $content = $client->lastArguments['Content'];

        self::assertArrayNotHasKey('Simple', $content);

        $raw = $content['Raw']['Data'];
        self::assertStringContainsString("Subject: Hello\r\n", $raw);
        self::assertStringContainsString(base64_encode('%PDF-1.4'), $raw);
    }

    /**
     * Simple.Headers is narrower than RFC 5322, so a message carrying custom headers takes
     * the Raw path too — one fidelity guarantee rather than two partial ones.
     */
    public function testCustomHeadersAlsoForceRawMimeContent(): void
    {
        $client = new FakeSesV2Client(['MessageId' => 'id']);

        (new AmazonSes($client))->send(self::message(headers: ['X-Campaign' => 'spring']));

        $raw = $client->lastArguments['Content']['Raw']['Data'];

        self::assertStringContainsString("X-Campaign: spring\r\n", $raw);
    }

    /** §2.12 holds on this path too: the Raw document carries no Bcc header. */
    public function testTheRawDocumentNeverCarriesABccHeader(): void
    {
        $client = new FakeSesV2Client(['MessageId' => 'id']);

        (new AmazonSes($client))->send(self::message(
            bcc: [new Address('blind@example.com')],
            attachments: [new Attachment('a.txt', 'text/plain', 'data')],
        ));

        $raw = $client->lastArguments['Content']['Raw']['Data'];
        $headerBlock = substr($raw, 0, (int) strpos($raw, "\r\n\r\n"));

        self::assertStringNotContainsStringIgnoringCase('bcc', $headerBlock);
        self::assertStringNotContainsString('blind@example.com', $raw);

        // The envelope still names it — SES delivers from Destination, not from the document.
        self::assertSame(['blind@example.com'], $client->lastArguments['Destination']['BccAddresses']);
    }

    public function testSendsTagsAndAConfigurationSet(): void
    {
        $client = new FakeSesV2Client(['MessageId' => 'id']);

        (new AmazonSes($client, 'my-config-set'))->send(self::message(tags: ['welcome', 'onboarding']));

        self::assertSame('my-config-set', $client->lastArguments['ConfigurationSetName']);
        self::assertSame(
            [
                ['Name' => 'welcome', 'Value' => 'welcome'],
                ['Name' => 'onboarding', 'Value' => 'onboarding'],
            ],
            $client->lastArguments['EmailTags']
        );
    }

    public function testOmitsTheConfigurationSetWhenNoneIsConfigured(): void
    {
        $client = new FakeSesV2Client(['MessageId' => 'id']);

        (new AmazonSes($client))->send(self::message());

        self::assertArrayNotHasKey('ConfigurationSetName', $client->lastArguments);
    }

    /**
     * A tag the other five mailers accept and SES cannot. Refused here with the reason,
     * rather than surfacing as an opaque InvalidParameterValue from AWS.
     */
    public function testRefusesATagSesCannotExpress(): void
    {
        $client = new FakeSesV2Client(['MessageId' => 'id']);

        try {
            (new AmazonSes($client))->send(self::message(tags: ['welcome email']));
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
            self::assertStringContainsString('welcome email', $e->getMessage());
            self::assertFalse($client->wasCalled, 'Refused before reaching AWS.');
        }
    }

    public function testReadsAMessageIdFromAnArrayAccessResult(): void
    {
        $client = new FakeSesV2Client(new ArrayObject(['MessageId' => 'from-array-access']));

        self::assertSame(
            'from-array-access',
            (new AmazonSes($client))->send(self::message())->providerMessageId
        );
    }

    public function testSurvivesAResultCarryingNoMessageId(): void
    {
        $client = new FakeSesV2Client([]);

        self::assertNull((new AmazonSes($client))->send(self::message())->providerMessageId);
    }

    /** §2.4: rejected credentials are exactly when another mailer should take over. */
    public function testRejectedCredentialsAreTheMailersFault(): void
    {
        $client = new FakeSesV2Client(throws: new FakeAwsException(
            'The security token included in the request is invalid.',
            'UnrecognizedClientException'
        ));

        try {
            (new AmazonSes($client))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('UnrecognizedClientException', $e->getMessage());
            self::assertInstanceOf(FakeAwsException::class, $e->getPrevious());
        }
    }

    public function testAnInvalidParameterIsTheMessagesFault(): void
    {
        $client = new FakeSesV2Client(throws: new FakeAwsException(
            'Local address contains control or whitespace',
            'InvalidParameterValue'
        ));

        try {
            (new AmazonSes($client))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
        }
    }

    public function testThrottlingIsTheMailersFault(): void
    {
        $client = new FakeSesV2Client(throws: new FakeAwsException('Maximum sending rate exceeded.', 'Throttling'));

        try {
            (new AmazonSes($client))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
        }
    }

    /**
     * MessageRejected spans a refused body and an unverified sending identity, so it takes
     * §2.4's default for the ambiguous rather than being guessed at.
     */
    public function testMessageRejectedTakesTheAmbiguousDefault(): void
    {
        $client = new FakeSesV2Client(throws: new FakeAwsException('Email address is not verified.', 'MessageRejected'));

        try {
            (new AmazonSes($client))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
        }
    }

    /** A client that is not the SDK at all — no getAwsErrorCode to consult. */
    public function testAnExceptionWithNoAwsErrorCodeIsTheMailersFault(): void
    {
        $client = new FakeSesV2Client(throws: new RuntimeException('Connection reset by peer'));

        try {
            (new AmazonSes($client))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);
            self::assertStringContainsString('Connection reset by peer', $e->getMessage());
        }
    }
}

/**
 * Minimal fake matching the one `Aws\SesV2Client` method AmazonSes calls, in the shape
 * `Tests\Services\FilesTest`'s FakeS3Client established for the S3 adapter — the precedent
 * ReleaseNotes_1.6.0.md §2.14 leans on.
 */
final class FakeSesV2Client
{
    public bool $wasCalled = false;

    /** @var array<string, mixed> */
    public array $lastArguments = [];

    public function __construct(
        private readonly mixed $result = [],
        private readonly ?\Throwable $throws = null,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function sendEmail(array $args): mixed
    {
        $this->wasCalled = true;
        $this->lastArguments = $args;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result;
    }
}

/**
 * Stands in for `Aws\Exception\AwsException`, whose `getAwsErrorCode()` is the only part of
 * it AmazonSes consults — and which it must consult without depending on the SDK.
 */
final class FakeAwsException extends RuntimeException
{
    public function __construct(string $message, private readonly string $awsErrorCode)
    {
        parent::__construct($message);
    }

    public function getAwsErrorCode(): string
    {
        return $this->awsErrorCode;
    }
}
