<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Closure;
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

        Schema::createTable(TransactionLedger::TRANSACTIONS_TABLE, self::transactionsBlueprint(), $context);
        Schema::createTable(TransactionLedger::STATUSES_TABLE, self::statusesBlueprint(), $context);
        Schema::createTable(TransactionLedger::REFUNDS_TABLE, self::refundsBlueprint(), $context);

        Console::success('Checkout install complete: transaction, status, and refund tables ready.');

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
}
