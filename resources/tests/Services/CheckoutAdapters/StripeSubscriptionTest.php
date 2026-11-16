<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\CheckoutAdapters;

use Monad\Clarity\Services\Checkout\BillingCycle;
use Monad\Clarity\Services\Checkout\BillingInterval;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Services\Checkout\TransactionStatus;
use Monad\Clarity\Services\CheckoutAdapters\StripeSubscription;
use Monad\Clarity\Utils\HMAC;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class StripeSubscriptionTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_test_secret';

    // ---------------------------------------------------------------------------------
    // createCheckout
    // ---------------------------------------------------------------------------------

    public function testCreateCheckoutSendsASubscriptionModeSessionWithARecurringInlinePrice(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest('order-1'));

        $request = $fake->lastRequest();
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', (string) $request->getUri());
        self::assertSame('2026-09-30.endive', $request->getHeaderLine('Stripe-Version'));

        $body = $fake->decodedLastRequestForm();
        self::assertSame('subscription', $body['mode']);
        self::assertSame('order-1', $body['client_reference_id']);
        self::assertSame('month', $body['line_items'][0]['price_data']['recurring']['interval']);
        self::assertSame('1', $body['line_items'][0]['price_data']['recurring']['interval_count']);
        self::assertSame('usd', $body['line_items'][0]['price_data']['currency']);
        self::assertSame('order-1', $body['subscription_data']['metadata']['reference']);
        self::assertSame('flexible', $body['subscription_data']['billing_mode']['type']);
        self::assertArrayNotHasKey('payment_intent_data', $body);
    }

    public function testCreateCheckoutReturnsTheSessionNotASubscription(): void
    {
        $session = $this->adapter()->createCheckout($this->checkoutRequest());

        self::assertSame('stripe_subscription', $session->gateway);
        self::assertSame('cs_test_123', $session->gatewayReference);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_123', $session->redirectUrl);
        self::assertNull($session->paymentReference);
        self::assertSame(2900, $session->amount->minorUnits);
    }

    public function testCreateCheckoutSendsTheMerchantReferenceAsAnIdempotencyKeyByDefault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest('order-77'));

        self::assertSame('order-77', $fake->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testCatalogueModeSendsThePriceIdAndReadsTheAmountBackOffTheSession(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());
        $adapter = StripeSubscription::forCatalogPrice('sk_test_key', $fake, 'price_1Pro');

        $session = $adapter->createCheckout(new CheckoutRequest(
            reference: 'order-2',
            amount: new Money(0, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
        ));

        $body = $fake->decodedLastRequestForm();
        self::assertSame('price_1Pro', $body['line_items'][0]['price']);
        self::assertSame('1', $body['line_items'][0]['quantity']);
        self::assertArrayNotHasKey('price_data', $body['line_items'][0]);
        self::assertSame(2900, $session->amount->minorUnits);
    }

    public function testCatalogueModeThrowsWhenTheSessionCarriesNoAmount(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            'status' => 'open',
            'payment_status' => 'unpaid',
        ]));
        $adapter = StripeSubscription::forCatalogPrice('sk_test_key', $fake, 'price_1Pro');

        $this->expectException(CheckoutException::class);

        $adapter->createCheckout(new CheckoutRequest(
            reference: 'order-2',
            amount: new Money(0, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
        ));
    }

    public function testATrialInDaysIsSentAsTrialPeriodDays(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        $this->adapter($fake, new BillingCycle(BillingInterval::Day, 14))->createCheckout($this->checkoutRequest());

        self::assertSame('14', $fake->decodedLastRequestForm()['subscription_data']['trial_period_days']);
    }

    public function testAOneDayTrialIsRefusedAtConstruction(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/at least 2 days/');

        $this->adapter(null, new BillingCycle(BillingInterval::Day, 1));
    }

    public function testATrialInWeeksIsConvertedToDays(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        $this->adapter($fake, new BillingCycle(BillingInterval::Week, 2))->createCheckout($this->checkoutRequest());

        self::assertSame('14', $fake->decodedLastRequestForm()['subscription_data']['trial_period_days']);
    }

    /**
     * @return array<string, array{BillingInterval}>
     */
    public static function calendarIntervals(): array
    {
        return ['month' => [BillingInterval::Month], 'year' => [BillingInterval::Year]];
    }

    #[DataProvider('calendarIntervals')]
    public function testATrialInMonthsIsRefusedAtConstruction(BillingInterval $interval): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/trial_period_days/');

        $this->adapter(null, new BillingCycle($interval, 1));
    }

    public function testCatalogueModeAcceptsATrial(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());
        $adapter = StripeSubscription::forCatalogPrice(
            'sk_test_key',
            $fake,
            'price_1Pro',
            new BillingCycle(BillingInterval::Day, 7)
        );

        $adapter->createCheckout($this->checkoutRequest());

        self::assertSame('7', $fake->decodedLastRequestForm()['subscription_data']['trial_period_days']);
    }

    public function testConstructionRefusesNeitherOrBothPlanSources(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        try {
            new StripeSubscription('sk_test_key', $fake, null);
            self::fail('Neither a cycle nor a catalogue price should be accepted.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('forCatalogPrice()', $e->getMessage());
        }

        try {
            new StripeSubscription(
                'sk_test_key',
                $fake,
                new BillingCycle(BillingInterval::Month),
                catalogPriceId: 'price_1Pro'
            );
            self::fail('Both a cycle and a catalogue price should be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('Pass one', $e->getMessage());
        }
    }

    public function testConstructionRefusesACatalogueIdThatIsNotAPrice(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        try {
            StripeSubscription::forCatalogPrice('sk_test_key', $fake, 'prod_x');
            self::fail('A product id should be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('product id', $e->getMessage());
            self::assertStringContainsString('price_', $e->getMessage());
        }

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/price_/');

        StripeSubscription::forCatalogPrice('sk_test_key', $fake, 'pri_wrong');
    }

    // ---------------------------------------------------------------------------------
    // retrieveStatus
    // ---------------------------------------------------------------------------------

    public function testRetrieveStatusOfACompletedSessionIsSuccessAndCarriesTheSubscription(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::completedSession()));
        $adapter = $this->adapter($fake);

        $snapshot = $adapter->retrieveStatus('cs_test_123');

        self::assertSame(TransactionStatus::Success, $snapshot->status);
        self::assertSame('sub_test_1', $adapter->subscriptionReferenceOf($snapshot));
        self::assertSame('pi_test_9', $snapshot->paymentReference);
        self::assertSame('GET', $fake->lastRequest()->getMethod());
        self::assertStringContainsString('expand%5B0%5D=invoice.payments', (string) $fake->lastRequest()->getUri());
    }

    public function testRetrieveStatusOfATrialSessionPaidAtZeroIsSuccess(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::completedSession([
            'payment_status' => 'paid',
            'amount_total' => 0,
            'invoice' => ['id' => 'in_trial', 'object' => 'invoice', 'status' => 'paid', 'payments' => ['data' => []]],
        ])));

        $snapshot = $this->adapter($fake)->retrieveStatus('cs_test_123');

        self::assertSame(TransactionStatus::Success, $snapshot->status);
        self::assertSame(0, $snapshot->amount->minorUnits);
        self::assertNull($snapshot->paymentReference);

        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::completedSession([
            'payment_status' => 'no_payment_required',
        ])));

        self::assertSame(
            TransactionStatus::Success,
            $this->adapter($fake)->retrieveStatus('cs_test_123')->status
        );
    }

    public function testRetrieveStatusOfAnInvoiceExpandsPaymentsAndReadsThePaymentIntent(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::invoice()));

        $snapshot = $this->adapter($fake)->retrieveStatus('in_test_1');

        self::assertStringContainsString('/invoices/in_test_1', (string) $fake->lastRequest()->getUri());
        self::assertStringContainsString('expand%5B0%5D=payments', (string) $fake->lastRequest()->getUri());
        self::assertSame('pi_test_9', $snapshot->paymentReference);
        self::assertSame('in_test_1', $snapshot->gatewayReference);
        self::assertSame(2900, $snapshot->amount->minorUnits);
        self::assertSame(TransactionStatus::Success, $snapshot->status);
    }

    /**
     * ReleaseNotes_1.11.0.md §2.8.
     *
     * @return array<string, array{string, TransactionStatus}>
     */
    public static function invoiceStates(): array
    {
        return [
            'paid' => ['paid', TransactionStatus::Success],
            'open' => ['open', TransactionStatus::Pending],
            'draft' => ['draft', TransactionStatus::Pending],
            'uncollectible' => ['uncollectible', TransactionStatus::Failed],
            'void' => ['void', TransactionStatus::Cancelled],
            'unrecognised' => ['something_new', TransactionStatus::Pending],
        ];
    }

    #[DataProvider('invoiceStates')]
    public function testInvoiceStatesMapOntoTransactionStatus(string $state, TransactionStatus $expected): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::invoice(['status' => $state])));

        self::assertSame($expected, $this->adapter($fake)->retrieveStatus('in_test_1')->status);
    }

    /**
     * 1.4.0 §2.6, asked of Stripe: dunning is not the end, and Failed is terminal.
     */
    public function testAnOpenInvoiceAfterAFailedAttemptIsPendingNotFailed(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::invoice(['status' => 'open', 'attempt_count' => 2])
        ));

        $snapshot = $this->adapter($fake)->retrieveStatus('in_test_1');

        self::assertSame(TransactionStatus::Pending, $snapshot->status);
        self::assertNull($snapshot->failureReason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPaymentReferences(): array
    {
        return ['subscription' => ['sub_x'], 'payment intent' => ['pi_x']];
    }

    #[DataProvider('nonPaymentReferences')]
    public function testRetrieveStatusRefusesAReferenceThatIsNeitherASessionNorAnInvoice(string $reference): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([]));

        try {
            $this->adapter($fake)->retrieveStatus($reference);
            self::fail('The reference should have been refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString($reference, $e->getMessage());
            self::assertSame(0, $fake->requestCount(), 'A refused reference sends nothing.');
        }
    }

    // ---------------------------------------------------------------------------------
    // parseCallback
    // ---------------------------------------------------------------------------------

    public function testParseCallbackAcceptsInvoicePaidForASubscription(): void
    {
        $payload = self::invoiceEvent('evt_1', 'invoice.paid');

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame('in_test_1', $event->gatewayReference);
        self::assertSame(TransactionStatus::Success, $event->status);
        self::assertSame('invoice.paid', $event->eventType);
        self::assertNull($event->failureReason);
    }

    public function testParseCallbackMapsInvoicePaymentFailedToPending(): void
    {
        $payload = self::invoiceEvent('evt_2', 'invoice.payment_failed', ['status' => 'open', 'attempt_count' => 1]);

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame(TransactionStatus::Pending, $event->status);
        self::assertNull($event->failureReason);
    }

    public function testParseCallbackReadsAnUncollectibleInvoiceAsFailedWithTheEventTypeAsReason(): void
    {
        $payload = self::invoiceEvent('evt_3', 'invoice.marked_uncollectible', ['status' => 'uncollectible']);

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame(TransactionStatus::Failed, $event->status);
        self::assertSame('invoice.marked_uncollectible', $event->failureReason);
    }

    public function testParseCallbackReadsThePaymentIntentFromTheLegacyInvoiceFieldWhenPresent(): void
    {
        $payload = self::invoiceEvent('evt_4', 'invoice.paid', ['payment_intent' => 'pi_legacy']);

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame('pi_legacy', $event->paymentReference);
    }

    public function testParseCallbackLeavesTheSessionPaymentReferenceNull(): void
    {
        $payload = self::sessionEvent('evt_5', 'checkout.session.completed');

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame('cs_test_123', $event->gatewayReference);
        self::assertSame(TransactionStatus::Success, $event->status);
        self::assertNull($event->paymentReference);
    }

    public function testParseCallbackMapsSessionAsyncFailureAndExpiry(): void
    {
        $failed = self::sessionEvent('evt_6', 'checkout.session.async_payment_failed', [
            'payment_status' => 'unpaid',
            'last_payment_error' => ['message' => 'The bank declined the debit.'],
        ]);
        $event = $this->adapter()->parseCallback($failed, self::signedHeaders($failed));
        self::assertSame(TransactionStatus::Failed, $event->status);
        self::assertSame('The bank declined the debit.', $event->failureReason);

        $succeeded = self::sessionEvent('evt_7', 'checkout.session.async_payment_succeeded', ['payment_status' => 'unpaid']);
        self::assertSame(
            TransactionStatus::Success,
            $this->adapter()->parseCallback($succeeded, self::signedHeaders($succeeded))->status
        );

        $expired = self::sessionEvent('evt_8', 'checkout.session.expired', ['status' => 'expired', 'payment_status' => 'unpaid']);
        self::assertSame(
            TransactionStatus::Cancelled,
            $this->adapter()->parseCallback($expired, self::signedHeaders($expired))->status
        );
    }

    /**
     * Review Focus 1: the live endive payload carried only `parent`, the acacia payload both.
     */
    public function testSubscriptionReferenceOfReadsTheBasilInvoiceParent(): void
    {
        $payload = self::invoiceEvent('evt_9', 'invoice.paid');
        $adapter = $this->adapter();

        $event = $adapter->parseCallback($payload, self::signedHeaders($payload));

        self::assertArrayNotHasKey('subscription', $event->raw['data']['object']);
        self::assertSame('sub_test_1', $adapter->subscriptionReferenceOf($event));
    }

    public function testSubscriptionReferenceOfReadsTheLegacyTopLevelInvoiceSubscription(): void
    {
        $payload = self::invoiceEvent('evt_10', 'invoice.paid', ['subscription' => 'sub_legacy', 'parent' => null]);
        $adapter = $this->adapter();

        $event = $adapter->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame('sub_legacy', $adapter->subscriptionReferenceOf($event));
    }

    public function testSubscriptionReferenceOfReadsAnExpandedSubscriptionObject(): void
    {
        $payload = self::invoiceEvent('evt_11', 'invoice.paid', [
            'parent' => ['subscription_details' => ['subscription' => ['id' => 'sub_expanded', 'object' => 'subscription']]],
        ]);
        $adapter = $this->adapter();

        self::assertSame(
            'sub_expanded',
            $adapter->subscriptionReferenceOf($adapter->parseCallback($payload, self::signedHeaders($payload)))
        );
    }

    public function testSubscriptionReferenceOfIsNullForAnOpenSession(): void
    {
        $payload = self::sessionEvent('evt_12', 'checkout.session.expired', ['subscription' => null, 'status' => 'expired']);
        $adapter = $this->adapter();

        self::assertNull($adapter->subscriptionReferenceOf($adapter->parseCallback($payload, self::signedHeaders($payload))));
    }

    /**
     * Review Focus 2.
     */
    public function testParseCallbackRefusesAOneOffInvoiceWithNoSubscription(): void
    {
        $payload = self::invoiceEvent('evt_13', 'invoice.paid', ['parent' => null]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/belongs to no subscription/');

        $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function nonTransactionEvents(): array
    {
        return [
            'subscription' => ['customer.subscription.updated', 'subscription', 'sub_test_1'],
            'product' => ['product.created', 'product', 'prod_V9bcHuoKi1ahsr'],
            'charge' => ['charge.succeeded', 'charge', 'ch_3U9IP5AIdoJYd'],
        ];
    }

    #[DataProvider('nonTransactionEvents')]
    public function testParseCallbackRefusesNonTransactionObjects(string $type, string $objectType, string $objectId): void
    {
        $payload = json_encode([
            'id' => 'evt_unrelated',
            'type' => $type,
            'data' => ['object' => ['id' => $objectId, 'object' => $objectType]],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
            self::fail('A non-transaction object should be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('"' . $objectType . '" object', $e->getMessage());

            if ($objectType === 'subscription') {
                self::assertStringContainsString('parseSubscriptionCallback()', $e->getMessage());
            } else {
                self::assertStringNotContainsString('parseSubscriptionCallback()', $e->getMessage());
            }
        }
    }

    public function testParseCallbackRefusesAnEventWhoseObjectHasNoType(): void
    {
        $payload = json_encode([
            'id' => 'evt_untyped',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_123']],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/"unknown" object/');

        $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
    }

    // -- Tier 1: signature verification --------------------------------------------------

    public function testParseCallbackRejectsATamperedPayload(): void
    {
        $payload = self::invoiceEvent('evt_1', 'invoice.paid');
        $headers = self::signedHeaders($payload);

        // The signature is genuine — for a different body. This is the attack the scheme exists to stop.
        $tampered = str_replace('2900', '1', $payload);
        self::assertNotSame($payload, $tampered);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseCallback($tampered, $headers);
    }

    public function testParseCallbackRejectsASignatureFromTheWrongSecret(): void
    {
        $payload = self::invoiceEvent('evt_1', 'invoice.paid');
        $headers = self::signedHeaders($payload, secret: 'whsec_someone_elses_secret');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseCallback($payload, $headers);
    }

    public function testParseCallbackRejectsATimestampOutsideTheReplayTolerance(): void
    {
        $payload = self::invoiceEvent('evt_1', 'invoice.paid');
        $headers = self::signedHeaders($payload, timestamp: time() - 3600);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/replay/');

        $this->adapter()->parseCallback($payload, $headers);
    }

    public function testParseCallbackRejectsAMissingSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/no Stripe-Signature header/');

        $this->adapter()->parseCallback(self::invoiceEvent('evt_1', 'invoice.paid'), []);
    }

    public function testParseCallbackRejectsAMalformedSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/malformed/');

        $this->adapter()->parseCallback(
            self::invoiceEvent('evt_1', 'invoice.paid'),
            ['Stripe-Signature' => 'v1=deadbeef']
        );
    }

    public function testParseCallbackRefusesToRunAtAllWithoutASigningSecret(): void
    {
        $payload = self::invoiceEvent('evt_1', 'invoice.paid');
        $adapter = new StripeSubscription(
            'sk_test_key',
            new FakeHttpClient(static fn (): Response => self::openSessionResponse()),
            new BillingCycle(BillingInterval::Month)
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/without a webhook signing secret/');

        $adapter->parseCallback($payload, self::signedHeaders($payload));
    }

    public function testParseCallbackMatchesTheSignatureHeaderCaseInsensitively(): void
    {
        $payload = self::invoiceEvent('evt_1', 'invoice.paid');
        $headers = ['stripe-signature' => self::signedHeaders($payload)['Stripe-Signature']];

        self::assertSame('evt_1', $this->adapter()->parseCallback($payload, $headers)->eventId);
    }

    public function testParseCallbackAcceptsAnyOneOfSeveralSignaturesDuringSecretRotation(): void
    {
        $payload = self::invoiceEvent('evt_1', 'invoice.paid');
        $timestamp = time();
        $valid = HMAC::sign($timestamp . '.' . $payload, self::WEBHOOK_SECRET);

        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, str_repeat('0', 64), $valid);

        self::assertSame('evt_1', $this->adapter()->parseCallback($payload, ['Stripe-Signature' => $header])->eventId);
    }

    public function testParseCallbackVerifiesTheSignatureBeforeTheObjectGuard(): void
    {
        $payload = json_encode([
            'id' => 'evt_unrelated',
            'type' => 'product.created',
            'data' => ['object' => ['id' => 'prod_1', 'object' => 'product']],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseCallback($payload, self::signedHeaders($payload, secret: 'whsec_someone_elses_secret'));
    }

    // ---------------------------------------------------------------------------------
    // refund
    // ---------------------------------------------------------------------------------

    public function testRefundPostsAgainstAPaymentIntentDirectly(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::refundResponse());

        $result = $this->adapter($fake)->refund(new RefundRequest(reference: 'pi_test_456'));

        self::assertSame(1, $fake->requestCount(), 'A payment intent reference needs no resolving call.');
        self::assertSame('re_test_1', $result->gatewayRefundId);
        self::assertSame('pi_test_456', $result->reference);
        self::assertSame('https://api.stripe.com/v1/refunds', (string) $fake->lastRequest()->getUri());
        self::assertSame('pi_test_456', $fake->decodedLastRequestForm()['payment_intent']);
    }

    public function testRefundResolvesARenewalInvoiceToItsPaymentIntent(): void
    {
        $fake = new FakeHttpClient(static fn (RequestInterface $request): Response => str_contains((string) $request->getUri(), '/invoices/')
            ? self::jsonResponse(self::invoice())
            : self::refundResponse());

        $result = $this->adapter($fake)->refund(new RefundRequest(reference: 'in_test_1', amount: new Money(1000, 'USD')));

        self::assertSame(2, $fake->requestCount());
        self::assertSame('pi_test_9', $fake->decodedLastRequestForm()['payment_intent']);
        self::assertSame('1000', $fake->decodedLastRequestForm()['amount']);
        self::assertSame('pi_test_9', $result->reference);
    }

    public function testRefundResolvesASubscriptionSessionThroughItsInvoice(): void
    {
        $fake = new FakeHttpClient(static fn (RequestInterface $request): Response => str_contains((string) $request->getUri(), '/checkout/sessions/')
            ? self::jsonResponse(self::completedSession())
            : self::refundResponse());

        $result = $this->adapter($fake)->refund(new RefundRequest(reference: 'cs_test_123'));

        self::assertSame(2, $fake->requestCount());
        self::assertStringContainsString('expand%5B0%5D=invoice.payments', (string) $fake->requests()[0]->getUri());
        self::assertSame('pi_test_9', $fake->decodedLastRequestForm()['payment_intent']);
        self::assertSame('pi_test_9', $result->reference);
    }

    /**
     * Review Focus 5. Task 0's real shape: a trial session is `paid` at zero, and its invoice
     * lists no payments.
     */
    public function testRefundExplainsItselfForATrialSessionThatChargedNothing(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::completedSession([
            'payment_status' => 'paid',
            'amount_total' => 0,
            'invoice' => ['id' => 'in_trial', 'object' => 'invoice', 'status' => 'paid', 'payments' => ['data' => []]],
        ])));

        try {
            $this->adapter($fake)->refund(new RefundRequest(reference: 'cs_test_123'));
            self::fail('A refund against a trial session should be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('cs_test_123', $e->getMessage());
            self::assertStringContainsString('nothing was charged', $e->getMessage());
        }

        self::assertSame(1, $fake->requestCount(), 'Nothing is posted to /refunds.');
    }

    public function testRefundExplainsItselfForAnInvoiceThatIsNotPaid(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::invoice(['status' => 'open'])));

        try {
            $this->adapter($fake)->refund(new RefundRequest(reference: 'in_test_1'));
            self::fail('A refund against an unpaid invoice should be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('in_test_1', $e->getMessage());
            self::assertStringContainsString('is not paid', $e->getMessage());
        }

        self::assertSame(1, $fake->requestCount(), 'Nothing is posted to /refunds.');
    }

    public function testRefundRefusesAReferenceWithAnUnknownPrefixBeforeAnyRequest(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::refundResponse());

        try {
            $this->adapter($fake)->refund(new RefundRequest(reference: 'sub_x'));
            self::fail('The reference should have been refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('sub_x', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount());
    }

    public function testRefundSendsTheIdempotencyKey(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::refundResponse());

        $this->adapter($fake)->refund(new RefundRequest(reference: 'pi_test_456', idempotencyKey: 'refund-key-1'));

        self::assertSame('refund-key-1', $fake->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testRefundKeepsAFreeTextReasonAsMetadataRatherThanFailing(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::refundResponse());

        $this->adapter($fake)->refund(new RefundRequest(reference: 'pi_test_456', reason: 'Customer changed their mind'));

        $body = $fake->decodedLastRequestForm();
        self::assertArrayNotHasKey('reason', $body, 'Stripe rejects a reason outside its own enum.');
        self::assertSame('Customer changed their mind', $body['metadata']['reason']);
    }

    // ---------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------

    private function adapter(?FakeHttpClient $fake = null, ?BillingCycle $trial = null): StripeSubscription
    {
        return new StripeSubscription(
            'sk_test_key',
            $fake ?? new FakeHttpClient(static fn (): Response => self::openSessionResponse()),
            new BillingCycle(BillingInterval::Month),
            $trial,
            self::WEBHOOK_SECRET
        );
    }

    private function checkoutRequest(string $reference = 'ORDER-1001'): CheckoutRequest
    {
        return new CheckoutRequest(
            reference: $reference,
            amount: new Money(2900, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private static function openSessionResponse(): Response
    {
        return self::jsonResponse([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => 2900,
            'currency' => 'usd',
        ]);
    }

    private static function refundResponse(): Response
    {
        return self::jsonResponse(['id' => 're_test_1', 'amount' => 2900, 'currency' => 'usd', 'status' => 'succeeded']);
    }

    /**
     * A completed subscription-mode session with its first invoice expanded.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function completedSession(array $overrides = []): array
    {
        return array_merge([
            'id' => 'cs_test_123',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 2900,
            'currency' => 'usd',
            'subscription' => 'sub_test_1',
            'invoice' => self::invoice(),
        ], $overrides);
    }

    /**
     * A paid renewal invoice in the endive shape: the subscription only under `parent`, and
     * the PaymentIntent only under the expanded `payments`.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function invoice(array $overrides = []): array
    {
        return array_merge([
            'id' => 'in_test_1',
            'object' => 'invoice',
            'status' => 'paid',
            'total' => 2900,
            'currency' => 'usd',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_test_1']],
            'payments' => ['data' => [[
                'object' => 'invoice_payment',
                'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_test_9'],
            ]]],
        ], $overrides);
    }

    /**
     * A webhook delivers an invoice without its payments expanded.
     *
     * @param array<string, mixed> $overrides
     */
    private static function invoiceEvent(string $eventId, string $type, array $overrides = []): string
    {
        $invoice = array_merge(self::invoice(), $overrides);
        unset($invoice['payments']);

        return json_encode(
            ['id' => $eventId, 'type' => $type, 'data' => ['object' => $invoice]],
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function sessionEvent(string $eventId, string $type, array $overrides = []): string
    {
        $session = array_merge(self::completedSession(), ['invoice' => 'in_test_1'], $overrides);

        return json_encode(
            ['id' => $eventId, 'type' => $type, 'data' => ['object' => $session]],
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return array<string, string>
     */
    private static function signedHeaders(string $payload, ?int $timestamp = null, string $secret = self::WEBHOOK_SECRET): array
    {
        $timestamp ??= time();

        return [
            'Stripe-Signature' => sprintf('t=%d,v1=%s', $timestamp, HMAC::sign($timestamp . '.' . $payload, $secret)),
        ];
    }
}
