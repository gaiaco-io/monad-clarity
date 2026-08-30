<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use DateTimeImmutable;
use DateTimeZone;
use Monad\Clarity\Services\DB;
use PDOException;

/**
 * Persistence for subscriptions — the recurring counterpart to TransactionLedger, and
 * deliberately a separate class rather than six more methods on it.
 *
 * TransactionLedger holds two invariants that are the whole reason it is easy to trust:
 * status rows are only ever inserted, and every write a gateway can deliver twice is keyed on
 * that gateway's own id under a unique index. **Neither is true here.** A subscription is one
 * mutable row, and folding it in would turn a class whose value is two crisp invariants into
 * one whose invariants have exceptions. So this class states its own instead:
 *
 *   One row per subscription, updated in place. Its history is the `subscription.*` deliveries
 *   an application may keep for itself; §9.6.8.2's insert-only requirement is about
 *   transaction status, and inventing a second history table here would be scaffolding
 *   nothing asked for.
 *
 *   Idempotency is a **monotonic guard on the gateway's own timestamp**, not a unique index.
 *   A mutable row has nowhere to hang an index on event id, so what protects it is refusing to
 *   apply an event older than the state already stored, plus the set of ids already applied at
 *   the newest stored moment.
 *
 *   The id set is not belt and braces. Gateways emit several distinct events in one second —
 *   observed live: a `subscription.resumed` and a `subscription.updated` 126 microseconds
 *   apart, which DATETIME's second precision collapses together. Remembering only the newest
 *   id would leave its siblings unrecognised, so a redelivery of one of them would be applied
 *   again and reported as a real change, double-firing whatever the application does on that.
 *   Keeping the ids for the current second makes redelivery recognition exact.
 *
 *   This is still an honestly weaker guarantee than the one TransactionLedger gives, in one
 *   remaining way: within a single second the events cannot be *ordered*, so the last writer
 *   wins. Refusing same-second events instead would silently drop real state, which is worse.
 *
 *   The unique index on `gateway_reference` is still load-bearing, for **concurrency**: two
 *   simultaneous first-deliveries both see no row, and the index stops the loser inserting a
 *   duplicate. That collision is caught and turned into an update.
 *
 * **Two clocks, deliberately.** `created_at` and `updated_at` follow TransactionLedger's local
 * convention, so the sibling tables read consistently. `last_event_occurred_at` is always UTC,
 * because it is what the gateway says happened rather than when this host wrote it down —
 * mixing a local timestamp into that column would make the ordering guard wrong by the host's
 * own UTC offset, which is a bug that only appears outside Greenwich.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class SubscriptionLedger
{
    public const SUBSCRIPTIONS_TABLE = 'checkout_subscriptions';

    /** SQLSTATE class for an integrity constraint violation — MySQL, PostgreSQL, SQLite alike. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23';

    /**
     * @param string|null $context DB connection context, for applications running the ledger
     *        on a connection other than the default.
     */
    public function __construct(private readonly ?string $context = null)
    {
    }

    /**
     * Apply a verified subscription callback, creating the record if this is the first sight
     * of it.
     *
     * @return bool True if the record was created or moved. False covers the two cases where
     *         it was not: this exact delivery was already applied, or it is older than the
     *         state already stored. Both mean "handled, nothing further to do" — a webhook
     *         endpoint acknowledges either.
     */
    public function record(SubscriptionEvent $event): bool
    {
        return $this->apply($event->subscription, $event->eventId, $event->occurredAt);
    }

    /**
     * Apply a re-query — the reconciliation path for a callback that never arrived.
     *
     * The stored event id is left alone rather than cleared, so a delivery that turns up after
     * a re-query is still recognised as one already seen.
     *
     * @param DateTimeImmutable|null $observedAt When this state was read. Defaults to now.
     * @return bool False if the snapshot is older than the state already stored.
     */
    public function recordSnapshot(SubscriptionSnapshot $snapshot, ?DateTimeImmutable $observedAt = null): bool
    {
        return $this->apply(
            $snapshot,
            null,
            $observedAt?->setTimezone(new DateTimeZone('UTC')) ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
    }

    /**
     * Attach a subscription to the transaction that created it, for the case where the
     * transaction side of the link was learned first.
     *
     * Idempotent, and never overwrites a link already recorded — the first answer and the
     * second are the same fact, and a gateway that later omits it should not erase it.
     *
     * @return bool False if there is no such subscription, or it already carries a transaction.
     */
    public function linkTransaction(string $gatewayReference, string $transactionReference): bool
    {
        $subscription = $this->findByGatewayReference($gatewayReference);

        if ($subscription === null || ($subscription['transaction_reference'] ?? null) !== null) {
            return false;
        }

        return DB::update(
            self::SUBSCRIPTIONS_TABLE,
            ['transaction_reference' => $transactionReference, 'updated_at' => self::now()],
            ['id' => $subscription['id']],
            $this->context
        ) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $subscriptionId): ?array
    {
        DB::run(
            sprintf('SELECT * FROM %s WHERE id = ?', self::SUBSCRIPTIONS_TABLE),
            [$subscriptionId],
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
            sprintf('SELECT * FROM %s WHERE gateway_reference = ?', self::SUBSCRIPTIONS_TABLE),
            [$gatewayReference],
            $this->context
        );

        return DB::fetch() ?: null;
    }

    /**
     * Every subscription recorded against one merchant reference, newest first. A plural
     * lookup because a customer who cancels and subscribes again under the same reference has
     * two subscriptions, and returning one of them silently would be a lie.
     *
     * @return list<array<string, mixed>>
     */
    public function findByReference(string $reference): array
    {
        DB::run(
            sprintf('SELECT * FROM %s WHERE reference = ? ORDER BY created_at DESC, id DESC', self::SUBSCRIPTIONS_TABLE),
            [$reference],
            $this->context
        );

        return DB::fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTransactionReference(string $transactionReference): ?array
    {
        DB::run(
            sprintf('SELECT * FROM %s WHERE transaction_reference = ?', self::SUBSCRIPTIONS_TABLE),
            [$transactionReference],
            $this->context
        );

        return DB::fetch() ?: null;
    }

    /**
     * The stored status of a subscription, or null if this ledger has never seen it.
     *
     * Null rather than an exception, so access-gating code can ask the question without
     * wrapping it — a gate that throws is a gate that ends up inside a `try` returning true.
     * What "null" or any given status should mean for access stays the application's decision;
     * see SubscriptionStatus for why this library declines to make it.
     */
    public function statusOf(string $gatewayReference): ?SubscriptionStatus
    {
        $subscription = $this->findByGatewayReference($gatewayReference);
        $status = $subscription['status'] ?? null;

        return is_string($status) ? SubscriptionStatus::tryFrom($status) : null;
    }

    /**
     * Insert the subscription, or move it on if this state is newer than what is stored.
     *
     * The ordering is the whole mechanism: recognise an exact repeat, refuse anything older,
     * and otherwise write. See this class's docblock for why a mutable row needs this where an
     * insert-only history needs only a unique index.
     */
    private function apply(SubscriptionSnapshot $snapshot, ?string $eventId, DateTimeImmutable $occurredAt): bool
    {
        $existing = $this->findByGatewayReference($snapshot->gatewayReference);

        if ($existing === null) {
            try {
                $this->insert($snapshot, $eventId === null ? [] : [$eventId], $occurredAt);

                return true;
            } catch (PDOException $e) {
                if (!$this->isDuplicate($e)) {
                    throw $e;
                }

                // Another delivery inserted it between the read above and this write. Fall
                // through and treat this as the update it turned out to be.
                $existing = $this->findByGatewayReference($snapshot->gatewayReference);

                if ($existing === null) {
                    throw $e;
                }
            }
        }

        $storedAt = is_string($existing['last_event_occurred_at'] ?? null)
            ? (string) $existing['last_event_occurred_at']
            : null;
        $seen = self::seenIds($existing);
        $at = self::format($occurredAt);

        // An exact redelivery, recognised anywhere in the second it was applied in.
        if ($eventId !== null && in_array($eventId, $seen, true)) {
            return false;
        }

        if ($storedAt !== null && $at < $storedAt) {
            return false;
        }

        // Same second: this is a sibling of what is stored, not a successor, so its id joins
        // the set rather than replacing it. A newer second starts the set afresh.
        $ids = $eventId === null ? $seen : array_values(array_unique(
            $storedAt === $at ? [...$seen, $eventId] : [$eventId]
        ));

        return $this->update((string) $existing['id'], $snapshot, $ids, $occurredAt, $existing);
    }

    /**
     * @param list<string> $eventIds
     */
    private function insert(SubscriptionSnapshot $snapshot, array $eventIds, DateTimeImmutable $occurredAt): void
    {
        $now = self::now();

        DB::insert(self::SUBSCRIPTIONS_TABLE, self::columns($snapshot, $eventIds, $occurredAt) + [
            'created_at' => $now,
            'updated_at' => $now,
        ], DB::ID_TYPE_UUID, $this->context);
    }

    /**
     * @param list<string> $eventIds
     * @param array<string, mixed> $existing
     */
    private function update(
        string $id,
        SubscriptionSnapshot $snapshot,
        array $eventIds,
        DateTimeImmutable $occurredAt,
        array $existing
    ): bool {
        $columns = self::columns($snapshot, $eventIds, $occurredAt);

        // Facts a gateway may legitimately omit on some event types are kept rather than
        // blanked: losing the creating transaction because a later `subscription.updated` did
        // not mention it would break the link this whole design rests on.
        foreach (['reference', 'transaction_reference', 'customer_reference'] as $sticky) {
            $columns[$sticky] ??= $existing[$sticky] ?? null;
        }

        $columns['updated_at'] = self::now();

        DB::update(self::SUBSCRIPTIONS_TABLE, $columns, ['id' => $id], $this->context);

        return true;
    }

    /**
     * @param list<string> $eventIds
     * @return array<string, mixed>
     */
    private static function columns(
        SubscriptionSnapshot $snapshot,
        array $eventIds,
        DateTimeImmutable $occurredAt
    ): array {
        return [
            'reference' => $snapshot->reference,
            'gateway' => $snapshot->gateway,
            'gateway_reference' => $snapshot->gatewayReference,
            'transaction_reference' => $snapshot->transactionReference,
            'customer_reference' => $snapshot->customerReference,
            'status' => $snapshot->status->value,
            'amount_minor' => $snapshot->recurringAmount?->minorUnits,
            'currency' => $snapshot->recurringAmount?->currency,
            'billing_interval' => $snapshot->billingCycle?->interval->value,
            'billing_frequency' => $snapshot->billingCycle?->frequency,
            'next_billed_at' => self::formatOrNull($snapshot->nextBilledAt),
            'current_period_starts_at' => self::formatOrNull($snapshot->currentPeriodStartsAt),
            'current_period_ends_at' => self::formatOrNull($snapshot->currentPeriodEndsAt),
            'scheduled_action' => $snapshot->scheduledChange?->action->value,
            'scheduled_effective_at' => self::formatOrNull($snapshot->scheduledChange?->effectiveAt),
            'scheduled_resume_at' => self::formatOrNull($snapshot->scheduledChange?->resumeAt),
            'last_event_ids' => $eventIds === [] ? null : json_encode($eventIds, JSON_THROW_ON_ERROR),
            'last_event_occurred_at' => self::format($occurredAt),
            'raw' => $snapshot->raw === [] ? null : json_encode($snapshot->raw, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * The ids already applied at the stored moment. A re-query writes none, so an empty set
     * simply means nothing has been recognised there yet.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function seenIds(array $row): array
    {
        $stored = $row['last_event_ids'] ?? null;

        if (!is_string($stored) || $stored === '') {
            return [];
        }

        $ids = json_decode($stored, true);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    private function isDuplicate(PDOException $e): bool
    {
        return str_starts_with((string) $e->getCode(), self::SQLSTATE_INTEGRITY_VIOLATION);
    }

    private static function formatOrNull(?DateTimeImmutable $moment): ?string
    {
        return $moment === null ? null : self::format($moment);
    }

    /**
     * Gateway timestamps are stored in UTC — see the two-clocks note on this class.
     */
    private static function format(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
