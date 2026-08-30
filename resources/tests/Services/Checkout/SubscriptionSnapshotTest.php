<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Checkout;

use DateTimeImmutable;
use Monad\Clarity\Services\Checkout\ScheduledChange;
use Monad\Clarity\Services\Checkout\ScheduledChangeAction;
use Monad\Clarity\Services\Checkout\SubscriptionSnapshot;
use Monad\Clarity\Services\Checkout\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

final class SubscriptionSnapshotTest extends TestCase
{
    private const PERIOD_END = '2026-09-30T00:00:00+00:00';

    /**
     * The bug this class exists to make impossible. A customer who has cancelled keeps the
     * period they paid for: the status stays Active and a cancellation is pending. Anything
     * that reads "a scheduled change exists" as "revoke now" takes away paid-for service.
     */
    public function testACancellingSubscriptionIsStillActive(): void
    {
        $snapshot = $this->snapshot(
            SubscriptionStatus::Active,
            new ScheduledChange(ScheduledChangeAction::Cancel, new DateTimeImmutable(self::PERIOD_END))
        );

        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
        self::assertTrue($snapshot->isCancelling());
        self::assertFalse($snapshot->status->isTerminal(), 'A pending cancellation has not ended anything yet.');
    }

    public function testACancellationThatHasTakenEffectIsTerminal(): void
    {
        $snapshot = $this->snapshot(SubscriptionStatus::Cancelled);

        self::assertTrue($snapshot->status->isTerminal());
        self::assertFalse($snapshot->isCancelling(), 'It has already cancelled; nothing is pending.');
    }

    public function testAPausingSubscriptionIsDistinguishedFromACancellingOne(): void
    {
        $snapshot = $this->snapshot(
            SubscriptionStatus::Active,
            new ScheduledChange(
                ScheduledChangeAction::Pause,
                new DateTimeImmutable(self::PERIOD_END),
                new DateTimeImmutable('2026-12-01T00:00:00+00:00')
            )
        );

        self::assertTrue($snapshot->isPausing());
        self::assertFalse($snapshot->isCancelling());
    }

    public function testNothingIsPendingWhenThereIsNoScheduledChange(): void
    {
        $snapshot = $this->snapshot(SubscriptionStatus::Active);

        self::assertFalse($snapshot->isCancelling());
        self::assertFalse($snapshot->isPausing());
    }

    public function testAccessEndsOnTheScheduledDateWhenACancellationIsPending(): void
    {
        $snapshot = $this->snapshot(
            SubscriptionStatus::Active,
            new ScheduledChange(ScheduledChangeAction::Cancel, new DateTimeImmutable(self::PERIOD_END))
        );

        self::assertEquals(new DateTimeImmutable(self::PERIOD_END), $snapshot->accessEndsAt());
    }

    public function testAccessEndsAtThePeriodEndWhenNothingIsScheduled(): void
    {
        $periodEnd = new DateTimeImmutable('2026-10-15T00:00:00+00:00');

        $snapshot = new SubscriptionSnapshot(
            gateway: 'paddle_subscription',
            gatewayReference: 'sub_test_123',
            status: SubscriptionStatus::Active,
            currentPeriodEndsAt: $periodEnd,
        );

        self::assertEquals($periodEnd, $snapshot->accessEndsAt());
    }

    /**
     * An already-cancelled or indefinitely paused subscription has no future date, and null
     * is the honest answer rather than a date invented to fill the field.
     */
    public function testAccessEndsAtIsNullWhenTheGatewayReportedNoDateAtAll(): void
    {
        self::assertNull($this->snapshot(SubscriptionStatus::Cancelled)->accessEndsAt());
    }

    /**
     * A scheduled resume is not an ending, so it must not displace the period end.
     */
    public function testAScheduledResumeDoesNotChangeWhenAccessEnds(): void
    {
        $periodEnd = new DateTimeImmutable('2026-10-15T00:00:00+00:00');

        $snapshot = new SubscriptionSnapshot(
            gateway: 'paddle_subscription',
            gatewayReference: 'sub_test_123',
            status: SubscriptionStatus::Paused,
            scheduledChange: new ScheduledChange(
                ScheduledChangeAction::Resume,
                new DateTimeImmutable('2026-09-20T00:00:00+00:00')
            ),
            currentPeriodEndsAt: $periodEnd,
        );

        self::assertEquals($periodEnd, $snapshot->accessEndsAt());
    }

    private function snapshot(SubscriptionStatus $status, ?ScheduledChange $scheduledChange = null): SubscriptionSnapshot
    {
        return new SubscriptionSnapshot(
            gateway: 'paddle_subscription',
            gatewayReference: 'sub_test_123',
            status: $status,
            scheduledChange: $scheduledChange,
        );
    }
}
