<?php

declare(strict_types=1);

use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Schema\Blueprint;

/**
 * The three tables ReleaseNotes §9.6.8 requires for Checkout.
 *
 * Deliberately a migration and not part of `DDL.sql` / `mitosis setup`: DDL.sql is a
 * cross-repo compatibility surface (CrossRepoContracts.md §8) whose contents are created on
 * every skeleton install, and Checkout is deferred (Architecture.md §8). Adding checkout
 * tables there would put them on `main` and make every Monad application carry payment
 * tables it does not use. Copy this file into the application's `database/migrations` to
 * adopt Checkout.
 *
 * Conventions follow Architecture.md §9: UUID char(36) primary keys, DATETIME at second
 * precision. Amounts are stored as integer minor units alongside their ISO 4217 code — see
 * Services\Checkout\Money for why no decimal type appears here.
 */
return new class {
    public function up(): void
    {
        // Transaction records (§9.6.8.1). One row per checkout; `status` is the current
        // state, denormalised from the status history for querying.
        Schema::createTable('checkout_transactions', function (Blueprint $table) {
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

            // The ledger resolves every callback and re-query through this column, and a
            // gateway never issues the same reference twice.
            $table->unique('gateway_reference', 'uq_checkout_transactions_gateway_reference');
            $table->index('reference', 'idx_checkout_transactions_reference');
            $table->index('payment_reference', 'idx_checkout_transactions_payment_reference');
            $table->index('status', 'idx_checkout_transactions_status');
        });

        // Immutable status records (§9.6.8.2) — insert only, with a failure reason column.
        Schema::createTable('checkout_transaction_statuses', function (Blueprint $table) {
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
            // rows this ledger writes itself (creation, re-query) carry no event id and
            // are free to repeat.
            $table->unique('gateway_event_id', 'uq_checkout_statuses_gateway_event_id');
            $table->index('transaction_id', 'idx_checkout_statuses_transaction_id');
        });

        // Refunds (§9.6.6). Separate records rather than a transaction status, so partial
        // and repeated refunds are both representable — see Checkout\TransactionStatus.
        Schema::createTable('checkout_refunds', function (Blueprint $table) {
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
        });
    }

    public function down(): void
    {
        Schema::dropTable('checkout_refunds');
        Schema::dropTable('checkout_transaction_statuses');
        Schema::dropTable('checkout_transactions');
    }
};
