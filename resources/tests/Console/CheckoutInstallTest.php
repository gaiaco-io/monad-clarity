<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\CheckoutInstall;
use Monad\Clarity\Console\Setup;
use Monad\Clarity\Services\Checkout\TransactionLedger;
use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class CheckoutInstallTest extends TestCase
{
    #[Before]
    public function setUpInMemoryDatabase(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));
    }

    #[After]
    public function resetDB(): void
    {
        DB::reset();
        Console::reset();
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    public function testCreatesTheThreeCheckoutTables(): void
    {
        $output = $this->capture(function () {
            self::assertSame(0, (new CheckoutInstall())(Arguments::parse([])));
        });

        self::assertStringContainsString('Checkout install complete', $output);
        self::assertTrue(Schema::hasTable(TransactionLedger::TRANSACTIONS_TABLE));
        self::assertTrue(Schema::hasTable(TransactionLedger::STATUSES_TABLE));
        self::assertTrue(Schema::hasTable(TransactionLedger::REFUNDS_TABLE));
    }

    /**
     * Payments are opt-in. An application that takes none runs `setup` and carries no
     * payment tables at all.
     */
    public function testSetupDoesNotCreateCheckoutTables(): void
    {
        $this->capture(static fn () => (new Setup())(Arguments::parse([])));

        self::assertTrue(Schema::hasTable('sessions'));
        self::assertFalse(Schema::hasTable(TransactionLedger::TRANSACTIONS_TABLE));
        self::assertFalse(Schema::hasTable(TransactionLedger::STATUSES_TABLE));
        self::assertFalse(Schema::hasTable(TransactionLedger::REFUNDS_TABLE));
    }

    public function testTheCommandIsRegisteredUnderItsStableName(): void
    {
        $output = $this->capture(static fn () => Console::run(['mitosis', 'checkout:install']));

        self::assertStringContainsString('Checkout install complete', $output);
        self::assertTrue(Schema::hasTable(TransactionLedger::TRANSACTIONS_TABLE));
    }

    public function testTheTablesAcceptTheRowsTheLedgerWrites(): void
    {
        $this->capture(static fn () => (new CheckoutInstall())(Arguments::parse([])));

        $transactionId = DB::insert(TransactionLedger::TRANSACTIONS_TABLE, [
            'reference' => 'ORDER-1001',
            'gateway' => 'stripe_checkout',
            'gateway_reference' => 'cs_test_123',
            'payment_reference' => null,
            'amount_minor' => 2500,
            'currency' => 'USD',
            'status' => 'pending',
            'customer_email' => null,
            'metadata' => null,
            'created_at' => '2026-08-28 10:00:00',
            'updated_at' => '2026-08-28 10:00:00',
        ]);

        DB::insert(TransactionLedger::STATUSES_TABLE, [
            'transaction_id' => $transactionId,
            'status' => 'pending',
            'failure_reason' => null,
            'gateway_event_id' => null,
            'event_type' => 'created',
            'raw' => null,
            'created_at' => '2026-08-28 10:00:00',
        ]);

        DB::insert(TransactionLedger::REFUNDS_TABLE, [
            'transaction_id' => $transactionId,
            'gateway_refund_id' => 're_test_1',
            'amount_minor' => 1000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'reason' => null,
            'raw' => null,
            'created_at' => '2026-08-28 10:05:00',
        ]);

        DB::run(sprintf('SELECT COUNT(*) AS total FROM %s', TransactionLedger::REFUNDS_TABLE));
        self::assertSame(1, (int) DB::fetch()['total']);
    }

    /**
     * The unique index the ledger's callback idempotency actually rests on — asserted
     * against the shipped DDL, not just the ledger's own guard.
     */
    public function testGatewayEventIdIsUniqueButRepeatableWhenNull(): void
    {
        $this->capture(static fn () => (new CheckoutInstall())(Arguments::parse([])));

        $row = static fn (?string $eventId): array => [
            'transaction_id' => 'txn-1',
            'status' => 'success',
            'failure_reason' => null,
            'gateway_event_id' => $eventId,
            'event_type' => 'checkout.session.completed',
            'raw' => null,
            'created_at' => '2026-08-28 10:00:00',
        ];

        DB::insert(TransactionLedger::STATUSES_TABLE, $row('evt_1'));

        // NULLs must stay repeatable: the ledger's own creation and re-query rows carry no
        // event id, and several of them against one transaction is ordinary.
        DB::insert(TransactionLedger::STATUSES_TABLE, $row(null));
        DB::insert(TransactionLedger::STATUSES_TABLE, $row(null));

        $this->expectException(\PDOException::class);
        DB::insert(TransactionLedger::STATUSES_TABLE, $row('evt_1'));
    }

    public function testGatewayRefundIdIsUnique(): void
    {
        $this->capture(static fn () => (new CheckoutInstall())(Arguments::parse([])));

        $row = [
            'transaction_id' => 'txn-1',
            'gateway_refund_id' => 're_test_1',
            'amount_minor' => 1000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'reason' => null,
            'raw' => null,
            'created_at' => '2026-08-28 10:00:00',
        ];

        DB::insert(TransactionLedger::REFUNDS_TABLE, $row);

        $this->expectException(\PDOException::class);
        DB::insert(TransactionLedger::REFUNDS_TABLE, $row);
    }
}
