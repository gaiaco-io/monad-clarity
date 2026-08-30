<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\CheckoutAdapters;

use DateTimeImmutable;
use Monad\Clarity\Services\Checkout\BillingCycle;
use Monad\Clarity\Services\Checkout\BillingInterval;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\LineItem;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\PaymentFailureBehaviour;
use Monad\Clarity\Services\Checkout\ProrationBillingMode;
use Monad\Clarity\Services\Checkout\ResumeBilling;
use Monad\Clarity\Services\Checkout\ScheduledChangeAction;
use Monad\Clarity\Services\Checkout\SubscriptionEffectiveFrom;
use Monad\Clarity\Services\Checkout\SubscriptionItem;
use Monad\Clarity\Services\Checkout\SubscriptionStatus;
use Monad\Clarity\Services\Checkout\TransactionStatus;
use Monad\Clarity\Console\CheckoutInstall;
use Monad\Clarity\Services\Checkout\TransactionLedger;
use Monad\Clarity\Services\CheckoutAdapters\PaddleSubscription;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Event;
use Monad\Clarity\Services\Schema;
use PDO;
use Monad\Clarity\Utils\HMAC;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaddleSubscriptionTest extends TestCase
{
    private const WEBHOOK_SECRET = 'pdl_ntfset_test_secret';
    private const PAYMENT_PAGE_URL = 'https://shop.test/subscribe';
    private const SUBSCRIPTION_ID = 'sub_test_123';

    // ---------------------------------------------------------------- createCheckout — §9.6.1

    public function testCreateCheckoutSendsABillingCycleOnEveryInlinePrice(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest());

        $items = $fake->decodedLastRequestBody()['items'];
        self::assertSame(['interval' => 'month', 'frequency' => 1], $items[0]['price']['billing_cycle']);
    }

    /**
     * The exact inverse of PaddleCheckout's guarantee, asserted just as explicitly — the
     * presence of this key is the whole difference between a payment and a subscription.
     */
    public function testCreateCheckoutSendsTheBillingCycleOnEveryLineItemNotJustTheFirst(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake, new BillingCycle(BillingInterval::Year, 2))->createCheckout(
            $this->checkoutRequest([
                new LineItem('Pro plan', new Money(2000, 'USD')),
                new LineItem('Extra seat', new Money(500, 'USD')),
            ])
        );

        foreach ($fake->decodedLastRequestBody()['items'] as $item) {
            self::assertSame(['interval' => 'year', 'frequency' => 2], $item['price']['billing_cycle']);
        }
    }

    public function testCreateCheckoutSendsATrialPeriodWhenThePlanCarriesOne(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake, trialPeriod: new BillingCycle(BillingInterval::Day, 14))
            ->createCheckout($this->checkoutRequest());

        self::assertSame(
            ['interval' => 'day', 'frequency' => 14],
            $fake->decodedLastRequestBody()['items'][0]['price']['trial_period']
        );
    }

    public function testCreateCheckoutOmitsTrialPeriodWhenThePlanHasNone(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest());

        self::assertArrayNotHasKey('trial_period', $fake->decodedLastRequestBody()['items'][0]['price']);
    }

    /**
     * There is no POST /subscriptions. The sub_ does not exist until the customer pays, so
     * anything that returned one here would be inventing it.
     */
    public function testCreateCheckoutReturnsATransactionReferenceNotASubscriptionReference(): void
    {
        $session = $this->adapter()->createCheckout($this->checkoutRequest());

        self::assertSame('paddle_subscription', $session->gateway);
        self::assertSame('txn_test_123', $session->gatewayReference);
        self::assertStringStartsNotWith('sub_', $session->gatewayReference);
        self::assertNull($session->paymentReference);
    }

    public function testCreateCheckoutRefusesToRunWithoutACheckoutUrl(): void
    {
        $adapter = new PaddleSubscription(
            'pdl_sdbx_apikey_test',
            new FakeHttpClient(static fn (): Response => self::transactionResponse()),
            new BillingCycle(BillingInterval::Month),
            webhookSecret: self::WEBHOOK_SECRET,
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/This PaddleSubscription was constructed without a checkout URL/');

        $adapter->createCheckout($this->checkoutRequest());
    }

    // ------------------------------------------------- retrieveStatus (inherited) — §9.6.3

    /**
     * The one mapping that deliberately differs from PaddleCheckout. On a renewal past_due is
     * dunning, not a dead payment: Paddle keeps retrying and the charge often completes. Failed
     * is terminal, and the ledger refuses to move a transaction away from a terminal status —
     * so inheriting PaddleCheckout's mapping would lock every recovered renewal at failed.
     */
    public function testPastDueIsPendingOnARenewalRatherThanFailed(): void
    {
        $fake = new FakeHttpClient(
            static fn (): Response => self::transactionResponse(['status' => 'past_due'])
        );

        self::assertSame(
            TransactionStatus::Pending,
            $this->adapter($fake)->retrieveStatus('txn_test_123')->status
        );
    }

    #[DataProvider('transactionStates')]
    public function testRetrieveStatusMapsTheRemainingTransactionStates(string $paddle, TransactionStatus $expected): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse(['status' => $paddle]));

        self::assertSame($expected, $this->adapter($fake)->retrieveStatus('txn_test_123')->status);
    }

    /**
     * @return list<array{string, TransactionStatus}>
     */
    public static function transactionStates(): array
    {
        return [
            ['draft', TransactionStatus::Pending],
            ['ready', TransactionStatus::Pending],
            ['billed', TransactionStatus::Pending],
            ['paid', TransactionStatus::Success],
            ['completed', TransactionStatus::Success],
            ['canceled', TransactionStatus::Cancelled],
            ['something_new', TransactionStatus::Pending],
        ];
    }

    // ------------------------------------------------------------ the txn_ → sub_ bridge

    public function testSubscriptionReferenceIsReadOffASettledTransaction(): void
    {
        $fake = new FakeHttpClient(
            static fn (): Response => self::transactionResponse([
                'status' => 'completed',
                'subscription_id' => self::SUBSCRIPTION_ID,
            ])
        );

        $adapter = $this->adapter($fake);
        $snapshot = $adapter->retrieveStatus('txn_test_123');

        self::assertSame(self::SUBSCRIPTION_ID, $adapter->subscriptionReferenceOf($snapshot));
    }

    /**
     * An unpaid transaction genuinely has no subscription — Paddle creates one only when money
     * moves — so null is the honest answer rather than an error.
     */
    public function testSubscriptionReferenceIsNullWhileTheTransactionIsUnpaid(): void
    {
        $adapter = $this->adapter();

        self::assertNull($adapter->subscriptionReferenceOf($adapter->retrieveStatus('txn_test_123')));
    }

    public function testSubscriptionReferenceIsAlsoReadOffATransactionCallback(): void
    {
        $payload = json_encode([
            'event_id' => 'evt_1',
            'event_type' => 'transaction.completed',
            'occurred_at' => '2026-08-31T10:00:00Z',
            'data' => [
                'id' => 'txn_test_123',
                'status' => 'completed',
                'subscription_id' => self::SUBSCRIPTION_ID,
            ],
        ], JSON_THROW_ON_ERROR);

        $adapter = $this->adapter();
        $event = $adapter->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame(self::SUBSCRIPTION_ID, $adapter->subscriptionReferenceOf($event));
    }

    // ------------------------------------------------------------------ retrieveSubscription

    public function testRetrieveSubscriptionNormalisesTheGatewaysPayload(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $snapshot = $this->adapter($fake)->retrieveSubscription(self::SUBSCRIPTION_ID);

        self::assertSame('paddle_subscription', $snapshot->gateway);
        self::assertSame(self::SUBSCRIPTION_ID, $snapshot->gatewayReference);
        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
        self::assertSame('ctm_test_1', $snapshot->customerReference);
        self::assertSame('order-9', $snapshot->reference);
        self::assertSame('txn_test_123', $snapshot->transactionReference);
        self::assertNotNull($snapshot->recurringAmount);
        self::assertSame(2500, $snapshot->recurringAmount->minorUnits);
        self::assertSame('USD', $snapshot->recurringAmount->currency);
        self::assertNotNull($snapshot->billingCycle);
        self::assertTrue($snapshot->billingCycle->equals(new BillingCycle(BillingInterval::Month)));
        self::assertNull($snapshot->scheduledChange);

        $request = $fake->lastRequest();
        self::assertNotNull($request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.paddle.com/subscriptions/sub_test_123', (string) $request->getUri());
    }

    public function testRetrieveSubscriptionSumsSeveralItemsIntoTheRecurringAmount(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse([
            'items' => [
                self::subscriptionItem('2000', 2),
                self::subscriptionItem('500', 1),
            ],
        ]));

        $snapshot = $this->adapter($fake)->retrieveSubscription(self::SUBSCRIPTION_ID);

        self::assertNotNull($snapshot->recurringAmount);
        self::assertSame(4500, $snapshot->recurringAmount->minorUnits);
    }

    public function testRetrieveSubscriptionReadsAPausedSubscriptionWithNoBillingPeriod(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse([
            'status' => 'paused',
            'current_billing_period' => null,
            'next_billed_at' => null,
        ]));

        $snapshot = $this->adapter($fake)->retrieveSubscription(self::SUBSCRIPTION_ID);

        self::assertSame(SubscriptionStatus::Paused, $snapshot->status);
        self::assertNull($snapshot->currentPeriodEndsAt);
        self::assertNull($snapshot->nextBilledAt);
        self::assertNull($snapshot->accessEndsAt());
    }

    public function testRetrieveSubscriptionRefusesATransactionReferenceByName(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/is a transaction id, not a subscription id/');

        $this->adapter()->retrieveSubscription('txn_test_123');
    }

    public function testRetrieveSubscriptionRefusesAnUnrecognisableReference(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/needs a Paddle subscription id/');

        $this->adapter()->retrieveSubscription('ctm_test_1');
    }

    // -------------------------------------- parseSubscriptionCallback — §9.6.4, Tier 1

    public function testParseSubscriptionCallbackAcceptsAValidlySignedEventAndNormalisesIt(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.created');

        $event = $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));

        self::assertSame('paddle_subscription', $event->gateway);
        self::assertSame('evt_1', $event->eventId);
        self::assertSame('subscription.created', $event->eventType);
        self::assertSame('2026-08-31T10:00:00+00:00', $event->occurredAt->format(DATE_ATOM));
        self::assertSame(self::SUBSCRIPTION_ID, $event->subscription->gatewayReference);
        self::assertSame(SubscriptionStatus::Active, $event->subscription->status);
        self::assertSame('order-9', $event->subscription->reference);
        self::assertSame('txn_test_123', $event->subscription->transactionReference);
    }

    public function testParseSubscriptionCallbackRejectsATamperedPayload(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.created');
        $headers = self::signedHeaders($payload);
        $tampered = str_replace('active', 'paused', $payload);

        self::assertNotSame($payload, $tampered, 'The tamper must actually change the body.');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseSubscriptionCallback($tampered, $headers);
    }

    public function testParseSubscriptionCallbackRejectsASignatureFromTheWrongSecret(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.created');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseSubscriptionCallback(
            $payload,
            self::signedHeaders($payload, secret: 'pdl_ntfset_a_different_secret')
        );
    }

    public function testParseSubscriptionCallbackRejectsATimestampOutsideTheReplayTolerance(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.created');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/refusing it as a replay/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload, time() - 400));
    }

    public function testParseSubscriptionCallbackRejectsAMissingSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/no Paddle-Signature header/');

        $this->adapter()->parseSubscriptionCallback(self::eventPayload('evt_1', 'subscription.created'), []);
    }

    public function testParseSubscriptionCallbackRejectsAMalformedSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/was malformed/');

        $this->adapter()->parseSubscriptionCallback(
            self::eventPayload('evt_1', 'subscription.created'),
            ['Paddle-Signature' => 'not-a-signature']
        );
    }

    public function testParseSubscriptionCallbackRefusesToRunAtAllWithoutASigningSecret(): void
    {
        $adapter = new PaddleSubscription(
            'pdl_sdbx_apikey_test',
            new FakeHttpClient(static fn (): Response => self::subscriptionResponse()),
            new BillingCycle(BillingInterval::Month),
            paymentPageUrl: self::PAYMENT_PAGE_URL,
        );

        $payload = self::eventPayload('evt_1', 'subscription.created');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/without a webhook signing secret/');

        $adapter->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    public function testParseSubscriptionCallbackMatchesTheSignatureHeaderCaseInsensitively(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.created');
        $timestamp = time();

        $event = $this->adapter()->parseSubscriptionCallback($payload, [
            'paddle-signature' => sprintf(
                'ts=%d;h1=%s',
                $timestamp,
                HMAC::sign($timestamp . ':' . $payload, self::WEBHOOK_SECRET)
            ),
        ]);

        self::assertSame('evt_1', $event->eventId);
    }

    public function testParseSubscriptionCallbackAcceptsAnyOneOfSeveralSignaturesDuringSecretRotation(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.created');
        $timestamp = time();

        $event = $this->adapter()->parseSubscriptionCallback($payload, [
            'Paddle-Signature' => sprintf(
                'ts=%d;h1=%s;h1=%s',
                $timestamp,
                HMAC::sign($timestamp . ':' . $payload, 'pdl_ntfset_the_outgoing_secret'),
                HMAC::sign($timestamp . ':' . $payload, self::WEBHOOK_SECRET)
            ),
        ]);

        self::assertSame('evt_1', $event->eventId);
    }

    #[DataProvider('nonSubscriptionEvents')]
    public function testParseSubscriptionCallbackRefusesAnEventThatIsNotASubscriptionEvent(
        string $type,
        string $id
    ): void {
        $payload = self::eventPayload('evt_1', $type, ['id' => $id]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/not a subscription event/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function nonSubscriptionEvents(): array
    {
        return [
            ['transaction.completed', 'txn_test_123'],
            ['customer.created', 'ctm_test_1'],
            ['adjustment.updated', 'adj_test_1'],
            ['price.updated', 'pri_test_1'],
        ];
    }

    public function testParseSubscriptionCallbackRefusesASubscriptionEventCarryingNoSubscriptionId(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.updated', ['id' => 'txn_test_123']);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/where a sub_ subscription id was expected/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    /**
     * The documented ordering, as an executable assertion. A non-subscription event signed with
     * the WRONG secret must be refused for the signature, not for the event type — proving
     * nothing is parsed before the bytes are proven authentic. 1.3.0's sandbox run could only
     * observe this live; here it is pinned.
     */
    public function testParseSubscriptionCallbackVerifiesTheSignatureBeforeTheEventGuard(): void
    {
        $payload = self::eventPayload('evt_1', 'customer.created', ['id' => 'ctm_test_1']);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseSubscriptionCallback(
            $payload,
            self::signedHeaders($payload, secret: 'pdl_ntfset_a_different_secret')
        );
    }

    public function testParseSubscriptionCallbackRefusesAnEventCarryingNoOccurredAt(): void
    {
        $payload = json_encode([
            'event_id' => 'evt_1',
            'event_type' => 'subscription.created',
            'data' => ['id' => self::SUBSCRIPTION_ID, 'status' => 'active'],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/carried no readable occurred_at/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    /**
     * There is no safe direction to guess in: defaulting to paused locks out a paying customer,
     * defaulting to active gives the product away. So the event is refused and a human looks.
     */
    public function testParseSubscriptionCallbackRefusesAnUnrecognisedSubscriptionStatus(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.updated', ['status' => 'hibernating']);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/does not recognise|no safe default/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    #[DataProvider('subscriptionStates')]
    public function testParseSubscriptionCallbackMapsEverySubscriptionState(
        string $paddle,
        SubscriptionStatus $expected
    ): void {
        $payload = self::eventPayload('evt_1', 'subscription.updated', ['status' => $paddle]);

        $event = $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));

        self::assertSame($expected, $event->subscription->status);
    }

    /**
     * @return list<array{string, SubscriptionStatus}>
     */
    public static function subscriptionStates(): array
    {
        return [
            ['active', SubscriptionStatus::Active],
            ['trialing', SubscriptionStatus::Trialing],
            ['past_due', SubscriptionStatus::PastDue],
            ['paused', SubscriptionStatus::Paused],
            ['canceled', SubscriptionStatus::Cancelled],
        ];
    }

    #[DataProvider('subscriptionEventTypes')]
    public function testParseSubscriptionCallbackAcceptsEverySubscriptionEventPaddlePublishes(string $type): void
    {
        $payload = self::eventPayload('evt_1', $type);

        self::assertSame(
            $type,
            $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload))->eventType
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function subscriptionEventTypes(): array
    {
        return [
            ['subscription.created'], ['subscription.updated'], ['subscription.activated'],
            ['subscription.trialing'], ['subscription.past_due'], ['subscription.paused'],
            ['subscription.resumed'], ['subscription.canceled'], ['subscription.imported'],
        ];
    }

    /**
     * The cancellation trap, arriving as a real delivery: the customer has cancelled, and the
     * subscription is still active until the period they paid for runs out.
     */
    public function testParseSubscriptionCallbackReadsAPendingCancellationWithoutEndingTheSubscription(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.updated', [
            'scheduled_change' => [
                'action' => 'cancel',
                'effective_at' => '2026-09-30T00:00:00Z',
                'resume_at' => null,
            ],
        ]);

        $subscription = $this->adapter()
            ->parseSubscriptionCallback($payload, self::signedHeaders($payload))
            ->subscription;

        self::assertSame(SubscriptionStatus::Active, $subscription->status);
        self::assertTrue($subscription->isCancelling());
        self::assertFalse($subscription->status->isTerminal());
        self::assertSame(
            ScheduledChangeAction::Cancel,
            $subscription->scheduledChange?->action
        );
        self::assertSame('2026-09-30T00:00:00+00:00', $subscription->accessEndsAt()?->format(DATE_ATOM));
    }

    public function testParseSubscriptionCallbackReadsAScheduledPauseWithItsResumeDate(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.updated', [
            'scheduled_change' => [
                'action' => 'pause',
                'effective_at' => '2026-09-30T00:00:00Z',
                'resume_at' => '2026-12-01T00:00:00Z',
            ],
        ]);

        $subscription = $this->adapter()
            ->parseSubscriptionCallback($payload, self::signedHeaders($payload))
            ->subscription;

        self::assertTrue($subscription->isPausing());
        self::assertSame('2026-12-01T00:00:00+00:00', $subscription->scheduledChange?->resumeAt?->format(DATE_ATOM));
    }

    public function testParseSubscriptionCallbackRefusesAScheduledChangeItCannotRead(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.updated', [
            'scheduled_change' => ['action' => 'reticulate', 'effective_at' => '2026-09-30T00:00:00Z'],
        ]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/scheduled change this adapter could not read/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    /**
     * The two parsers are separate doors. A subscription event must not slip through the
     * transaction one, exactly as PaddleCheckout already refuses it.
     */
    public function testParseCallbackStillRefusesSubscriptionEventsOnThisAdapter(): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.created');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/not a transaction event/');

        $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
    }

    /**
     * A Tier 1 parser promises callers one exception type. Money validates what it is given and
     * throws InvalidArgumentException, which is right for a value object and wrong to let out
     * of a webhook parser — a handler catching CheckoutException would take an uncaught fatal
     * on a payload that verified.
     */
    #[DataProvider('unreadableItemAmounts')]
    public function testParseSubscriptionCallbackReportsUnreadableAmountsAsACheckoutException(array $items): void
    {
        $payload = self::eventPayload('evt_1', 'subscription.updated', ['items' => $items]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/could not read as a recurring charge/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    /**
     * @return array<string, array{list<array<string, mixed>>}>
     */
    public static function unreadableItemAmounts(): array
    {
        return [
            'a negative unit price' => [[[
                'quantity' => 1,
                'price' => ['unit_price' => ['amount' => '-100', 'currency_code' => 'USD']],
            ]]],
            'a currency code that is not ISO 4217' => [[[
                'quantity' => 1,
                'price' => ['unit_price' => ['amount' => '100', 'currency_code' => 'US']],
            ]]],
            'two items in different currencies' => [[
                ['quantity' => 1, 'price' => ['unit_price' => ['amount' => '100', 'currency_code' => 'USD']]],
                ['quantity' => 1, 'price' => ['unit_price' => ['amount' => '100', 'currency_code' => 'EUR']]],
            ]],
        ];
    }

    // -------------------------------------- the renewal recovery, end to end through the ledger

    /**
     * The defect this adapter's status mapping exists to prevent, proved against a real ledger
     * rather than asserted in a docblock.
     *
     * A renewal fails, Paddle enters dunning, and days later the retry succeeds. Under
     * PaddleCheckout's mapping past_due would be Failed — which is terminal, and
     * TransactionLedger refuses to move a transaction away from a settled status — so the
     * recovered renewal would stay `failed` in the merchant's books for good. Mapping it to
     * Pending is what lets the later success actually land.
     */
    public function testARenewalThatGoesPastDueAndLaterCompletesEndsAsSuccess(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));
        Schema::createTable(TransactionLedger::TRANSACTIONS_TABLE, CheckoutInstall::transactionsBlueprint());
        Schema::createTable(TransactionLedger::STATUSES_TABLE, CheckoutInstall::statusesBlueprint());
        Schema::createTable(TransactionLedger::REFUNDS_TABLE, CheckoutInstall::refundsBlueprint());

        try {
            $ledger = new TransactionLedger();

            $pastDue = new FakeHttpClient(
                static fn (): Response => self::transactionResponse(['status' => 'past_due'])
            );
            $adapter = $this->adapter($pastDue);

            $ledger->open($this->checkoutRequest(), $adapter->createCheckout($this->checkoutRequest()));

            // The renewal is refused, and Paddle starts retrying.
            $ledger->recordSnapshot($adapter->retrieveStatus('txn_test_123'));
            self::assertSame('pending', $ledger->findByGatewayReference('txn_test_123')['status']);

            // Days later the retry goes through.
            $completed = new FakeHttpClient(
                static fn (): Response => self::transactionResponse(['status' => 'completed'])
            );
            $ledger->recordSnapshot($this->adapter($completed)->retrieveStatus('txn_test_123'));

            self::assertSame(
                'success',
                $ledger->findByGatewayReference('txn_test_123')['status'],
                'A recovered renewal must reach success; Failed would have been terminal and locked it out.'
            );
        } finally {
            DB::reset();
            Event::forget();
        }
    }

    // ------------------------------------------------- cancel / pause / resume

    /**
     * The headline case. A customer clicks cancel; they have paid through the end of the
     * period, so Paddle schedules the cancellation and the subscription STAYS ACTIVE. Any
     * application that revoked access on this response would be taking away paid-for service.
     */
    public function testCancelForTheNextBillingPeriodLeavesTheSubscriptionActiveWithAScheduledChange(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse([
            'status' => 'active',
            'scheduled_change' => [
                'action' => 'cancel',
                'effective_at' => '2026-09-30T00:00:00Z',
                'resume_at' => null,
            ],
        ]));

        $snapshot = $this->adapter($fake)->cancel(
            self::SUBSCRIPTION_ID,
            SubscriptionEffectiveFrom::NextBillingPeriod
        );

        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
        self::assertFalse($snapshot->status->isTerminal());
        self::assertTrue($snapshot->isCancelling());
        self::assertSame('2026-09-30T00:00:00+00:00', $snapshot->accessEndsAt()?->format(DATE_ATOM));

        $request = $fake->lastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.paddle.com/subscriptions/sub_test_123/cancel', (string) $request->getUri());
        self::assertSame(['effective_from' => 'next_billing_period'], $fake->decodedLastRequestBody());
    }

    public function testCancelImmediatelyEndsTheSubscription(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse([
            'status' => 'canceled',
            'current_billing_period' => null,
        ]));

        $snapshot = $this->adapter($fake)->cancel(self::SUBSCRIPTION_ID, SubscriptionEffectiveFrom::Immediately);

        self::assertSame(SubscriptionStatus::Cancelled, $snapshot->status);
        self::assertTrue($snapshot->status->isTerminal());
        self::assertFalse($snapshot->isCancelling(), 'It has cancelled; nothing is pending.');
        self::assertSame(['effective_from' => 'immediately'], $fake->decodedLastRequestBody());
    }

    /**
     * Paddle refuses `on_resume` without `resume_at` — so an indefinite pause sends neither.
     * The mocked suite accepted the invalid pairing until a live sandbox call rejected it.
     */
    public function testAnIndefinitePauseSendsOnlyItsEffectiveFrom(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $this->adapter($fake)->pause(self::SUBSCRIPTION_ID, SubscriptionEffectiveFrom::NextBillingPeriod);

        self::assertSame(
            'https://api.paddle.com/subscriptions/sub_test_123/pause',
            (string) $fake->lastRequest()?->getUri()
        );
        self::assertSame(['effective_from' => 'next_billing_period'], $fake->decodedLastRequestBody());
    }

    public function testPauseRefusesResumeBillingWithoutAResumeDate(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        try {
            $this->adapter($fake)->pause(
                self::SUBSCRIPTION_ID,
                SubscriptionEffectiveFrom::Immediately,
                null,
                ResumeBilling::ContinueExistingBillingPeriod
            );
            self::fail('on_resume without resume_at should have been refused.');
        } catch (CheckoutException $e) {
            self::assertMatchesRegularExpression('/if it also says when to resume/', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount(), 'Nothing should reach Paddle.');
    }

    public function testPauseSendsAResumeDateAsUtcRfc3339(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $this->adapter($fake)->pause(
            self::SUBSCRIPTION_ID,
            SubscriptionEffectiveFrom::Immediately,
            new DateTimeImmutable('2026-12-01T09:00:00+05:00'),
            ResumeBilling::ContinueExistingBillingPeriod
        );

        $body = $fake->decodedLastRequestBody();
        self::assertSame('2026-12-01T04:00:00+00:00', $body['resume_at']);
        self::assertSame('continue_existing_billing_period', $body['on_resume']);
        self::assertSame('immediately', $body['effective_from']);
    }

    /**
     * An absent resume_at is an indefinite pause, which is a different instruction from
     * "resume at no particular time" — so the key is omitted, never sent as null.
     */
    public function testPauseOmitsResumeAtEntirelyWhenNoneIsGiven(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $this->adapter($fake)->pause(self::SUBSCRIPTION_ID, SubscriptionEffectiveFrom::Immediately);

        $body = $fake->decodedLastRequestBody();
        self::assertArrayNotHasKey('resume_at', $body);
        self::assertArrayNotHasKey('on_resume', $body, 'Paddle refuses on_resume without resume_at.');
    }

    public function testResumePostsToTheResumeEndpoint(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $this->adapter($fake)->resume(self::SUBSCRIPTION_ID, SubscriptionEffectiveFrom::Immediately);

        self::assertSame(
            'https://api.paddle.com/subscriptions/sub_test_123/resume',
            (string) $fake->lastRequest()?->getUri()
        );
        self::assertSame([
            'effective_from' => 'immediately',
            'on_resume' => 'start_new_billing_period',
        ], $fake->decodedLastRequestBody());
    }

    /**
     * Paddle accepts only `immediately` for a resume and answers anything else with a bare
     * `bad_request` / "Invalid request." naming nothing — confirmed live. Refusing it here is
     * the difference between a caller knowing what they did wrong and not.
     */
    public function testResumeRefusesNextBillingPeriodBecausePaddleNeverAcceptsIt(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        try {
            $this->adapter($fake)->resume(self::SUBSCRIPTION_ID, SubscriptionEffectiveFrom::NextBillingPeriod);
            self::fail('A next-billing-period resume should have been refused.');
        } catch (CheckoutException $e) {
            self::assertMatchesRegularExpression('/can only be resumed immediately/', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount(), 'Nothing should reach Paddle.');
    }

    /**
     * While a change is scheduled, Paddle refuses every further change to the subscription, so
     * without this a customer who changed their mind would have to wait out their own
     * cancellation.
     */
    public function testRemoveScheduledChangePatchesTheChangeAway(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $snapshot = $this->adapter($fake)->removeScheduledChange(self::SUBSCRIPTION_ID);

        $request = $fake->lastRequest();
        self::assertNotNull($request);
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('https://api.paddle.com/subscriptions/sub_test_123', (string) $request->getUri());
        self::assertSame(['scheduled_change' => null], $fake->decodedLastRequestBody());
        self::assertNull($snapshot->scheduledChange);
        self::assertFalse($snapshot->isCancelling());
    }

    public function testRemoveScheduledChangeRefusesATransactionReference(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/is a transaction id, not a subscription id/');

        $this->adapter()->removeScheduledChange('txn_test_123');
    }

    #[DataProvider('lifecycleOperations')]
    public function testEveryLifecycleOperationRefusesATransactionReference(string $operation): void
    {
        $adapter = $this->adapter();

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/is a transaction id, not a subscription id/');

        match ($operation) {
            'cancel' => $adapter->cancel('txn_test_123', SubscriptionEffectiveFrom::Immediately),
            'pause' => $adapter->pause('txn_test_123', SubscriptionEffectiveFrom::Immediately),
            'resume' => $adapter->resume('txn_test_123', SubscriptionEffectiveFrom::Immediately),
            'changePlan' => $adapter->changePlan(
                'txn_test_123',
                [SubscriptionItem::catalogPrice('pri_1')],
                ProrationBillingMode::ProratedImmediately
            ),
        };
    }

    /**
     * @return list<array{string}>
     */
    public static function lifecycleOperations(): array
    {
        return [['cancel'], ['pause'], ['resume'], ['changePlan']];
    }

    // ------------------------------------------------------------------------ changePlan

    public function testChangePlanPatchesTheSubscriptionWithCatalogPrices(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $this->adapter($fake)->changePlan(
            self::SUBSCRIPTION_ID,
            [SubscriptionItem::catalogPrice('pri_pro_monthly', 2)],
            ProrationBillingMode::ProratedImmediately
        );

        $request = $fake->lastRequest();
        self::assertNotNull($request);
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('https://api.paddle.com/subscriptions/sub_test_123', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('1', $request->getHeaderLine('Paddle-Version'));

        self::assertSame([
            'items' => [['price_id' => 'pri_pro_monthly', 'quantity' => 2]],
            'proration_billing_mode' => 'prorated_immediately',
            'on_payment_failure' => 'prevent_change',
        ], $fake->decodedLastRequestBody());
    }

    /**
     * The correction that matters: Paddle's update endpoint takes inline non-catalogue prices
     * too, so a merchant is never forced to seed a catalogue just to offer an upgrade button.
     */
    public function testChangePlanAcceptsInlinePricesSoNoCatalogueIsNeeded(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $this->adapter($fake)->changePlan(
            self::SUBSCRIPTION_ID,
            [SubscriptionItem::inline(
                new LineItem('Pro plan', new Money(5000, 'USD')),
                new BillingCycle(BillingInterval::Year)
            )],
            ProrationBillingMode::ProratedImmediately
        );

        $item = $fake->decodedLastRequestBody()['items'][0];
        self::assertArrayNotHasKey('price_id', $item);
        self::assertSame('Pro plan', $item['price']['name']);
        self::assertSame('5000', $item['price']['unit_price']['amount']);
        self::assertSame(['interval' => 'year', 'frequency' => 1], $item['price']['billing_cycle']);
    }

    #[DataProvider('prorationModes')]
    public function testChangePlanAlwaysStatesItsProrationMode(ProrationBillingMode $mode): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $this->adapter($fake)->changePlan(
            self::SUBSCRIPTION_ID,
            [SubscriptionItem::catalogPrice('pri_pro_monthly')],
            $mode
        );

        self::assertSame($mode->value, $fake->decodedLastRequestBody()['proration_billing_mode']);
    }

    /**
     * @return list<array{ProrationBillingMode}>
     */
    public static function prorationModes(): array
    {
        return array_map(static fn (ProrationBillingMode $m): array => [$m], ProrationBillingMode::cases());
    }

    public function testChangePlanSendsApplyChangeWhenTheMerchantAsksForIt(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $this->adapter($fake)->changePlan(
            self::SUBSCRIPTION_ID,
            [SubscriptionItem::catalogPrice('pri_pro_monthly')],
            ProrationBillingMode::ProratedImmediately,
            PaymentFailureBehaviour::ApplyChange
        );

        self::assertSame('apply_change', $fake->decodedLastRequestBody()['on_payment_failure']);
    }

    /**
     * The update replaces rather than appends, so an empty list is a cancellation wearing a
     * plan change's clothes. Refused before a request is sent.
     */
    public function testChangePlanRefusesAnEmptyItemListAndSaysToCancelInstead(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        try {
            $this->adapter($fake)->changePlan(self::SUBSCRIPTION_ID, [], ProrationBillingMode::DoNotBill);
            self::fail('An empty item set should have been refused.');
        } catch (CheckoutException $e) {
            self::assertMatchesRegularExpression('/Call cancel\(\) if that is what you mean/', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount(), 'Nothing should reach Paddle.');
    }

    public function testChangePlanRefusesInlineItemsThatMixBillingIntervals(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        try {
            $this->adapter($fake)->changePlan(
                self::SUBSCRIPTION_ID,
                [
                    SubscriptionItem::inline(
                        new LineItem('Pro plan', new Money(5000, 'USD')),
                        new BillingCycle(BillingInterval::Year)
                    ),
                    SubscriptionItem::inline(
                        new LineItem('Extra seat', new Money(500, 'USD')),
                        new BillingCycle(BillingInterval::Month)
                    ),
                ],
                ProrationBillingMode::ProratedImmediately
            );
            self::fail('Mixed billing intervals should have been refused.');
        } catch (CheckoutException $e) {
            self::assertMatchesRegularExpression('/must bill on the same cycle/', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount(), 'Nothing should reach Paddle.');
    }

    /**
     * A catalogue price keeps its interval in the gateway, so this adapter cannot see it. It
     * sends the change and lets Paddle refuse rather than guessing without the facts.
     */
    public function testChangePlanLeavesCatalogueIntervalsForPaddleToJudge(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::subscriptionResponse());

        $this->adapter($fake)->changePlan(
            self::SUBSCRIPTION_ID,
            [
                SubscriptionItem::catalogPrice('pri_pro_annual'),
                SubscriptionItem::inline(
                    new LineItem('Extra seat', new Money(500, 'USD')),
                    new BillingCycle(BillingInterval::Month)
                ),
            ],
            ProrationBillingMode::ProratedImmediately
        );

        self::assertSame(1, $fake->requestCount());
    }

    // ------------------------------------------------------------ fixtures

    private function adapter(
        ?FakeHttpClient $fake = null,
        ?BillingCycle $billingCycle = null,
        ?BillingCycle $trialPeriod = null,
    ): PaddleSubscription {
        return new PaddleSubscription(
            'pdl_sdbx_apikey_test',
            $fake ?? new FakeHttpClient(static fn (): Response => self::transactionResponse()),
            $billingCycle ?? new BillingCycle(BillingInterval::Month),
            $trialPeriod,
            self::WEBHOOK_SECRET,
            paymentPageUrl: self::PAYMENT_PAGE_URL,
        );
    }

    /**
     * @param list<LineItem> $lineItems
     */
    private function checkoutRequest(array $lineItems = []): CheckoutRequest
    {
        $amount = new Money(2500, 'USD');

        // CheckoutRequest refuses line items whose subtotals disagree with the total, so when
        // a test supplies its own items the total is summed from them rather than assumed.
        if ($lineItems !== []) {
            $amount = new Money(0, 'USD');

            foreach ($lineItems as $item) {
                $amount = $amount->plus($item->subtotal());
            }
        }

        return new CheckoutRequest(
            reference: 'order-9',
            amount: $amount,
            successUrl: 'https://shop.test/thanks',
            cancelUrl: 'https://shop.test/cancelled',
            lineItems: $lineItems,
            customerEmail: 'buyer@shop.test',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function transactionResponse(array $overrides = []): Response
    {
        return self::jsonResponse(['data' => array_merge([
            'id' => 'txn_test_123',
            'status' => 'draft',
            'currency_code' => 'USD',
            'subscription_id' => null,
            'checkout' => ['url' => 'https://shop.test/subscribe?_ptxn=txn_test_123'],
            'details' => ['totals' => ['grand_total' => '2500']],
        ], $overrides)]);
    }

    /**
     * A raw webhook body, as bytes — signatures are computed over exact bytes, so tests must
     * sign the same string the parser reads.
     *
     * @param array<string, mixed> $overrides
     */
    private static function eventPayload(string $eventId, string $type, array $overrides = []): string
    {
        return json_encode([
            'event_id' => $eventId,
            'event_type' => $type,
            'occurred_at' => '2026-08-31T10:00:00Z',
            'data' => array_merge([
                'id' => self::SUBSCRIPTION_ID,
                'status' => 'active',
                'customer_id' => 'ctm_test_1',
                'transaction_id' => 'txn_test_123',
                'currency_code' => 'USD',
                'custom_data' => ['reference' => 'order-9'],
                'billing_cycle' => ['interval' => 'month', 'frequency' => 1],
                'items' => [self::subscriptionItem('2500', 1)],
            ], $overrides),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function subscriptionResponse(array $overrides = []): Response
    {
        return self::jsonResponse(['data' => array_merge([
            'id' => self::SUBSCRIPTION_ID,
            'status' => 'active',
            'customer_id' => 'ctm_test_1',
            'transaction_id' => 'txn_test_123',
            'currency_code' => 'USD',
            'custom_data' => ['reference' => 'order-9'],
            'billing_cycle' => ['interval' => 'month', 'frequency' => 1],
            'next_billed_at' => '2026-09-30T00:00:00Z',
            'current_billing_period' => ['starts_at' => '2026-08-30T00:00:00Z', 'ends_at' => '2026-09-30T00:00:00Z'],
            'scheduled_change' => null,
            'items' => [self::subscriptionItem('2500', 1)],
        ], $overrides)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function subscriptionItem(string $amount, int $quantity): array
    {
        return [
            'quantity' => $quantity,
            'price' => ['unit_price' => ['amount' => $amount, 'currency_code' => 'USD']],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function signedHeaders(
        string $payload,
        ?int $timestamp = null,
        string $secret = self::WEBHOOK_SECRET
    ): array {
        $timestamp ??= time();

        return [
            'Paddle-Signature' => sprintf('ts=%d;h1=%s', $timestamp, HMAC::sign($timestamp . ':' . $payload, $secret)),
        ];
    }
}
