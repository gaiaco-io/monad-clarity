<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Mail;

use InvalidArgumentException;
use Monad\Clarity\Services\Mail;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attempt;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\MailerPool;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\SentMessage;
use PHPUnit\Framework\TestCase;
use TypeError;

final class MailerPoolTest extends TestCase
{
    private static function message(): Message
    {
        return new Message(
            from: new Address('app@example.com', 'App'),
            to: [new Address('someone@example.com')],
            subject: 'Hello',
            text: 'Hello there.',
        );
    }

    public function testTheFirstMailerTakesItAndTheRestAreNeverCalled(): void
    {
        $first = RecordingMailer::succeeding('postmark', 'pm-1');
        $second = RecordingMailer::succeeding('resend', 're-1');

        $sent = (new MailerPool([$first, $second]))->send(self::message());

        self::assertSame('postmark', $sent->mailer);
        self::assertSame('pm-1', $sent->providerMessageId);
        self::assertSame(1, $first->calls);
        self::assertSame(0, $second->calls, 'A healthy primary must end the search.');

        self::assertFalse($sent->failedOver());
        self::assertCount(1, $sent->attempts);
        self::assertTrue($sent->attempts[0]->succeeded);
    }

    public function testAMailerFaultAdvancesAndRecordsBothAttempts(): void
    {
        $first = RecordingMailer::failing('postmark', MailException::mailer('503 from the provider.'));
        $second = RecordingMailer::succeeding('resend', 're-1');

        $sent = (new MailerPool([$first, $second]))->send(self::message());

        self::assertSame('resend', $sent->mailer);
        self::assertSame('re-1', $sent->providerMessageId);
        self::assertSame(1, $second->calls);

        self::assertTrue($sent->failedOver());
        self::assertCount(2, $sent->attempts);

        self::assertSame('postmark', $sent->attempts[0]->mailer);
        self::assertFalse($sent->attempts[0]->succeeded);
        self::assertSame('503 from the provider.', $sent->attempts[0]->reason());

        self::assertSame('resend', $sent->attempts[1]->mailer);
        self::assertTrue($sent->attempts[1]->succeeded);

        // The failures are also reachable on their own, which is what alerting reads.
        self::assertCount(1, $sent->failures());
    }

    /** §2.4: bad credentials on one provider are exactly when the next should get its turn. */
    public function testAnAuthenticationFailureFailsOver(): void
    {
        $first = RecordingMailer::failing('mailgun', MailException::mailer('mailgun rejected the credentials.'));
        $second = RecordingMailer::succeeding('smtp', null);

        $sent = (new MailerPool([$first, $second]))->send(self::message());

        self::assertSame('smtp', $sent->mailer);
        self::assertNull($sent->providerMessageId);
    }

    /**
     * The other half of §2.4, and the one that costs real latency if it is wrong: a message
     * every mailer would refuse must not be offered to every mailer.
     */
    public function testAMessageFaultStopsImmediatelyAndTheNextIsNeverCalled(): void
    {
        $first = RecordingMailer::failing('postmark', MailException::message('Recipient address is invalid.'));
        $second = RecordingMailer::succeeding('resend', 're-1');

        try {
            (new MailerPool([$first, $second]))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
            self::assertSame(0, $second->calls, 'A message fault must not be offered to the next mailer.');
            self::assertStringContainsString('Recipient address is invalid.', $e->getMessage());
            self::assertStringContainsString('remaining mailer was not tried', $e->getMessage());
        }
    }

    public function testAMessageFaultNamesHowManyWereSkipped(): void
    {
        $pool = new MailerPool([
            RecordingMailer::failing('postmark', MailException::message('No recipients.')),
            RecordingMailer::succeeding('resend', 'a'),
            RecordingMailer::succeeding('smtp', 'b'),
        ]);

        try {
            $pool->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertStringContainsString('remaining 2 mailers were not tried', $e->getMessage());
        }
    }

