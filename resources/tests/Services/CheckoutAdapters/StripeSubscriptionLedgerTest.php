<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\CheckoutAdapters;

use LogicException;
use Monad\Clarity\Console\CheckoutInstall;
use Monad\Clarity\Services\Checkout\BillingCycle;
use Monad\Clarity\Services\Checkout\BillingInterval;
use Monad\Clarity\Services\Checkout\SubscriptionEvent;
use Monad\Clarity\Services\Checkout\SubscriptionLedger;
use Monad\Clarity\Services\CheckoutAdapters\StripeSubscription;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Utils\HMAC;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * Every event here is a signed delivery parsed by the adapter, then handed to the ledger built
 * from the shipped `checkout:install` blueprint — the round trip is the point.
 */
final class StripeSubscriptionLedgerTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_test_secret';

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

    public function testASubscriptionCreatedEventIsRecordedWithItsReference(): void
    {
        self::assertTrue($this->ledger->record($this->delivered('evt_1', 'customer.subscription.created')));

        $row = $this->ledger->findByGatewayReference('sub_test_1');

        self::assertNotNull($row);
        self::assertSame('stripe_subscription', $row['gateway']);
        self::assertSame('order-1', $row['reference']);
    }

    public function testTheSessionIsLinkedThroughSubscriptionReferenceOf(): void
    {
        $this->ledger->record($this->delivered('evt_1', 'customer.subscription.created'));

        $payload = json_encode([
            'id' => 'evt_cs',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_123',
                'object' => 'checkout.session',
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => 2900,
                'currency' => 'usd',
                'subscription' => 'sub_test_1',
                'invoice' => 'in_test_1',
            ]],
        ], JSON_THROW_ON_ERROR);
        $adapter = $this->adapter();
        $sessionEvent = $adapter->parseCallback($payload, self::signedHeaders($payload));

        self::assertTrue($this->ledger->linkTransaction($adapter->subscriptionReferenceOf($sessionEvent), 'cs_test_123'));

        $row = $this->ledger->findByTransactionReference('cs_test_123');

        self::assertNotNull($row);
        self::assertSame('sub_test_1', $row['gateway_reference']);
    }

    public function testSameSecondPausedAndUpdatedSiblingsAreRecognisedOnRedelivery(): void
    {
        $paused = $this->delivered('evt_paused', 'customer.subscription.paused', ['status' => 'paused'], 1700000200);
        $updated = $this->delivered('evt_updated', 'customer.subscription.updated', ['status' => 'paused'], 1700000200);

        self::assertTrue($this->ledger->record($paused));
        self::assertTrue($this->ledger->record($updated));

        self::assertFalse($this->ledger->record($paused));
        self::assertFalse($this->ledger->record($updated));
    }

    public function testAnOlderUpdatedEventDoesNotOverwriteANewerCancellation(): void
    {
        $canceled = $this->delivered('evt_deleted', 'customer.subscription.deleted', ['status' => 'canceled'], 1700000300);
        $older = $this->delivered('evt_older', 'customer.subscription.updated', ['status' => 'active'], 1700000200);

        self::assertTrue($this->ledger->record($canceled));
        self::assertFalse($this->ledger->record($older));

        self::assertSame('cancelled', $this->ledger->findByGatewayReference('sub_test_1')['status']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function delivered(string $eventId, string $type, array $overrides = [], int $created = 1700000123): SubscriptionEvent
    {
        $payload = json_encode([
            'id' => $eventId,
            'type' => $type,
            'created' => $created,
            'data' => ['object' => self::subscription($overrides)],
        ], JSON_THROW_ON_ERROR);

        return $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    private function adapter(): StripeSubscription
    {
        return new StripeSubscription(
            'sk_test_key',
            new FakeHttpClient(static fn (): never => throw new LogicException('No HTTP call expected.')),
            new BillingCycle(BillingInterval::Month),
            null,
            self::WEBHOOK_SECRET
        );
    }

    /**
     * An endive-shape subscription: periods only on the items, `flexible` billing mode, and the
     * merchant reference stamped into metadata.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function subscription(array $overrides = []): array
    {
        return array_merge([
            'id' => 'sub_test_1',
            'object' => 'subscription',
            'status' => 'active',
            'customer' => 'cus_test_1',
            'metadata' => ['reference' => 'order-1'],
            'billing_mode' => ['type' => 'flexible'],
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'current_period_start' => null,
            'current_period_end' => null,
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_2900',
                'object' => 'subscription_item',
                'quantity' => 1,
                'current_period_start' => 1700000000,
                'current_period_end' => 1702592000,
                'price' => [
                    'id' => 'price_2900',
                    'unit_amount' => 2900,
                    'currency' => 'usd',
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ],
            ]]],
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    private static function signedHeaders(string $payload): array
    {
        $timestamp = time();

        return ['Stripe-Signature' => sprintf('t=%d,v1=%s', $timestamp, HMAC::sign($timestamp . '.' . $payload, self::WEBHOOK_SECRET))];
    }
}
