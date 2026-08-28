<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use Monad\Clarity\Services\DB;
use DateTimeImmutable;
use PDOException;
use Ramsey\Uuid\Uuid;

/**
 * The stateful half of ReleaseNotes §9.6 — transaction records (§9.6.2), status updates
 * after a re-query or callback (§9.6.5), and the insert-only status history (§9.6.8).
 *
 * Gateway-agnostic by design: it stores what every adapter in §9.3 already produces, so
 * nine gateways share one ledger rather than each carrying a copy of the same persistence.
 *
 * Two invariants hold the whole thing together:
 *
 *   Status rows are only ever inserted, never updated or deleted (§9.6.8.2). The current
 *   status on `checkout_transactions` is a denormalised convenience for querying; the
 *   status rows are the record of what actually happened, and they stay complete even when
 *   a transition is rejected.
 *
 *   Every write that a gateway can deliver twice is keyed on that gateway's own id —
 *   `gateway_event_id` for callbacks, `gateway_refund_id` for refunds — under a unique
 *   index. Gateways redeliver callbacks aggressively and by design, so "record this
 *   callback" must be safe to call repeatedly with the same payload.
 *
 * Append-only rows (statuses, refunds) carry a UUIDv7 primary key rather than the v4 the
 * rest of the framework defaults to. Built-in tables store DATETIME at second precision
 * (Architecture.md §9), and two callbacks arriving within the same second are ordinary — so
 * `created_at` alone cannot order a history, and a v4 tie-break would order it at random.
 * v7 is time-ordered and sorts lexically in generation order, which makes
 * `ORDER BY created_at, id` genuinely chronological without a schema-level sequence column
 * (no portable one exists — MySQL and SQLite both refuse a second auto-increment column).
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class TransactionLedger
{
    public const TRANSACTIONS_TABLE = 'checkout_transactions';
    public const STATUSES_TABLE = 'checkout_transaction_statuses';
    public const REFUNDS_TABLE = 'checkout_refunds';

    /** SQLSTATE class for an integrity constraint violation — MySQL, PostgreSQL, SQLite alike. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23';

    /**
     * @param string|null $context DB connection context, for applications running the
     *        ledger on a connection other than the default.
     */
    public function __construct(private readonly ?string $context = null)
    {
    }

    /**
     * Record a newly created checkout as a pending transaction (§9.6.2), together with the
     * first row of its status history.
     *
     * @return string The ledger's own transaction id.
     */
    public function open(CheckoutRequest $request, CheckoutSession $session): string
    {
        $now = self::now();

        $transactionId = DB::insert(self::TRANSACTIONS_TABLE, [
            'reference' => $request->reference,
            'gateway' => $session->gateway,
            'gateway_reference' => $session->gatewayReference,
            'payment_reference' => $session->paymentReference,
            'amount_minor' => $session->amount->minorUnits,
            'currency' => $session->amount->currency,
            'status' => $session->status->value,
            'customer_email' => $request->customerEmail,
            'metadata' => $request->metadata === [] ? null : json_encode($request->metadata, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ], DB::ID_TYPE_UUID, $this->context);

        $this->appendStatus($transactionId, $session->status, now: $now);

        return $transactionId;
    }

    /**
     * Apply a verified callback (§9.6.4) to the transaction it names, appending a status
     * row and updating the transaction (§9.6.5).
     *
     * @return bool False if this exact gateway event was already recorded — a redelivery,
     *         which is normal traffic and not an error.
     */
    public function recordCallback(CallbackEvent $event): bool
    {
        $transaction = $this->findByGatewayReference($event->gatewayReference);

        if ($transaction === null) {
            throw new CheckoutException(sprintf(
                'Callback %s refers to gateway reference "%s", which this ledger has no transaction for.',
                $event->eventId,
                $event->gatewayReference
            ));
        }

        return $this->apply(
            $transaction,
            $event->status,
            $event->failureReason,
            $event->paymentReference,
            $event->eventId,
            $event->eventType,
            $event->raw
        );
    }

    /**
     * Apply a re-query (§9.6.3) to the transaction it names. The reconciliation path for a
     * callback that never arrived.
     *
     * @return bool False if the transaction is already at this status and nothing changed.
     */
    public function recordSnapshot(TransactionSnapshot $snapshot): bool
    {
        $transaction = $this->findByGatewayReference($snapshot->gatewayReference);

        if ($transaction === null) {
            throw new CheckoutException(sprintf(
                'Snapshot refers to gateway reference "%s", which this ledger has no transaction for.',
                $snapshot->gatewayReference
            ));
        }

        if ($transaction['status'] === $snapshot->status->value) {
            return false;
        }

        return $this->apply(
            $transaction,
            $snapshot->status,
            $snapshot->failureReason,
            $snapshot->paymentReference,
            null,
            'requery',
            $snapshot->raw
        );
    }

    /**
     * Record a refund against a settled transaction (§9.6.6). The transaction's own status
     * is untouched — a refunded payment still succeeded.
     *
     * @return string|null The ledger's refund id, or null if this gateway refund was
     *         already recorded.
     */
    public function recordRefund(string $transactionId, RefundResult $refund): ?string
    {
        $transaction = $this->find($transactionId);

        if ($transaction === null) {
            throw new CheckoutException(sprintf('No transaction "%s" to refund against.', $transactionId));
        }

        $remaining = $this->refundableAmount($transactionId);

        if ($refund->amount->isGreaterThan($remaining)) {
            throw new CheckoutException(sprintf(
                'Refunding %s would exceed the %s still refundable on transaction %s.',
                $refund->amount->describe(),
                $remaining->describe(),
                $transactionId
            ));
        }

        try {
            return DB::insert(self::REFUNDS_TABLE, [
                'id' => self::sequentialId(),
                'transaction_id' => $transactionId,
                'gateway_refund_id' => $refund->gatewayRefundId,
                'amount_minor' => $refund->amount->minorUnits,
                'currency' => $refund->amount->currency,
                'status' => $refund->status,
                'reason' => $refund->reason,
                'raw' => $refund->raw === [] ? null : json_encode($refund->raw, JSON_THROW_ON_ERROR),
                'created_at' => self::now(),
            ], DB::ID_TYPE_UUID, $this->context);
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The amount still refundable: the transaction total less everything already refunded.
     *
     * @throws CheckoutException if there is no such transaction.
     */
    public function refundableAmount(string $transactionId): Money
    {
        $transaction = $this->find($transactionId);

        if ($transaction === null) {
            throw new CheckoutException(sprintf('No transaction "%s" in this ledger.', $transactionId));
        }

        $total = new Money((int) $transaction['amount_minor'], (string) $transaction['currency']);

        return $total->minus($this->totalRefunded($transactionId));
    }

    public function totalRefunded(string $transactionId): Money
    {
        $transaction = $this->find($transactionId);

        if ($transaction === null) {
            throw new CheckoutException(sprintf('No transaction "%s" in this ledger.', $transactionId));
        }

        $currency = (string) $transaction['currency'];
        $refunded = new Money(0, $currency);

        foreach ($this->refunds($transactionId) as $refund) {
            $refunded = $refunded->plus(new Money((int) $refund['amount_minor'], (string) $refund['currency']));
        }

        return $refunded;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $transactionId): ?array
    {
        DB::run(
            sprintf('SELECT * FROM %s WHERE id = ?', self::TRANSACTIONS_TABLE),
            [$transactionId],
            $this->context
        );

        return DB::fetch() ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByGatewayReference(string $gatewayReference): ?array
    {
        DB::run(
            sprintf('SELECT * FROM %s WHERE gateway_reference = ?', self::TRANSACTIONS_TABLE),
            [$gatewayReference],
            $this->context
        );

        return DB::fetch() ?: null;
    }

    /**
     * The complete, ordered status history of a transaction — the insert-only record
     * §9.6.8.2 requires.
     *
     * @return list<array<string, mixed>>
     */
    public function statusHistory(string $transactionId): array
    {
        DB::run(
            sprintf('SELECT * FROM %s WHERE transaction_id = ? ORDER BY created_at, id', self::STATUSES_TABLE),
            [$transactionId],
            $this->context
        );

        return DB::fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function refunds(string $transactionId): array
    {
        DB::run(
            sprintf('SELECT * FROM %s WHERE transaction_id = ? ORDER BY created_at, id', self::REFUNDS_TABLE),
            [$transactionId],
            $this->context
        );

        return DB::fetchAll();
    }

    /**
     * Append the status row, then move the transaction on if it is still open.
     *
     * The status row is written even when the transition is refused, because the history is
     * a record of what the gateway said, not of what the ledger accepted — a late callback
     * contradicting a settled transaction is exactly the thing an audit needs to see.
     *
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $raw
     */
    private function apply(
        array $transaction,
        TransactionStatus $status,
        ?string $failureReason,
        ?string $paymentReference,
        ?string $eventId,
        string $eventType,
        array $raw,
    ): bool {
        $transactionId = (string) $transaction['id'];
        $now = self::now();

        try {
            $this->appendStatus($transactionId, $status, $failureReason, $eventId, $eventType, $raw, $now);
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                return false;
            }

            throw $e;
        }

        $current = TransactionStatus::from((string) $transaction['status']);

        // A settled transaction does not un-settle. Its history keeps the contradicting
        // row; its current status stays the first terminal answer the gateway gave.
        if ($current->isTerminal()) {
            return false;
        }

        $changes = ['status' => $status->value, 'updated_at' => $now];

        if ($paymentReference !== null && $transaction['payment_reference'] === null) {
            $changes['payment_reference'] = $paymentReference;
        }

        DB::update(self::TRANSACTIONS_TABLE, $changes, ['id' => $transactionId], $this->context);

        return true;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function appendStatus(
        string $transactionId,
        TransactionStatus $status,
        ?string $failureReason = null,
        ?string $eventId = null,
        string $eventType = 'created',
        array $raw = [],
        ?string $now = null,
    ): void {
        DB::insert(self::STATUSES_TABLE, [
            'id' => self::sequentialId(),
            'transaction_id' => $transactionId,
            'status' => $status->value,
            'failure_reason' => $failureReason,
            'gateway_event_id' => $eventId,
            'event_type' => $eventType,
            'raw' => $raw === [] ? null : json_encode($raw, JSON_THROW_ON_ERROR),
            'created_at' => $now ?? self::now(),
        ], DB::ID_TYPE_UUID, $this->context);
    }

    /**
     * A unique-index collision, which for this ledger always means "already recorded".
     * Matched on the SQLSTATE class rather than a driver error number, so the same code
     * works on MySQL, PostgreSQL, and SQLite.
     */
    private function isDuplicate(PDOException $e): bool
    {
        return str_starts_with((string) $e->getCode(), self::SQLSTATE_INTEGRITY_VIOLATION);
    }

    /**
     * A time-ordered UUIDv7, so append-only rows sort into the order they were written.
     * See this class's docblock for why `created_at` cannot carry that ordering alone.
     */
    private static function sequentialId(): string
    {
        return Uuid::uuid7()->toString();
    }

    private static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
