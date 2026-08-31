<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Mail;

use Monad\Clarity\Services\Mail\Attempt;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\SentMessage;
use PHPUnit\Framework\TestCase;

final class SentMessageTest extends TestCase
{
    public function testDeliveredRecordsTheSuccessfulAttempt(): void
    {
        $sent = SentMessage::delivered('postmark', 'abc-123', ['MessageID' => 'abc-123']);

        self::assertSame('postmark', $sent->mailer);
        self::assertSame('abc-123', $sent->providerMessageId);
        self::assertSame(['MessageID' => 'abc-123'], $sent->raw);

        // The trail always includes the winner, so a single-adapter send counts one.
        self::assertCount(1, $sent->attempts);
        self::assertTrue($sent->attempts[0]->succeeded);
        self::assertSame('postmark', $sent->attempts[0]->mailer);

        self::assertFalse($sent->failedOver());
        self::assertSame([], $sent->failures());
    }

    public function testFailedOverExposesThePassedOverMailers(): void
    {
        $refused = MailException::mailer('mailgun rejected the credentials.');

        $sent = new SentMessage('resend', 're_1', [
            Attempt::failed('mailgun', $refused),
            Attempt::succeeded('resend'),
        ]);

        self::assertTrue($sent->failedOver());
        self::assertCount(2, $sent->attempts);

        $failures = $sent->failures();
        self::assertCount(1, $failures);
        self::assertSame('mailgun', $failures[0]->mailer);
        self::assertSame('mailgun rejected the credentials.', $failures[0]->reason());
    }

    public function testSucceededAttemptHasNoReason(): void
    {
        self::assertNull(Attempt::succeeded('resend')->reason());
    }

    public function testAcceptsAbsentProviderMessageId(): void
    {
        self::assertNull(SentMessage::delivered('smtp', null)->providerMessageId);
    }

    public function testMailExceptionCarriesItsScope(): void
    {
        $mailerFault = MailException::mailer('503 from the provider.');
        $messageFault = MailException::message('Recipient address is invalid.');

        self::assertSame(FailureScope::Mailer, $mailerFault->scope);
        self::assertTrue($mailerFault->isMailerFault());

        self::assertSame(FailureScope::Message, $messageFault->scope);
        self::assertFalse($messageFault->isMailerFault());
    }

    public function testMailExceptionKeepsItsCause(): void
    {
        $cause = new \RuntimeException('cURL error 28');
        $failure = MailException::mailer('Timed out.', $cause);

        self::assertSame($cause, $failure->getPrevious());
    }
}
