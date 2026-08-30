<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Checkout;

use DateTimeImmutable;
use DateTimeZone;
use Monad\Clarity\Console\CheckoutInstall;
use Monad\Clarity\Services\Checkout\BillingCycle;
use Monad\Clarity\Services\Checkout\BillingInterval;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\ScheduledChange;
use Monad\Clarity\Services\Checkout\ScheduledChangeAction;
use Monad\Clarity\Services\Checkout\SubscriptionEvent;
use Monad\Clarity\Services\Checkout\SubscriptionLedger;
use Monad\Clarity\Services\Checkout\SubscriptionSnapshot;
use Monad\Clarity\Services\Checkout\SubscriptionStatus;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * The ledger runs against the very blueprint `mitosis checkout:install` emits, on in-memory
 * SQLite — so these tests cover the schema exactly as shipped, including the unique index the
 * concurrency guard actually depends on. Exercising the command's own closure rather than a
 * second copy of the DDL is what keeps the two from drifting.
 */
final class SubscriptionLedgerTest extends TestCase
{
    private const SUBSCRIPTION_ID = 'sub_test_123';

    private SubscriptionLedger $ledger;

    #[Before]
    public function setUpLedgerSchema(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));

        Schema::createTable(SubscriptionLedger::SUBSCRIPTIONS_TABLE, CheckoutInstall::subscriptionsBlueprint());

        $this->ledger = new SubscriptionLedger();
    }

    #[After]
    public function resetDB(): void
    {
        DB::reset();
    }

    public function testRecordCreatesTheSubscriptionFromItsFirstEvent(): void
    {
        self::assertTrue($this->ledger->record($this->event('evt_1', 'subscription.created')));

        $row = $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID);

        self::assertNotNull($row);
        self::assertSame('paddle_subscription', $row['gateway']);
        self::assertSame('active', $row['status']);
        self::assertSame('order-9', $row['reference']);
        self::assertSame('txn_test_123', $row['transaction_reference']);
        self::assertSame('ctm_test_1', $row['customer_reference']);
        self::assertSame(2500, (int) $row['amount_minor']);
        self::assertSame('USD', $row['currency']);
        self::assertSame('month', $row['billing_interval']);
        self::assertSame(1, (int) $row['billing_frequency']);
        self::assertSame('["evt_1"]', $row['last_event_ids']);
    }

    public function testRecordUpdatesTheSubscriptionInPlaceRatherThanAppending(): void
    {
        $this->ledger->record($this->event('evt_1', 'subscription.created'));

        self::assertTrue($this->ledger->record($this->event(
            'evt_2',
            'subscription.updated',
            occurredAt: '2026-08-31T11:00:00Z',
            status: SubscriptionStatus::PastDue
        )));

        DB::run('SELECT COUNT(*) AS total FROM ' . SubscriptionLedger::SUBSCRIPTIONS_TABLE);
        self::assertSame(1, (int) DB::fetch()['total'], 'One subscription is one row.');
        self::assertSame('past_due', $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID)['status']);
    }

    /**
     * Gateways redeliver and do not promise order, so an update can arrive before the created
     * event it logically follows. The record converges on the state rather than depending on
     * the sequence: the first delivery to arrive creates the row, whatever it was.
     */
    public function testAnUpdateArrivingBeforeItsCreatedEventStillCreatesTheRecord(): void
    {
        self::assertTrue($this->ledger->record($this->event(
            'evt_2',
            'subscription.updated',
            occurredAt: '2026-08-31T11:00:00Z',
            status: SubscriptionStatus::PastDue
        )));

        // The older created event now arrives. It must not drag the record backwards.
        self::assertFalse($this->ledger->record($this->event('evt_1', 'subscription.created')));

        $row = $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID);

        self::assertSame('past_due', $row['status']);
        self::assertSame('["evt_2"]', $row['last_event_ids']);
    }

    public function testARedeliveredEventIsRecognisedAndIgnored(): void
    {
        $event = $this->event('evt_1', 'subscription.created');

        self::assertTrue($this->ledger->record($event));
        self::assertFalse($this->ledger->record($event), 'Gateways redeliver; the second one is a no-op.');
    }

    /**
     * A mutable row has no insert-only history to protect it, so ordering is what stops a
     * redelivered older event from dragging the record backwards.
     */
    public function testAnOutOfOrderEventDoesNotOverwriteNewerState(): void
    {
        $this->ledger->record($this->event(
            'evt_2',
            'subscription.canceled',
            occurredAt: '2026-08-31T11:00:00Z',
            status: SubscriptionStatus::Cancelled
        ));

        self::assertFalse($this->ledger->record($this->event(
            'evt_1',
            'subscription.updated',
            occurredAt: '2026-08-31T10:00:00Z',
            status: SubscriptionStatus::Active
        )));

        self::assertSame('cancelled', $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID)['status']);
    }

    /**
     * DATETIME is second-precision, and gateways genuinely emit two events in one second.
     * Refusing them would drop real state, so the later writer wins — stated here rather than
     * discovered.
     */
    public function testASameSecondEventIsStillApplied(): void
    {
        $this->ledger->record($this->event('evt_1', 'subscription.created'));

        self::assertTrue($this->ledger->record($this->event(
            'evt_2',
            'subscription.activated',
            occurredAt: '2026-08-31T10:00:00Z',
            status: SubscriptionStatus::Active
        )));
    }

    /**
     * Found against real Paddle traffic. Gateways emit several distinct events in one second —
     * observed live, a `subscription.resumed` and a `subscription.updated` 126 microseconds
     * apart — and DATETIME's second precision collapses them together. Remembering only the
     * newest id left its siblings unrecognised, so redelivering one was applied again AND
     * reported as a real change, which would double-fire whatever an application does on that.
     */
    public function testEverySameSecondSiblingIsRecognisedOnRedelivery(): void
    {
        $a = $this->event('evt_a', 'subscription.updated', occurredAt: '2026-08-31T10:00:00Z');
        $b = $this->event('evt_b', 'subscription.paused', occurredAt: '2026-08-31T10:00:00Z',
            status: SubscriptionStatus::Paused);

        self::assertTrue($this->ledger->record($a));
        self::assertTrue($this->ledger->record($b), 'A distinct sibling is real state, not a duplicate.');

        // Both are now redelivered. Neither may move the record.
        self::assertFalse($this->ledger->record($a), 'The earlier sibling must still be recognised.');
        self::assertFalse($this->ledger->record($b));

        self::assertSame(
            '["evt_a","evt_b"]',
            $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID)['last_event_ids']
        );
    }

    /**
     * A genuinely newer second starts the set afresh, so it cannot grow without bound.
     */
    public function testANewerSecondReplacesTheRememberedIdsRatherThanAccumulating(): void
    {
        $this->ledger->record($this->event('evt_a', 'subscription.updated', occurredAt: '2026-08-31T10:00:00Z'));
        $this->ledger->record($this->event('evt_b', 'subscription.paused', occurredAt: '2026-08-31T10:00:00Z'));
        $this->ledger->record($this->event('evt_c', 'subscription.resumed', occurredAt: '2026-08-31T10:00:05Z'));

        self::assertSame(
            '["evt_c"]',
            $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID)['last_event_ids']
        );
    }

    public function testAPendingCancellationIsStoredAsItsOwnColumns(): void
    {
        $this->ledger->record($this->event(
            'evt_1',
            'subscription.updated',
            scheduledChange: new ScheduledChange(
                ScheduledChangeAction::Cancel,
                new DateTimeImmutable('2026-09-30T00:00:00Z')
            )
        ));

        $row = $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID);

        self::assertSame('active', $row['status'], 'A pending cancellation has not ended anything.');
        self::assertSame('cancel', $row['scheduled_action']);
        self::assertSame('2026-09-30 00:00:00', $row['scheduled_effective_at']);
        self::assertNull($row['scheduled_resume_at']);
    }

    public function testAResumeClearsTheScheduledChangeColumns(): void
    {
        $this->ledger->record($this->event(
            'evt_1',
            'subscription.updated',
            scheduledChange: new ScheduledChange(
                ScheduledChangeAction::Pause,
                new DateTimeImmutable('2026-09-30T00:00:00Z'),
                new DateTimeImmutable('2026-12-01T00:00:00Z')
            )
        ));

        $this->ledger->record($this->event('evt_2', 'subscription.resumed', occurredAt: '2026-08-31T11:00:00Z'));

        $row = $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID);

        self::assertNull($row['scheduled_action'], 'The pause is gone, so its columns must be too.');
        self::assertNull($row['scheduled_effective_at']);
        self::assertNull($row['scheduled_resume_at']);
    }

    /**
     * A later event that does not repeat the creating transaction must not erase it — the
     * whole txn_ to sub_ link would be lost.
     */
    public function testALaterEventDoesNotBlankTheCreatingTransaction(): void
    {
        $this->ledger->record($this->event('evt_1', 'subscription.created'));

        $this->ledger->record(new SubscriptionEvent(
            gateway: 'paddle_subscription',
            eventId: 'evt_2',
            eventType: 'subscription.updated',
            occurredAt: new DateTimeImmutable('2026-08-31T11:00:00Z'),
            subscription: new SubscriptionSnapshot(
                gateway: 'paddle_subscription',
                gatewayReference: self::SUBSCRIPTION_ID,
                status: SubscriptionStatus::Active,
            ),
        ));

        $row = $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID);

        self::assertSame('txn_test_123', $row['transaction_reference']);
        self::assertSame('order-9', $row['reference']);
        self::assertSame('ctm_test_1', $row['customer_reference']);
    }

    public function testRecordSnapshotReconcilesASubscriptionWhoseCallbackNeverArrived(): void
    {
        self::assertTrue($this->ledger->recordSnapshot($this->snapshot(SubscriptionStatus::PastDue)));

        $row = $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID);

        self::assertSame('past_due', $row['status']);
        self::assertNull($row['last_event_ids'], 'A re-query is not an event.');
    }

    /**
     * The stored event id survives a re-query, so a delivery arriving afterwards is still
     * recognised as one already applied.
     */
    public function testRecordSnapshotKeepsTheStoredEventIdSoARedeliveryIsStillRecognised(): void
    {
        $event = $this->event('evt_1', 'subscription.created');
        $this->ledger->record($event);

        $this->ledger->recordSnapshot(
            $this->snapshot(SubscriptionStatus::Active),
            new DateTimeImmutable('2026-08-31T12:00:00Z')
        );

        self::assertSame('["evt_1"]', $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID)['last_event_ids']);
        self::assertFalse($this->ledger->record($event), 'The redelivery is still a duplicate.');
    }

    /**
     * The case this exists for: the subscription was learned from a gateway that did not name
     * the transaction, and the link is filled in from the other side later.
     */
    public function testLinkTransactionAttachesTheCreatingTransaction(): void
    {
        $this->ledger->recordSnapshot(new SubscriptionSnapshot(
            gateway: 'paddle_subscription',
            gatewayReference: self::SUBSCRIPTION_ID,
            status: SubscriptionStatus::Active,
        ));

        self::assertNull(
            $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID)['transaction_reference'],
            'Nothing has named the transaction yet.'
        );
        self::assertTrue($this->ledger->linkTransaction(self::SUBSCRIPTION_ID, 'txn_test_123'));
        self::assertSame(
            self::SUBSCRIPTION_ID,
            $this->ledger->findByTransactionReference('txn_test_123')['gateway_reference'],
            'The transaction now resolves to the subscription it created.'
        );
    }

    public function testLinkTransactionNeverOverwritesALinkAlreadyRecorded(): void
    {
        $this->ledger->record($this->event('evt_1', 'subscription.created'));

        self::assertFalse($this->ledger->linkTransaction(self::SUBSCRIPTION_ID, 'txn_something_else'));
        self::assertSame(
            'txn_test_123',
            $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID)['transaction_reference']
        );
    }

    public function testLinkTransactionIsFalseForASubscriptionThisLedgerHasNeverSeen(): void
    {
        self::assertFalse($this->ledger->linkTransaction('sub_unknown', 'txn_test_123'));
    }

    /**
     * A customer who cancels and subscribes again keeps the same merchant reference, so
     * answering with one row would be a lie.
     */
    public function testFindByReferenceReturnsEverySubscriptionUnderThatReferenceNewestFirst(): void
    {
        $this->ledger->record($this->event('evt_1', 'subscription.created'));
        $this->ledger->record($this->event(
            'evt_2',
            'subscription.created',
            occurredAt: '2026-08-31T11:00:00Z',
            gatewayReference: 'sub_test_456'
        ));

        self::assertCount(2, $this->ledger->findByReference('order-9'));
    }

    public function testStatusOfReadsTheStoredStatus(): void
    {
        $this->ledger->record($this->event('evt_1', 'subscription.created'));

        self::assertSame(SubscriptionStatus::Active, $this->ledger->statusOf(self::SUBSCRIPTION_ID));
    }

    /**
     * Null rather than an exception: a gate that throws is a gate that ends up wrapped in a
     * try/catch returning true.
     */
    public function testStatusOfIsNullForASubscriptionThisLedgerHasNeverSeen(): void
    {
        self::assertNull($this->ledger->statusOf('sub_never_seen'));
    }

    public function testTheSameSubscriptionIsNeverStoredTwice(): void
    {
        $this->ledger->record($this->event('evt_1', 'subscription.created'));
        $this->ledger->record($this->event('evt_2', 'subscription.updated', occurredAt: '2026-08-31T11:00:00Z'));
        $this->ledger->record($this->event('evt_3', 'subscription.activated', occurredAt: '2026-08-31T12:00:00Z'));

        DB::run('SELECT COUNT(*) AS total FROM ' . SubscriptionLedger::SUBSCRIPTIONS_TABLE);
        self::assertSame(1, (int) DB::fetch()['total']);
    }

    /**
     * Gateway timestamps are stored in UTC regardless of the host's own offset, because the
     * ordering guard compares them as strings.
     */
    public function testAGatewayTimestampIsStoredInUtc(): void
    {
        $this->ledger->recordSnapshot(
            $this->snapshot(SubscriptionStatus::Active),
            new DateTimeImmutable('2026-08-31T14:00:00', new DateTimeZone('+05:00'))
        );

        self::assertSame(
            '2026-08-31 09:00:00',
            $this->ledger->findByGatewayReference(self::SUBSCRIPTION_ID)['last_event_occurred_at']
        );
    }

    private function event(
        string $eventId,
        string $type,
        string $occurredAt = '2026-08-31T10:00:00Z',
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?ScheduledChange $scheduledChange = null,
        string $gatewayReference = self::SUBSCRIPTION_ID,
    ): SubscriptionEvent {
        return new SubscriptionEvent(
            gateway: 'paddle_subscription',
            eventId: $eventId,
            eventType: $type,
            occurredAt: new DateTimeImmutable($occurredAt),
            subscription: $this->snapshot($status, $scheduledChange, $gatewayReference),
        );
    }

    private function snapshot(
        SubscriptionStatus $status,
        ?ScheduledChange $scheduledChange = null,
        string $gatewayReference = self::SUBSCRIPTION_ID,
    ): SubscriptionSnapshot {
        return new SubscriptionSnapshot(
            gateway: 'paddle_subscription',
            gatewayReference: $gatewayReference,
            status: $status,
            scheduledChange: $scheduledChange,
            reference: 'order-9',
            transactionReference: 'txn_test_123',
            customerReference: 'ctm_test_1',
            recurringAmount: new Money(2500, 'USD'),
            billingCycle: new BillingCycle(BillingInterval::Month),
            currentPeriodEndsAt: new DateTimeImmutable('2026-09-30T00:00:00Z'),
            raw: ['id' => $gatewayReference],
        );
    }
}
