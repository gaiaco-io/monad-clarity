<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Closure;
use Monad\Clarity\Services\Checkout\SubscriptionLedger;
use Monad\Clarity\Services\Checkout\TransactionLedger;
use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Schema\Blueprint;

/**
 * `php mitosis checkout:install` — creates the three tables `Services\Checkout` needs
 * (ReleaseNotes_1.2.0.md §9.6.8): transaction records, their insert-only status history,
 * and refunds.
 *
 * Separate from `setup` rather than folded into it, for two reasons. The checkout tables
 * are not a setup-owned compatibility surface (CrossRepoContracts.md §8 names exactly
 * `sessions` and `caches`), and payments are opt-in — an application that takes none has
 * no business carrying three empty payment tables it never queries.
 *
 * **Re-runnable, and that is the upgrade path.** A release that adds a table expects an
 * existing application to simply run this command again, so each table is skipped when it is
 * already there. The skip is a `hasTable` check rather than a reliance on the DDL's own
 * `IF NOT EXISTS`, because that clause covers only the table: createTable() then creates the
 * indexes unconditionally, and MySQL has no `CREATE INDEX IF NOT EXISTS` to make those
 * idempotent either. Without this guard a second run dies on a duplicate index rather than
 * doing nothing.
 *
 * A command rather than a shipped migration file because `resources/` is export-ignored
 * from the Packagist dist (`.gitattributes`): a migration there reaches contributors but
 * never reaches anyone who installs Clarity through Composer, which would leave every
 * ledger method failing on its first query. `src/Console/` ships.
 *
 * The blueprint methods below are the single canonical definition of the checkout schema —
 * tests exercise these same closures rather than a second, hand-maintained copy that could
 * drift from what this command actually emits.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class CheckoutInstall implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $context = $arguments->option('context');
        $context = is_string($context) ? $context : null;

        $tables = [
            TransactionLedger::TRANSACTIONS_TABLE => self::transactionsBlueprint(),
            TransactionLedger::STATUSES_TABLE => self::statusesBlueprint(),
            TransactionLedger::REFUNDS_TABLE => self::refundsBlueprint(),
            SubscriptionLedger::SUBSCRIPTIONS_TABLE => self::subscriptionsBlueprint(),
        ];

        $created = 0;

        foreach ($tables as $table => $blueprint) {
            if (Schema::hasTable($table, $context)) {
                continue;
            }

            Schema::createTable($table, $blueprint, $context);
            $created++;
        }

        Console::success($created === 0
            ? 'Checkout is already installed: all four tables were already present.'
            : sprintf(
                'Checkout install complete: %d of 4 tables created (transaction, status, refund, subscription).',
                $created
            ));

        return 0;
    }

    /**
     * Transaction records (§9.6.8.1). `status` is the current state, denormalised from the
     * status history for querying; amounts are integer minor units beside their ISO 4217
     * code, never a decimal — see Services\Checkout\Money for why.
     *
     * @return Closure(Blueprint): void
     */
    public static function transactionsBlueprint(): Closure
    {
        return static function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->string('gateway', 64);
            $table->string('gateway_reference');
            $table->string('payment_reference', 255, nullable: true);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 16);
            $table->string('customer_email', 255, nullable: true);
            $table->json('metadata', nullable: true);
            $table->datetime('created_at');
            $table->datetime('updated_at');

            // Every callback and re-query resolves through this column, and a gateway
            // never issues the same reference twice.
            $table->unique('gateway_reference', 'uq_checkout_transactions_gateway_reference');
            $table->index('reference', 'idx_checkout_transactions_reference');
            $table->index('payment_reference', 'idx_checkout_transactions_payment_reference');
            $table->index('status', 'idx_checkout_transactions_status');
        };
    }

    /**
     * Immutable status records (§9.6.8.2) — insert only, with a failure reason column.
     *
     * @return Closure(Blueprint): void
     */
    public static function statusesBlueprint(): Closure
    {
        return static function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_id');
            $table->string('status', 16);
            $table->text('failure_reason', nullable: true);
            $table->string('gateway_event_id', 255, nullable: true);
            $table->string('event_type', 64);
            $table->json('raw', nullable: true);
            $table->datetime('created_at');

            // What makes callback handling idempotent: a gateway redelivering an event it
            // already sent collides here and is recognised as a duplicate. Nullable, and
            // NULLs do not collide under a unique index on any supported dialect — the
            // rows the ledger writes itself (creation, re-query) carry no event id and are
            // free to repeat.
            $table->unique('gateway_event_id', 'uq_checkout_statuses_gateway_event_id');
            $table->index('transaction_id', 'idx_checkout_statuses_transaction_id');
        };
    }

    /**
     * Refunds (§9.6.6). Separate records rather than a transaction status, so partial and
     * repeated refunds are both representable — see Services\Checkout\TransactionStatus.
     *
     * @return Closure(Blueprint): void
     */
    public static function refundsBlueprint(): Closure
    {
        return static function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_id');
            $table->string('gateway_refund_id');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32);
            $table->text('reason', nullable: true);
            $table->json('raw', nullable: true);
            $table->datetime('created_at');

            $table->unique('gateway_refund_id', 'uq_checkout_refunds_gateway_refund_id');
            $table->index('transaction_id', 'idx_checkout_refunds_transaction_id');
        };
    }

    /**
     * Subscriptions (ReleaseNotes_1.4.0.md §1). Unlike the two tables above this one is
     * **mutable** — a subscription is a long-lived arrangement whose current state is what an
     * application queries, not an event to be appended. So it carries an ordinary UUIDv4 key
     * like `checkout_transactions`, and the UUIDv7 rule of 1.2.0 §2.3 does not reach it: that
     * rule exists to order insert-only rows sharing a second-precision timestamp, and there is
     * no history here to order.
     *
     * The scheduled-change block is flattened into three columns rather than left inside
     * `raw`. "Which subscriptions have a cancellation pending, and when" is a question asked in
     * a WHERE clause, and it should not need a JSON extraction to answer.
     *
     * `reference` is nullable because a gateway is not guaranteed to carry the merchant's own
     * reference onto the subscription it creates from a transaction. When it does not, the
     * reference is resolved through `transaction_reference` instead — so a subscription that
     * arrives before that link is known is still recordable, which a NOT NULL column would
     * have made impossible at exactly the wrong moment.
     *
     * `amount_minor` is the **recurring** charge, not a total and not what any one transaction
     * took — a different meaning from the identically named column on `checkout_transactions`.
     *
     * @return Closure(Blueprint): void
     */
    public static function subscriptionsBlueprint(): Closure
    {
        return static function (Blueprint $table) {
            $table->id();
            $table->string('reference', 255, nullable: true);
            $table->string('gateway', 64);
            $table->string('gateway_reference');
            $table->string('transaction_reference', 255, nullable: true);
            $table->string('customer_reference', 255, nullable: true);
            $table->string('status', 16);
            $table->bigInteger('amount_minor', nullable: true);
            $table->string('currency', 3, nullable: true);
            $table->string('billing_interval', 8, nullable: true);
            $table->integer('billing_frequency', nullable: true);
            $table->datetime('next_billed_at', nullable: true);
            $table->datetime('current_period_starts_at', nullable: true);
            $table->datetime('current_period_ends_at', nullable: true);
            $table->string('scheduled_action', 16, nullable: true);
            $table->datetime('scheduled_effective_at', nullable: true);
            $table->datetime('scheduled_resume_at', nullable: true);
            // What the monotonic guard compares against: when the gateway says the newest
            // applied delivery happened, and the ids of *every* delivery applied at that
            // moment. A set rather than one id because gateways emit several distinct events
            // in a single second and DATETIME stores seconds — remembering only the last of
            // them would leave its siblings unrecognised on redelivery. See SubscriptionLedger.
            $table->json('last_event_ids', nullable: true);
            $table->datetime('last_event_occurred_at', nullable: true);
            $table->json('raw', nullable: true);
            $table->datetime('created_at');
            $table->datetime('updated_at');

            // Load-bearing for concurrency rather than for redelivery: two simultaneous
            // "subscription created" deliveries both find no row, and this is what stops the
            // second from inserting a duplicate.
            $table->unique('gateway_reference', 'uq_checkout_subscriptions_gateway_reference');
            $table->index('reference', 'idx_checkout_subscriptions_reference');
            $table->index('transaction_reference', 'idx_checkout_subscriptions_transaction_reference');
            $table->index('customer_reference', 'idx_checkout_subscriptions_customer_reference');
            $table->index('status', 'idx_checkout_subscriptions_status');
        };
    }

}
