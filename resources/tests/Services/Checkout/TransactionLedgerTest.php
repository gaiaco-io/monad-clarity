<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Checkout;

use Monad\Clarity\Services\Checkout\CallbackEvent;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\CheckoutSession;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\RefundResult;
use Monad\Clarity\Services\Checkout\TransactionLedger;
use Monad\Clarity\Services\Checkout\TransactionSnapshot;
use Monad\Clarity\Services\Checkout\TransactionStatus;
use Monad\Clarity\Console\CheckoutInstall;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Event;
use Monad\Clarity\Services\Schema;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * The ledger runs against the very blueprints `mitosis checkout:install` emits, on
 * in-memory SQLite — so these tests cover the schema exactly as shipped, including the
 * unique indexes the idempotency guarantees actually depend on. Exercising the command's
 * own closures rather than a second copy of the DDL is what keeps the two from drifting.
 */
final class TransactionLedgerTest extends TestCase
{
    private TransactionLedger $ledger;

    #[Before]
    public function setUpLedgerSchema(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));

        Schema::createTable(TransactionLedger::TRANSACTIONS_TABLE, CheckoutInstall::transactionsBlueprint());
        Schema::createTable(TransactionLedger::STATUSES_TABLE, CheckoutInstall::statusesBlueprint());
        Schema::createTable(TransactionLedger::REFUNDS_TABLE, CheckoutInstall::refundsBlueprint());

        $this->ledger = new TransactionLedger();
    }

    #[After]
    public function resetDB(): void
    {
        DB::reset();
        Event::forget();
    }

    // ---------------------------------------------------------------------------------
    // §9.6.2 — transaction creation
    // ---------------------------------------------------------------------------------

    public function testOpenRecordsAPendingTransactionAndItsFirstStatusRow(): void
    {
        $id = $this->open();

        $transaction = $this->ledger->find($id);
        self::assertSame('ORDER-1001', $transaction['reference']);
        self::assertSame('stripe_checkout', $transaction['gateway']);
        self::assertSame('cs_test_123', $transaction['gateway_reference']);
        self::assertSame(2500, (int) $transaction['amount_minor']);
        self::assertSame('USD', $transaction['currency']);
        self::assertSame('pending', $transaction['status']);

        $history = $this->ledger->statusHistory($id);
        self::assertCount(1, $history);
        self::assertSame('pending', $history[0]['status']);
        self::assertSame('created', $history[0]['event_type']);
    }

    public function testOpenStoresMetadataAsJsonAndOmitsItWhenEmpty(): void
    {
        $withMetadata = $this->open(metadata: ['order_id' => '1001']);
        self::assertSame(['order_id' => '1001'], json_decode((string) $this->ledger->find($withMetadata)['metadata'], true));

        $without = $this->open(reference: 'ORDER-1002', gatewayReference: 'cs_test_999');
        self::assertNull($this->ledger->find($without)['metadata']);
    }

    // ---------------------------------------------------------------------------------
    // §9.6.4 / §9.6.5 — callbacks move the transaction on, exactly once
    // ---------------------------------------------------------------------------------

    public function testRecordCallbackSettlesTheTransactionAndAppendsToItsHistory(): void
    {
        $id = $this->open();

        self::assertTrue($this->ledger->recordCallback($this->callbackEvent('evt_1', TransactionStatus::Success)));

        self::assertSame('success', $this->ledger->find($id)['status']);

        $history = $this->ledger->statusHistory($id);
        self::assertCount(2, $history);
        self::assertSame('success', $history[1]['status']);
        self::assertSame('evt_1', $history[1]['gateway_event_id']);
    }

    public function testARedeliveredCallbackIsIgnoredAndWritesNoSecondStatusRow(): void
    {
        $id = $this->open();
        $event = $this->callbackEvent('evt_1', TransactionStatus::Success);

        self::assertTrue($this->ledger->recordCallback($event));
        self::assertFalse($this->ledger->recordCallback($event), 'A redelivery is normal traffic, not an error.');
        self::assertFalse($this->ledger->recordCallback($event));

        self::assertCount(2, $this->ledger->statusHistory($id));
        self::assertSame('success', $this->ledger->find($id)['status']);
    }

    public function testAFailedCallbackRecordsItsReason(): void
    {
        $id = $this->open();

        $this->ledger->recordCallback($this->callbackEvent('evt_1', TransactionStatus::Failed, 'The bank declined the debit.'));

        self::assertSame('failed', $this->ledger->find($id)['status']);
        self::assertSame('The bank declined the debit.', $this->ledger->statusHistory($id)[1]['failure_reason']);
    }

    public function testASettledTransactionIsNotReopenedButTheContradictionIsStillRecorded(): void
    {
        $id = $this->open();
        $this->ledger->recordCallback($this->callbackEvent('evt_1', TransactionStatus::Success));

        // A late, genuine, differently-identified event that disagrees with the settled state.
        self::assertFalse($this->ledger->recordCallback($this->callbackEvent('evt_2', TransactionStatus::Cancelled)));

        self::assertSame('success', $this->ledger->find($id)['status'], 'A settled transaction does not un-settle.');

        $history = $this->ledger->statusHistory($id);
        self::assertCount(3, $history, 'The audit trail keeps what the gateway said, even when refused.');
        self::assertSame('cancelled', $history[2]['status']);
    }

    public function testRecordCallbackRefusesAnEventForAnUnknownTransaction(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/no transaction for/');

        $this->ledger->recordCallback($this->callbackEvent('evt_1', TransactionStatus::Success, gatewayReference: 'cs_unknown'));
    }

    public function testStatusRowsAreOnlyEverInserted(): void
    {
        $id = $this->open();
        $this->ledger->recordCallback($this->callbackEvent('evt_1', TransactionStatus::Success));
        $this->ledger->recordCallback($this->callbackEvent('evt_2', TransactionStatus::Cancelled));

        $history = $this->ledger->statusHistory($id);

        self::assertSame(['pending', 'success', 'cancelled'], array_column($history, 'status'));
        self::assertCount(3, array_unique(array_column($history, 'id')), 'Every status row is its own record.');
    }

    // ---------------------------------------------------------------------------------
    // §9.6.3 — re-query reconciles when a callback never arrived
    // ---------------------------------------------------------------------------------

    public function testRecordSnapshotSettlesATransactionThatNeverReceivedItsCallback(): void
    {
        $id = $this->open();

        self::assertTrue($this->ledger->recordSnapshot(new TransactionSnapshot(
            gateway: 'stripe_checkout',
            gatewayReference: 'cs_test_123',
            status: TransactionStatus::Success,
            amount: new Money(2500, 'USD'),
            paymentReference: 'pi_test_456',
        )));

        self::assertSame('success', $this->ledger->find($id)['status']);
        self::assertSame('pi_test_456', $this->ledger->find($id)['payment_reference']);
        self::assertSame('requery', $this->ledger->statusHistory($id)[1]['event_type']);
    }

    public function testRecordSnapshotIsANoOpWhenNothingChanged(): void
    {
        $id = $this->open();

        self::assertFalse($this->ledger->recordSnapshot(new TransactionSnapshot(
            gateway: 'stripe_checkout',
            gatewayReference: 'cs_test_123',
            status: TransactionStatus::Pending,
            amount: new Money(2500, 'USD'),
        )));

        self::assertCount(1, $this->ledger->statusHistory($id));
    }

    // ---------------------------------------------------------------------------------
    // §9.6.6 — refunds accumulate; the transaction stays successful
    // ---------------------------------------------------------------------------------

    public function testRecordRefundLeavesTheTransactionSuccessful(): void
    {
        $id = $this->settled();

        $refundId = $this->ledger->recordRefund($id, $this->refund('re_1', 1000));

        self::assertNotNull($refundId);
        self::assertSame('success', $this->ledger->find($id)['status']);
        self::assertCount(1, $this->ledger->refunds($id));
        self::assertSame(1000, $this->ledger->totalRefunded($id)->minorUnits);
        self::assertSame(1500, $this->ledger->refundableAmount($id)->minorUnits);
    }

    public function testPartialRefundsAccumulateUpToTheOriginalTotal(): void
    {
        $id = $this->settled();

        $this->ledger->recordRefund($id, $this->refund('re_1', 1000));
        $this->ledger->recordRefund($id, $this->refund('re_2', 1500));

        self::assertSame(2500, $this->ledger->totalRefunded($id)->minorUnits);
        self::assertSame(0, $this->ledger->refundableAmount($id)->minorUnits);
    }

    public function testARefundBeyondTheRefundableAmountIsRefused(): void
    {
        $id = $this->settled();
        $this->ledger->recordRefund($id, $this->refund('re_1', 2000));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/would exceed the 500 USD/');

        $this->ledger->recordRefund($id, $this->refund('re_2', 1000));
    }

    public function testTheSameGatewayRefundIsNeverRecordedTwice(): void
    {
        $id = $this->settled();
        $refund = $this->refund('re_1', 1000);

        self::assertNotNull($this->ledger->recordRefund($id, $refund));
        self::assertNull($this->ledger->recordRefund($id, $refund), 'A retried refund call must not double-refund.');

        self::assertCount(1, $this->ledger->refunds($id));
        self::assertSame(1000, $this->ledger->totalRefunded($id)->minorUnits);
    }

    /**
     * The retry case idempotency exists for — a gateway call that timed out after the
     * refund was accepted. A full refund leaves nothing refundable, so recognising the
     * duplicate has to come before the refundable-amount check, or the retry is rejected
     * as an overspend rather than recognised as the same refund.
     */
    public function testRetryingAFullRefundIsRecognisedRatherThanRejectedAsAnOverspend(): void
    {
        $id = $this->settled();
        $refund = $this->refund('re_1', 2500);

        self::assertNotNull($this->ledger->recordRefund($id, $refund));
        self::assertNull($this->ledger->recordRefund($id, $refund));

        self::assertCount(1, $this->ledger->refunds($id));
        self::assertSame(2500, $this->ledger->totalRefunded($id)->minorUnits);
        self::assertSame(0, $this->ledger->refundableAmount($id)->minorUnits);
    }

    // ---------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------

    /**
     * @param array<string, scalar> $metadata
     */
    private function open(
        string $reference = 'ORDER-1001',
        string $gatewayReference = 'cs_test_123',
        array $metadata = [],
    ): string {
        return $this->ledger->open(
            new CheckoutRequest(
                reference: $reference,
                amount: new Money(2500, 'USD'),
                successUrl: 'https://shop.test/success',
                cancelUrl: 'https://shop.test/cancel',
                customerEmail: 'buyer@example.com',
                metadata: $metadata,
            ),
            new CheckoutSession(
                gateway: 'stripe_checkout',
                gatewayReference: $gatewayReference,
                redirectUrl: 'https://checkout.stripe.com/c/pay/' . $gatewayReference,
                status: TransactionStatus::Pending,
                amount: new Money(2500, 'USD'),
            )
        );
    }

    private function settled(): string
    {
        $id = $this->open();
        $this->ledger->recordCallback($this->callbackEvent('evt_1', TransactionStatus::Success));

        return $id;
    }

    private function callbackEvent(
        string $eventId,
        TransactionStatus $status,
        ?string $failureReason = null,
        string $gatewayReference = 'cs_test_123',
    ): CallbackEvent {
        return new CallbackEvent(
            gateway: 'stripe_checkout',
            eventId: $eventId,
            eventType: 'checkout.session.completed',
            gatewayReference: $gatewayReference,
            status: $status,
            failureReason: $failureReason,
            paymentReference: 'pi_test_456',
            raw: ['id' => $eventId],
        );
    }

    private function refund(string $gatewayRefundId, int $minorUnits): RefundResult
    {
        return new RefundResult(
            gateway: 'stripe_checkout',
            gatewayRefundId: $gatewayRefundId,
            reference: 'pi_test_456',
            amount: new Money($minorUnits, 'USD'),
            status: 'succeeded',
        );
    }
}