    /**
     * The untried count alone is misleading once a mailer has already failed over: on a
     * three-member pool it reads as though the pool skipped one for no reason.
     */
    public function testAMessageFaultAlsoNamesTheMailersThatFailedBeforeIt(): void
    {
        $pool = new MailerPool([
            RecordingMailer::failing('postmark', MailException::mailer('503 Service Unavailable')),
            RecordingMailer::failing('resend', MailException::message('Recipient address is invalid.')),
            RecordingMailer::succeeding('smtp', 's-1'),
        ]);

        try {
            $pool->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Message, $e->scope);
            self::assertStringContainsString('resend refused the message itself', $e->getMessage());
            self::assertStringContainsString('remaining mailer was not tried', $e->getMessage());
            self::assertStringContainsString('1 earlier mailer had already failed', $e->getMessage());
        }
    }

    public function testEveryMailerFailingRaisesNamingAllOfThem(): void
    {
        $first = RecordingMailer::failing('postmark', MailException::mailer('503 Service Unavailable'));
        $second = RecordingMailer::failing('resend', MailException::mailer('Connection timed out'));
        $third = RecordingMailer::failing('smtp', MailException::mailer('Connection refused'));

        try {
            (new MailerPool([$first, $second, $third]))->send(self::message());
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            self::assertSame(FailureScope::Mailer, $e->scope);

            self::assertStringContainsString('All 3 mailers in the pool failed', $e->getMessage());
            self::assertStringContainsString('postmark: 503 Service Unavailable', $e->getMessage());
            self::assertStringContainsString('resend: Connection timed out', $e->getMessage());
            self::assertStringContainsString('smtp: Connection refused', $e->getMessage());

            // The primary's failure is the cause, since the primary is the one whose health
            // the operator is being told about.
            self::assertSame('503 Service Unavailable', $e->getPrevious()?->getMessage());

            self::assertSame(1, $first->calls);
            self::assertSame(1, $second->calls);
            self::assertSame(1, $third->calls);
        }
    }

    /**
     * The pool's version of the try-narrowing in the adapters: a bug inside a mailer is not
     * a delivery failure, and failing it over would turn one broken adapter into a defect
     * that never surfaces until every member is broken.
     */
    public function testABugInAMailerIsNotSwallowedAsAFailover(): void
    {
        $broken = RecordingMailer::throwing('postmark', new TypeError('Argument #1 must be of type string'));
        $second = RecordingMailer::succeeding('resend', 're-1');

        try {
            (new MailerPool([$broken, $second]))->send(self::message());
            self::fail('Expected the TypeError to propagate.');
        } catch (TypeError $e) {
            self::assertStringContainsString('must be of type string', $e->getMessage());
            self::assertSame(0, $second->calls, 'A bug must not be failed over.');
        }
    }

    public function testRefusesAnEmptyPoolAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs at least one mailer');

        new MailerPool([]);
    }

    public function testRefusesAMemberThatIsNotAMailer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a Services\Mail');

        new MailerPool([RecordingMailer::succeeding('postmark', 'a'), 'not-a-mailer']);
    }

    /** §2.2: a primary and a standby account at one provider is a legitimate pool. */
    public function testAllowsTwoMembersSharingAName(): void
    {
        $primary = RecordingMailer::failing('postmark', MailException::mailer('Rate limited.'));
        $standby = RecordingMailer::succeeding('postmark', 'pm-2');

        $sent = (new MailerPool([$primary, $standby]))->send(self::message());

        self::assertSame('postmark', $sent->mailer);
        self::assertCount(2, $sent->attempts);
        self::assertSame(['postmark', 'postmark'], array_map(
            static fn (Attempt $a): string => $a->mailer,
            $sent->attempts
        ));
    }

    public function testAPoolIsItselfAMailer(): void
    {
        $pool = new MailerPool([RecordingMailer::succeeding('postmark', 'a')]);

        self::assertInstanceOf(Mail::class, $pool);
        self::assertSame('pool(postmark)', $pool->mailerName());
    }

    public function testNamesItselfAfterItsMembers(): void
    {
        $pool = new MailerPool([
            RecordingMailer::succeeding('postmark', 'a'),
            RecordingMailer::succeeding('resend', 'b'),
        ]);

        self::assertSame('pool(postmark+resend)', $pool->mailerName());
        self::assertCount(2, $pool->mailers());
    }

    /**
     * A nested pool must not flatten into an opaque success: the leaf that really sent stays
     * the mailer, and the inner trail survives into the outer one.
     */
    public function testANestedPoolKeepsTheLeafMailerAndTheWholeTrail(): void
    {
        $inner = new MailerPool([
            RecordingMailer::failing('resend', MailException::mailer('Resend is down.')),
            RecordingMailer::succeeding('smtp', 'smtp-1'),
        ]);

        $outer = new MailerPool([
            RecordingMailer::failing('postmark', MailException::mailer('Postmark is down.')),
            $inner,
        ]);

        $sent = $outer->send(self::message());

        self::assertSame('smtp', $sent->mailer, 'The leaf that sent, not the pool that delegated.');
        self::assertSame('smtp-1', $sent->providerMessageId);

        self::assertSame(
            ['postmark', 'resend', 'smtp'],
            array_map(static fn (Attempt $a): string => $a->mailer, $sent->attempts)
        );
        self::assertTrue($sent->failedOver());
        self::assertCount(2, $sent->failures());
    }

    /** A failing inner pool is recorded under its composed name, not a bare "pool". */
    public function testAFailingNestedPoolIsRecordedByItsComposedName(): void
    {
        $inner = new MailerPool([
            RecordingMailer::failing('resend', MailException::mailer('Resend is down.')),
        ]);

        $sent = (new MailerPool([$inner, RecordingMailer::succeeding('smtp', 's-1')]))
            ->send(self::message());

        self::assertSame('smtp', $sent->mailer);
        self::assertSame('pool(resend)', $sent->attempts[0]->mailer);
        self::assertFalse($sent->attempts[0]->succeeded);
    }

    public function testKeepsTheWinnersRawResponse(): void
    {
        $mailer = RecordingMailer::succeeding('resend', 're-1', ['id' => 're-1', 'extra' => true]);

        $sent = (new MailerPool([$mailer]))->send(self::message());

        self::assertSame(['id' => 're-1', 'extra' => true], $sent->raw);
    }

    public function testKeepsTheTrailWhenAMemberReturnsNoneOfItsOwn(): void
    {
        $mailer = new RecordingMailer(
            'legacy',
            static fn (): SentMessage => new SentMessage('legacy', 'l-1', [])
        );

        $sent = (new MailerPool([$mailer]))->send(self::message());

        self::assertCount(1, $sent->attempts);
        self::assertSame('legacy', $sent->attempts[0]->mailer);
        self::assertTrue($sent->attempts[0]->succeeded);
    }

    public function testHandsEveryMailerTheSameMessage(): void
    {
        $message = self::message();

        $first = RecordingMailer::failing('postmark', MailException::mailer('down'));
        $second = RecordingMailer::succeeding('resend', 're-1');

        (new MailerPool([$first, $second]))->send($message);

        self::assertSame($message, $first->lastMessage);
        self::assertSame($message, $second->lastMessage);
    }
}

/**
 * A mailer whose outcome is scripted, recording how many times it was asked and with what —
 * the pool's behaviour is entirely about *which* members get called, so counting calls is
 * most of what these tests assert.
 */
final class RecordingMailer extends Mail
{
    public int $calls = 0;
    public ?Message $lastMessage = null;

    /** @var callable(): SentMessage */
    private $outcome;

    public function __construct(private readonly string $name, callable $outcome)
    {
        $this->outcome = $outcome;
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function succeeding(string $name, ?string $messageId, array $raw = []): self
    {
        return new self($name, static fn (): SentMessage => SentMessage::delivered($name, $messageId, $raw));
    }

    public static function failing(string $name, MailException $failure): self
    {
        return new self($name, static fn (): SentMessage => throw $failure);
    }

    public static function throwing(string $name, \Throwable $failure): self
    {
        return new self($name, static fn (): SentMessage => throw $failure);
    }

    public function mailerName(): string
    {
        return $this->name;
    }

    public function send(Message $message): SentMessage
    {
        $this->calls++;
        $this->lastMessage = $message;

        return ($this->outcome)();
    }
}
