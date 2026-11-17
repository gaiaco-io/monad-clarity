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
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Services\Checkout\ResumeBilling;
use Monad\Clarity\Services\Checkout\SubscriptionEffectiveFrom;
use Monad\Clarity\Services\Checkout\SubscriptionItem;
use Monad\Clarity\Services\Checkout\SubscriptionStatus;
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

    /**
     * Signed does not mean well-formed. Mediator::register() turns every warning into an
     * ErrorException, and an array hitting a string cast is a warning, so each of these must
     * be refused as a CheckoutException instead of leaking one.
     *
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function malformedEvents(): array
    {
        $arr = ['not' => 'a string'];

        return [
            'array event id' => [static fn (array $e): array => ['id' => $arr] + $e],
            'array event type' => [static fn (array $e): array => ['type' => $arr] + $e],
            'array object type' => [static function (array $e) use ($arr): array {
                $e['data']['object']['object'] = $arr;

                return $e;
            }],
            'array object id' => [static function (array $e) use ($arr): array {
                $e['data']['object']['id'] = $arr;

                return $e;
            }],
            'array invoice status' => [static function (array $e) use ($arr): array {
                $e['data']['object']['status'] = $arr;

                return $e;
            }],
            'array subscription id' => [static function (array $e) use ($arr): array {
                $e['data']['object']['parent']['subscription_details']['subscription'] = ['id' => $arr];

                return $e;
            }],
            'array payment intent id' => [static function (array $e) use ($arr): array {
                $e['data']['object']['payment_intent'] = ['id' => $arr];

                return $e;
            }],
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $malform
     */
    #[DataProvider('malformedEvents')]
    public function testParseCallbackRefusesASignedButMalformedInvoicePayloadAsACheckoutException(callable $malform): void
    {
        $event = json_decode(self::invoiceEvent('evt_bad', 'invoice.paid'), true, flags: JSON_THROW_ON_ERROR);
        $payload = json_encode($malform($event), JSON_THROW_ON_ERROR);

        $this->assertWarningsBecomeExceptions(function () use ($payload): void {
            try {
                $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
                // Reaching here is acceptable: the field was harmlessly ignored. Leaking is not.
                self::addToAssertionCount(1);
            } catch (CheckoutException) {
                self::addToAssertionCount(1);
            }
        });
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function malformedSessionFields(): array
    {
        return [
            'array payment_status' => ['payment_status', ['x' => 1]],
            'array last_payment_error message' => ['last_payment_error', ['message' => ['x' => 1]]],
        ];
    }

    #[DataProvider('malformedSessionFields')]
    public function testParseCallbackRefusesASignedButMalformedSessionPayloadAsACheckoutException(string $field, mixed $value): void
    {
        $payload = self::sessionEvent('evt_bad', 'checkout.session.async_payment_failed', [$field => $value]);

        $this->assertWarningsBecomeExceptions(function () use ($payload): void {
            try {
                $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
                self::addToAssertionCount(1);
            } catch (CheckoutException) {
                self::addToAssertionCount(1);
            }
        });
    }

    public function testAnArrayValuedInvoiceStatusIsRefusedOrReadAsPendingNeverLeaked(): void
    {
        $payload = self::invoiceEvent('evt_bad', 'invoice.paid', ['status' => ['x' => 1]]);

        $this->assertWarningsBecomeExceptions(function () use ($payload): void {
            $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
            self::assertSame(TransactionStatus::Pending, $event->status);
        });
    }

    /**
     * Installs the framework's own behaviour (Mediator::register) for the duration of $body.
     */
    private function assertWarningsBecomeExceptions(callable $body): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $body();
        } finally {
            restore_error_handler();
        }
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
    // retrieveSubscription / parseSubscriptionCallback
    // ---------------------------------------------------------------------------------

    public function testRetrieveSubscriptionNormalisesAnActiveSubscription(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription()));

        $snapshot = $this->adapter($fake)->retrieveSubscription('sub_test_1');

        $request = $fake->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/subscriptions/sub_test_1', (string) $request->getUri());
        self::assertSame('2026-09-30.endive', $request->getHeaderLine('Stripe-Version'));

        self::assertSame('stripe_subscription', $snapshot->gateway);
        self::assertSame('sub_test_1', $snapshot->gatewayReference);
        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
        self::assertSame('order-1', $snapshot->reference);
        self::assertSame('cus_test_1', $snapshot->customerReference);
        self::assertNull($snapshot->transactionReference);
        self::assertNull($snapshot->scheduledChange);
        self::assertSame(2900, $snapshot->recurringAmount?->minorUnits);
        self::assertSame('USD', $snapshot->recurringAmount->currency);
        self::assertTrue($snapshot->billingCycle?->equals(new BillingCycle(BillingInterval::Month, 1)));
        self::assertSame(1700000000, $snapshot->currentPeriodStartsAt?->getTimestamp());
        self::assertSame(1702592000, $snapshot->currentPeriodEndsAt?->getTimestamp());
        self::assertEquals($snapshot->currentPeriodEndsAt, $snapshot->nextBilledAt);
        self::assertSame('sub_test_1', $snapshot->raw['id']);
    }

    /**
     * @return array<string, array{string, SubscriptionStatus}>
     */
    public static function subscriptionStatuses(): array
    {
        return [
            'active' => ['active', SubscriptionStatus::Active],
            'trialing' => ['trialing', SubscriptionStatus::Trialing],
            'past_due' => ['past_due', SubscriptionStatus::PastDue],
            'paused' => ['paused', SubscriptionStatus::Paused],
            'canceled' => ['canceled', SubscriptionStatus::Cancelled],
            'incomplete' => ['incomplete', SubscriptionStatus::PastDue],
            'unpaid' => ['unpaid', SubscriptionStatus::PastDue],
            'incomplete_expired' => ['incomplete_expired', SubscriptionStatus::Cancelled],
        ];
    }

    #[DataProvider('subscriptionStatuses')]
    public function testSubscriptionStatusesMap(string $stripeStatus, SubscriptionStatus $expected): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription(['status' => $stripeStatus])));

        self::assertSame($expected, $this->adapter($fake)->retrieveSubscription('sub_test_1')->status);
    }

    public function testAnUnrecognisedStatusIsRefusedByName(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription(['status' => 'hibernating'])));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/hibernating/');

        $this->adapter($fake)->retrieveSubscription('sub_test_1');
    }

    public function testCancelAtPeriodEndIsAScheduledCancellationOnAnActiveSubscription(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::subscription(['cancel_at_period_end' => true, 'cancel_at' => 1702592000])
        ));

        $snapshot = $this->adapter($fake)->retrieveSubscription('sub_test_1');

        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
        self::assertTrue($snapshot->isCancelling());
        self::assertSame(1702592000, $snapshot->accessEndsAt()?->getTimestamp());
        self::assertNull($snapshot->nextBilledAt);
    }

    public function testCancelAtPeriodEndWithoutACancelAtFallsBackToThePeriodEnd(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::subscription(['cancel_at_period_end' => true])
        ));

        $snapshot = $this->adapter($fake)->retrieveSubscription('sub_test_1');

        self::assertTrue($snapshot->isCancelling());
        self::assertSame(1702592000, $snapshot->accessEndsAt()?->getTimestamp());
    }

    public function testACancelledSubscriptionSchedulesNothing(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::subscription(['status' => 'canceled', 'cancel_at_period_end' => true, 'cancel_at' => 1702592000])
        ));

        $snapshot = $this->adapter($fake)->retrieveSubscription('sub_test_1');

        self::assertNull($snapshot->scheduledChange);
        self::assertNull($snapshot->nextBilledAt);
    }

    public function testAScheduledCancellationWithNoDateAnywhereIsAGatewayError(): void
    {
        $subscription = self::subscription(['cancel_at_period_end' => true]);
        unset($subscription['items']['data'][0]['current_period_end'], $subscription['items']['data'][1]['current_period_end']);
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/sub_test_1/');

        $this->adapter($fake)->retrieveSubscription('sub_test_1');
    }

    public function testPeriodsAreReadFromItemsOnBasilAndLaterPayloads(): void
    {
        $subscription = self::subscription();
        $subscription['current_period_start'] = null;
        $subscription['current_period_end'] = null;
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        $snapshot = $this->adapter($fake)->retrieveSubscription('sub_test_1');

        self::assertSame(1700000000, $snapshot->currentPeriodStartsAt?->getTimestamp());
        self::assertSame(1702592000, $snapshot->currentPeriodEndsAt?->getTimestamp());
    }

    public function testPeriodsAreReadFromTheSubscriptionOnLegacyPayloads(): void
    {
        $subscription = self::subscription();
        foreach (array_keys($subscription['items']['data']) as $i) {
            unset($subscription['items']['data'][$i]['current_period_start'], $subscription['items']['data'][$i]['current_period_end']);
        }
        $subscription['current_period_start'] = 1710000000;
        $subscription['current_period_end'] = 1712592000;
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        $snapshot = $this->adapter($fake)->retrieveSubscription('sub_test_1');

        self::assertSame(1710000000, $snapshot->currentPeriodStartsAt?->getTimestamp());
        self::assertSame(1712592000, $snapshot->currentPeriodEndsAt?->getTimestamp());
    }

    public function testATieredPriceHasNoFixedRecurringAmountRatherThanAFailure(): void
    {
        $subscription = self::subscription();
        $subscription['items']['data'][1]['price']['unit_amount'] = null;
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        $snapshot = $this->adapter($fake)->retrieveSubscription('sub_test_1');

        self::assertNull($snapshot->recurringAmount);
        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
    }

    public function testAnUnknownRecurringIntervalReadsAsNoBillingCycle(): void
    {
        $subscription = self::subscription();
        $subscription['items']['data'][0]['price']['recurring'] = ['interval' => 'fortnight', 'interval_count' => 1];
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        self::assertNull($this->adapter($fake)->retrieveSubscription('sub_test_1')->billingCycle);
    }

    public function testAnIntervalCountOfThreeMonthsIsReadAsAQuarterlyCycle(): void
    {
        $subscription = self::subscription();
        $subscription['items']['data'][0]['price']['recurring'] = ['interval' => 'month', 'interval_count' => 3];
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        $cycle = $this->adapter($fake)->retrieveSubscription('sub_test_1')->billingCycle;

        self::assertTrue($cycle?->equals(new BillingCycle(BillingInterval::Month, 3)));
    }

    public function testAPayloadWithMixedItemCurrenciesIsAGatewayError(): void
    {
        $subscription = self::subscription();
        $subscription['items']['data'][1]['price']['currency'] = 'eur';
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        $this->expectException(CheckoutException::class);

        $this->adapter($fake)->retrieveSubscription('sub_test_1');
    }

    public function testAPayloadWithANegativeUnitAmountIsAGatewayError(): void
    {
        $subscription = self::subscription();
        $subscription['items']['data'][0]['price']['unit_amount'] = -100;
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        $this->expectException(CheckoutException::class);

        $this->adapter($fake)->retrieveSubscription('sub_test_1');
    }

    public function testAPayloadWithNoItemsIsAGatewayError(): void
    {
        $subscription = self::subscription();
        $subscription['items']['data'] = [];
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        $this->expectException(CheckoutException::class);

        $this->adapter($fake)->retrieveSubscription('sub_test_1');
    }

    public function testAPayloadWithANonIsoCurrencyIsAGatewayError(): void
    {
        $subscription = self::subscription();
        $subscription['items']['data'][0]['price']['currency'] = 'dollars';
        $subscription['items']['data'][1]['price']['currency'] = 'dollars';
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($subscription));

        $this->expectException(CheckoutException::class);

        $this->adapter($fake)->retrieveSubscription('sub_test_1');
    }

    public function testRetrieveSubscriptionRefusesAReferenceThatIsNotASubscription(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription()));

        try {
            $this->adapter($fake)->retrieveSubscription('cs_x');
            self::fail('A checkout session id must be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('cs_x', $e->getMessage());
            self::assertSame(0, $fake->requestCount());
        }
    }

    // ---------------------------------------------------------------------------------
    // cancel / removeScheduledChange
    // ---------------------------------------------------------------------------------

    public function testCancelForTheNextBillingPeriodSetsCancelAtPeriodEndAndStaysActive(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::subscription(['cancel_at_period_end' => true, 'cancel_at' => 1702592000])
        ));

        $snapshot = $this->adapter($fake)->cancel('sub_test_1', SubscriptionEffectiveFrom::NextBillingPeriod);

        $request = $fake->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/subscriptions/sub_test_1', (string) $request->getUri());
        self::assertSame(['cancel_at_period_end' => 'true'], $fake->decodedLastRequestForm());
        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
        self::assertTrue($snapshot->isCancelling());
        self::assertSame(1702592000, $snapshot->accessEndsAt()?->getTimestamp());
    }

    public function testCancelImmediatelyDeletesTheSubscriptionAndSendsNoProration(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::subscription(['status' => 'canceled'])
        ));

        $snapshot = $this->adapter($fake)->cancel('sub_test_1', SubscriptionEffectiveFrom::Immediately);

        $request = $fake->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/subscriptions/sub_test_1', (string) $request->getUri());
        self::assertStringNotContainsString('prorate', (string) $request->getUri());
        self::assertStringNotContainsString('invoice_now', (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame('', $request->getHeaderLine('Content-Type'));
        self::assertSame(SubscriptionStatus::Cancelled, $snapshot->status);
    }

    public function testRemoveScheduledChangeWithdrawsAPendingCancellation(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::subscription(['cancel_at_period_end' => false, 'cancel_at' => null])
        ));

        $snapshot = $this->adapter($fake)->removeScheduledChange('sub_test_1');

        $request = $fake->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/subscriptions/sub_test_1', (string) $request->getUri());
        self::assertSame(['cancel_at_period_end' => 'false'], $fake->decodedLastRequestForm());
        self::assertFalse($snapshot->isCancelling());
    }

    public function testCancelRefusesAReferenceThatIsNotASubscription(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription()));

        foreach ([
            fn () => $this->adapter($fake)->cancel('cs_x', SubscriptionEffectiveFrom::Immediately),
            fn () => $this->adapter($fake)->removeScheduledChange('cs_x'),
        ] as $call) {
            try {
                $call();
                self::fail('A checkout session id must be refused.');
            } catch (CheckoutException $e) {
                self::assertStringContainsString('cs_x', $e->getMessage());
            }
        }

        self::assertSame(0, $fake->requestCount());
    }

    // ---------------------------------------------------------------------------------
    // pause / resume
    // ---------------------------------------------------------------------------------

    public function testPauseImmediatelyPostsToThePauseEndpointAndReportsPaused(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::subscription(['status' => 'paused'])
        ));

        $snapshot = $this->adapter($fake)->pause('sub_test_1', SubscriptionEffectiveFrom::Immediately);

        $request = $fake->lastRequest();
        self::assertSame(1, $fake->requestCount());
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/subscriptions/sub_test_1/pause', (string) $request->getUri());
        self::assertSame(['type' => 'subscription'], $fake->decodedLastRequestForm());
        self::assertSame(SubscriptionStatus::Paused, $snapshot->status);
    }

    public function testPauseRefusesNextBillingPeriod(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription()));

        try {
            $this->adapter($fake)->pause('sub_test_1', SubscriptionEffectiveFrom::NextBillingPeriod);
            self::fail('A pause at the next billing period must be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('immediately', $e->getMessage());
            self::assertStringContainsString('subscription schedule', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount());
    }

    public function testPauseRefusesAResumeDate(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription()));

        try {
            $this->adapter($fake)->pause(
                'sub_test_1',
                SubscriptionEffectiveFrom::Immediately,
                new DateTimeImmutable('2027-01-01')
            );
            self::fail('A resume date must be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('resumeAt', $e->getMessage());
            self::assertStringContainsString('no resume date', $e->getMessage());
            self::assertStringContainsString('resume()', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount());
    }

    public function testPauseRefusesAnOnResumeChoice(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription()));

        try {
            $this->adapter($fake)->pause(
                'sub_test_1',
                SubscriptionEffectiveFrom::Immediately,
                onResume: ResumeBilling::ContinueExistingBillingPeriod
            );
            self::fail('An onResume choice must be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('onResume', $e->getMessage());
            self::assertStringContainsString('resume()', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount());
    }

    public function testPauseAndResumeRefuseAReferenceThatIsNotASubscription(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription()));

        foreach ([
            fn () => $this->adapter($fake)->pause('cs_x', SubscriptionEffectiveFrom::Immediately),
            fn () => $this->adapter($fake)->resume('cs_x', SubscriptionEffectiveFrom::Immediately),
        ] as $call) {
            try {
                $call();
                self::fail('A checkout session id must be refused.');
            } catch (CheckoutException $e) {
                self::assertStringContainsString('cs_x', $e->getMessage());
            }
        }

        self::assertSame(0, $fake->requestCount());
    }

    /**
     * @return array<string, array{ResumeBilling, string}>
     */
    public static function resumeBillingChoices(): array
    {
        return [
            'start a new period' => [ResumeBilling::StartNewBillingPeriod, 'now'],
            'continue the period' => [ResumeBilling::ContinueExistingBillingPeriod, 'unchanged'],
        ];
    }

    #[DataProvider('resumeBillingChoices')]
    public function testResumeMapsResumeBillingOntoTheBillingCycleAnchor(ResumeBilling $choice, string $anchor): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::subscription(['status' => 'active', 'latest_invoice' => self::invoice(['status' => 'paid'])])
        ));

        $this->adapter($fake)->resume('sub_test_1', SubscriptionEffectiveFrom::Immediately, $choice);

        $request = $fake->requests()[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/subscriptions/sub_test_1/resume', (string) $request->getUri());

        parse_str((string) $request->getBody(), $form);
        self::assertSame(['type' => $anchor], $form['billing_cycle_anchor']);
        self::assertSame(['latest_invoice'], $form['expand']);
        self::assertStringContainsString('expand%5B0%5D=latest_invoice', (string) $request->getBody());
    }

    public function testResumeRefusesNextBillingPeriod(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription()));

        try {
            $this->adapter($fake)->resume('sub_test_1', SubscriptionEffectiveFrom::NextBillingPeriod);
            self::fail('A resume at the next billing period must be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('immediately', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount());
    }

    public function testResumeStartingANewPeriodPaysTheOpenResumeInvoiceThenReReads(): void
    {
        $fake = new FakeHttpClient(static function (RequestInterface $request): Response {
            return match ($request->getUri()->getPath()) {
                '/v1/subscriptions/sub_test_1/resume' => self::jsonResponse(self::subscription([
                    'status' => 'paused',
                    'latest_invoice' => self::invoice(['id' => 'in_r', 'status' => 'open']),
                ])),
                '/v1/invoices/in_r/pay' => self::jsonResponse(self::invoice(['id' => 'in_r', 'status' => 'paid'])),
                default => self::jsonResponse(self::subscription(['status' => 'active'])),
            };
        });

        $snapshot = $this->adapter($fake)->resume('sub_test_1', SubscriptionEffectiveFrom::Immediately);

        self::assertSame(
            [
                'POST /v1/subscriptions/sub_test_1/resume',
                'POST /v1/invoices/in_r/pay',
                'GET /v1/subscriptions/sub_test_1',
            ],
            array_map(
                static fn (RequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri()->getPath(),
                $fake->requests()
            )
        );
        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
    }

    public function testResumeReadsTheInvoiceWhenStripeDidNotExpandIt(): void
    {
        $fake = new FakeHttpClient(static function (RequestInterface $request): Response {
            return match ($request->getUri()->getPath()) {
                '/v1/subscriptions/sub_test_1/resume' => self::jsonResponse(
                    self::subscription(['status' => 'paused', 'latest_invoice' => 'in_r'])
                ),
                '/v1/invoices/in_r' => self::jsonResponse(self::invoice(['id' => 'in_r', 'status' => 'open'])),
                '/v1/invoices/in_r/pay' => self::jsonResponse(self::invoice(['id' => 'in_r', 'status' => 'paid'])),
                default => self::jsonResponse(self::subscription(['status' => 'active'])),
            };
        });

        $snapshot = $this->adapter($fake)->resume('sub_test_1', SubscriptionEffectiveFrom::Immediately);

        self::assertSame(
            [
                'POST /v1/subscriptions/sub_test_1/resume',
                'GET /v1/invoices/in_r',
                'POST /v1/invoices/in_r/pay',
                'GET /v1/subscriptions/sub_test_1',
            ],
            array_map(
                static fn (RequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri()->getPath(),
                $fake->requests()
            )
        );
        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
    }

    public function testResumeThatRaisesNoOpenInvoiceDoesNotPay(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription([
            'status' => 'active',
            'latest_invoice' => self::invoice(['id' => 'in_old', 'status' => 'paid']),
        ])));

        $snapshot = $this->adapter($fake)->resume(
            'sub_test_1',
            SubscriptionEffectiveFrom::Immediately,
            ResumeBilling::ContinueExistingBillingPeriod
        );

        self::assertSame(1, $fake->requestCount());
        self::assertStringNotContainsString('/pay', (string) $fake->lastRequest()->getUri());
        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
    }

    public function testADeclinedResumePaymentSaysTheSubscriptionIsStillPaused(): void
    {
        $fake = new FakeHttpClient(static function (RequestInterface $request): Response {
            return $request->getUri()->getPath() === '/v1/invoices/in_r/pay'
                ? self::jsonResponse(['error' => [
                    'type' => 'card_error',
                    'code' => 'card_declined',
                    'message' => 'Your card was declined.',
                ]], 402)
                : self::jsonResponse(self::subscription([
                    'status' => 'paused',
                    'latest_invoice' => self::invoice(['id' => 'in_r', 'status' => 'open']),
                ]));
        });

        try {
            $this->adapter($fake)->resume('sub_test_1', SubscriptionEffectiveFrom::Immediately);
            self::fail('A declined payment must not read as a resumed subscription.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('still paused', $e->getMessage());
            self::assertStringContainsString('in_r', $e->getMessage());
            self::assertStringContainsString('retrieveSubscription()', $e->getMessage());
            self::assertStringNotContainsString('not charged', $e->getMessage());
            self::assertStringContainsString('sub_test_1', $e->getMessage());
            self::assertInstanceOf(CheckoutException::class, $e->getPrevious());
            self::assertStringContainsString('card_declined', $e->getPrevious()->getMessage());
        }

        self::assertSame(2, $fake->requestCount());
        self::assertSame('POST', $fake->lastRequest()->getMethod());
    }

    public function testPauseAndResumeSendTheAdaptersPinnedApiVersion(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            self::subscription(['status' => 'paused'])
        ));
        $adapter = $this->adapter($fake);

        $adapter->pause('sub_test_1', SubscriptionEffectiveFrom::Immediately);
        $adapter->resume('sub_test_1', SubscriptionEffectiveFrom::Immediately);

        self::assertSame(2, $fake->requestCount());

        foreach ($fake->requests() as $request) {
            self::assertSame('2026-09-30.endive', $request->getHeaderLine('Stripe-Version'));
        }
    }

    public function testParseSubscriptionCallbackReturnsTheEventWithAUtcOccurredAt(): void
    {
        $payload = self::subscriptionEvent('evt_s1', 'customer.subscription.updated', created: 1700000123);

        $event = $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));

        self::assertSame('stripe_subscription', $event->gateway);
        self::assertSame('evt_s1', $event->eventId);
        self::assertSame('customer.subscription.updated', $event->eventType);
        self::assertSame(1700000123, $event->occurredAt->getTimestamp());
        self::assertSame('UTC', $event->occurredAt->getTimezone()->getName());
        self::assertSame('sub_test_1', $event->subscription->gatewayReference);
        self::assertSame('order-1', $event->subscription->reference);
        self::assertSame('evt_s1', $event->raw['id']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function nonSubscriptionObjects(): array
    {
        return [
            'invoice' => ['invoice.paid', 'invoice', 'in_1'],
            'checkout session' => ['checkout.session.completed', 'checkout.session', 'cs_1'],
        ];
    }

    #[DataProvider('nonSubscriptionObjects')]
    public function testParseSubscriptionCallbackRefusesInvoiceAndSessionEvents(string $type, string $objectType, string $id): void
    {
        $payload = json_encode([
            'id' => 'evt_x',
            'type' => $type,
            'created' => 1700000000,
            'data' => ['object' => ['id' => $id, 'object' => $objectType]],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/parseCallback\(\)/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    public function testParseSubscriptionCallbackRefusesASubscriptionObjectUnderAnotherEventType(): void
    {
        $payload = self::subscriptionEvent('evt_s1', 'invoice.paid');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/invoice\.paid/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    public function testParseSubscriptionCallbackVerifiesTheSignatureBeforeTheEventGuard(): void
    {
        $payload = json_encode([
            'id' => 'evt_unrelated',
            'type' => 'product.created',
            'data' => ['object' => ['id' => 'prod_1', 'object' => 'product']],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload, secret: 'whsec_someone_elses_secret'));
    }

    public function testParseSubscriptionCallbackRefusesToRunWithoutASigningSecret(): void
    {
        $adapter = new StripeSubscription(
            'sk_test_key',
            new FakeHttpClient(static fn (): Response => self::openSessionResponse()),
            new BillingCycle(BillingInterval::Month)
        );
        $payload = self::subscriptionEvent('evt_s1', 'customer.subscription.created');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/signing secret/');

        $adapter->parseSubscriptionCallback($payload, self::signedHeaders($payload));
    }

    public function testParseSubscriptionCallbackRejectsATamperedPayload(): void
    {
        $payload = self::subscriptionEvent('evt_s1', 'customer.subscription.updated');
        $headers = self::signedHeaders($payload);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseSubscriptionCallback(str_replace('order-1', 'order-2', $payload), $headers);
    }

    public function testParseSubscriptionCallbackRejectsASignatureFromTheWrongSecret(): void
    {
        $payload = self::subscriptionEvent('evt_s1', 'customer.subscription.updated');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload, secret: 'whsec_someone_elses_secret'));
    }

    public function testParseSubscriptionCallbackRejectsATimestampOutsideTheReplayTolerance(): void
    {
        $payload = self::subscriptionEvent('evt_s1', 'customer.subscription.updated');
        $headers = self::signedHeaders($payload, timestamp: time() - 3600);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/replay/');

        $this->adapter()->parseSubscriptionCallback($payload, $headers);
    }

    public function testParseSubscriptionCallbackRejectsAMissingSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/no Stripe-Signature header/');

        $this->adapter()->parseSubscriptionCallback(self::subscriptionEvent('evt_s1', 'customer.subscription.updated'), []);
    }

    public function testParseSubscriptionCallbackRejectsAMalformedSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/malformed/');

        $this->adapter()->parseSubscriptionCallback(
            self::subscriptionEvent('evt_s1', 'customer.subscription.updated'),
            ['Stripe-Signature' => 'v1=deadbeef']
        );
    }

    public function testParseSubscriptionCallbackMatchesTheSignatureHeaderCaseInsensitively(): void
    {
        $payload = self::subscriptionEvent('evt_s1', 'customer.subscription.updated');
        $headers = ['stripe-signature' => self::signedHeaders($payload)['Stripe-Signature']];

        self::assertSame('evt_s1', $this->adapter()->parseSubscriptionCallback($payload, $headers)->eventId);
    }

    public function testParseSubscriptionCallbackAcceptsAnyOneOfSeveralSignaturesDuringSecretRotation(): void
    {
        $payload = self::subscriptionEvent('evt_s1', 'customer.subscription.updated');
        $timestamp = time();
        $valid = HMAC::sign($timestamp . '.' . $payload, self::WEBHOOK_SECRET);
        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, str_repeat('0', 64), $valid);

        self::assertSame(
            'evt_s1',
            $this->adapter()->parseSubscriptionCallback($payload, ['Stripe-Signature' => $header])->eventId
        );
    }

    /**
     * Signed does not mean well-formed. Each of these must come out as a CheckoutException — or,
     * where the field is optional and a malformed value reasonably reads as absent, as a
     * successful parse — and never as the ErrorException the framework's handler would raise.
     * The names say which: "is refused" or "reads as absent".
     *
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, bool}>
     */
    public static function malformedSubscriptionEvents(): array
    {
        $arr = ['not' => 'a string'];

        return [
            'array status is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['status'] = $arr;

                return $e;
            }, true],
            'array subscription id is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['id'] = $arr;

                return $e;
            }, true],
            'array event type is refused' => [static fn (array $e): array => ['type' => $arr] + $e, true],
            'array event id is refused' => [static fn (array $e): array => ['id' => $arr] + $e, true],
            'array object type is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['object'] = $arr;

                return $e;
            }, true],
            'array created is refused' => [static fn (array $e): array => ['created' => $arr] + $e, true],
            'string created is refused' => [static fn (array $e): array => ['created' => '1700000000'] + $e, true],
            'missing created is refused' => [static function (array $e): array {
                unset($e['created']);

                return $e;
            }, true],
            'array metadata reference reads as absent' => [static function (array $e) use ($arr): array {
                $e['data']['object']['metadata']['reference'] = $arr;

                return $e;
            }, false],
            'array unit_amount is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['items']['data'][0]['price']['unit_amount'] = $arr;

                return $e;
            }, true],
            'array quantity is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['items']['data'][0]['quantity'] = $arr;

                return $e;
            }, true],
            'array currency is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['items']['data'][0]['price']['currency'] = $arr;

                return $e;
            }, true],
            'array cancel_at is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['cancel_at'] = $arr;

                return $e;
            }, true],
            'array customer reads as absent' => [static function (array $e) use ($arr): array {
                $e['data']['object']['customer'] = ['id' => $arr];

                return $e;
            }, false],
            'array recurring interval reads as absent' => [static function (array $e) use ($arr): array {
                $e['data']['object']['items']['data'][0]['price']['recurring'] = ['interval' => $arr, 'interval_count' => $arr];

                return $e;
            }, false],
            'unit amount times quantity overflow is refused' => [static function (array $e): array {
                $e['data']['object']['items']['data'][0]['price']['unit_amount'] = PHP_INT_MAX;
                $e['data']['object']['items']['data'][0]['quantity'] = 2;

                return $e;
            }, true],
            'sum of items overflow is refused' => [static function (array $e): array {
                foreach ([0, 1] as $i) {
                    $e['data']['object']['items']['data'][$i]['price']['unit_amount'] = PHP_INT_MAX;
                    $e['data']['object']['items']['data'][$i]['quantity'] = 1;
                }

                return $e;
            }, true],
            'array items data is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['items']['data'] = $arr;

                return $e;
            }, true],
            'string object is refused' => [static function (array $e): array {
                $e['data']['object'] = 'sub_test_1';

                return $e;
            }, true],
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $malform
     */
    #[DataProvider('malformedSubscriptionEvents')]
    public function testParseSubscriptionCallbackRefusesASignedButMalformedPayloadAsACheckoutException(callable $malform, bool $refused): void
    {
        $event = json_decode(self::subscriptionEvent('evt_bad', 'customer.subscription.updated'), true, flags: JSON_THROW_ON_ERROR);
        $payload = json_encode($malform($event), JSON_THROW_ON_ERROR);

        $this->assertWarningsBecomeExceptions(function () use ($payload, $refused): void {
            if ($refused) {
                $this->expectException(CheckoutException::class);
            }

            $parsed = $this->adapter()->parseSubscriptionCallback($payload, self::signedHeaders($payload));

            if (!$refused) {
                self::assertSame('sub_test_1', $parsed->subscription->gatewayReference);
            }
        });
    }

    // ---------------------------------------------------------------------------------
    // changePlan
    // ---------------------------------------------------------------------------------

    public function testChangePlanReplacesTheWholeItemSet(): void
    {
        $fake = $this->changePlanFake();

        $this->adapter($fake)->changePlan(
            'sub_test_1',
            [SubscriptionItem::catalogPrice('price_pro', 3)],
            ProrationBillingMode::ProratedImmediately
        );

        self::assertSame(['GET /v1/subscriptions/sub_test_1', 'POST /v1/subscriptions/sub_test_1'], self::requestLines($fake));

        $form = self::formOf($fake->requests()[1]);
        self::assertSame(
            [
                ['id' => 'si_1900', 'deleted' => 'true'],
                ['id' => 'si_500', 'deleted' => 'true'],
                ['price' => 'price_pro', 'quantity' => '3'],
            ],
            $form['items']
        );
    }

    public function testChangePlanCreatesAProductForAnInlineItemThenPricesAgainstIt(): void
    {
        $fake = $this->changePlanFake();

        $this->adapter($fake)->changePlan(
            'sub_test_1',
            [SubscriptionItem::inline(
                new LineItem('Pro plan', new Money(4900, 'USD'), 2),
                new BillingCycle(BillingInterval::Year, 1)
            )],
            ProrationBillingMode::ProratedImmediately
        );

        self::assertSame(
            [
                'GET /v1/subscriptions/sub_test_1',
                'POST /v1/products',
                'POST /v1/subscriptions/sub_test_1',
                'POST /v1/products/prod_new',
            ],
            self::requestLines($fake)
        );

        $requests = $fake->requests();
        self::assertSame(['name' => 'Pro plan'], self::formOf($requests[1]));

        $items = self::formOf($requests[2])['items'];
        self::assertSame(['id' => 'si_1900', 'deleted' => 'true'], $items[0]);
        self::assertSame(['id' => 'si_500', 'deleted' => 'true'], $items[1]);
        self::assertSame(
            [
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => '4900',
                    'product' => 'prod_new',
                    'recurring' => ['interval' => 'year', 'interval_count' => '1'],
                ],
                'quantity' => '2',
            ],
            $items[2]
        );
        self::assertSame(['active' => 'false'], self::formOf($requests[3]));
    }

    public function testChangePlanArchivesTheProductItCreatedEvenWhenTheChangeIsRefused(): void
    {
        $fake = $this->changePlanFake(update: self::jsonResponse(['error' => [
            'type' => 'card_error',
            'code' => 'card_declined',
            'message' => 'Your card was declined.',
        ]], 402));

        try {
            $this->adapter($fake)->changePlan(
                'sub_test_1',
                [self::inlineItem()],
                ProrationBillingMode::ProratedImmediately
            );
            self::fail('A refused change must throw.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('402', $e->getMessage());
            self::assertStringContainsString('Your card was declined.', $e->getMessage());
        }

        self::assertSame(
            [
                'GET /v1/subscriptions/sub_test_1',
                'POST /v1/products',
                'POST /v1/subscriptions/sub_test_1',
                'POST /v1/products/prod_new',
            ],
            self::requestLines($fake)
        );
        self::assertSame(['active' => 'false'], self::formOf($fake->requests()[3]));
    }

    public function testChangePlanThrowsTheUpdatesErrorWhenArchivingFailsToo(): void
    {
        $fake = $this->changePlanFake(
            update: self::jsonResponse(['error' => ['message' => 'Your card was declined.']], 402),
            archive: self::jsonResponse(['error' => ['message' => 'archive broke']], 500)
        );

        try {
            $this->adapter($fake)->changePlan('sub_test_1', [self::inlineItem()], ProrationBillingMode::DoNotBill);
            self::fail('A refused change must throw.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('Your card was declined.', $e->getMessage());
            self::assertStringNotContainsString('archive broke', $e->getMessage());
        }
    }

    public function testChangePlanReturnsTheChangeEvenWhenArchivingFails(): void
    {
        $fake = $this->changePlanFake(
            update: self::jsonResponse(self::subscription(['status' => 'active'])),
            archive: self::jsonResponse(['error' => ['message' => 'archive broke']], 500)
        );

        $snapshot = $this->adapter($fake)->changePlan(
            'sub_test_1',
            [self::inlineItem()],
            ProrationBillingMode::ProratedImmediately
        );

        self::assertSame('sub_test_1', $snapshot->gatewayReference);
        self::assertSame(SubscriptionStatus::Active, $snapshot->status);
        self::assertSame(4, $fake->requestCount());
    }

    public function testChangePlanArchivesProductsAlreadyCreatedWhenALaterOneFails(): void
    {
        $products = 0;
        $fake = new FakeHttpClient(static function (RequestInterface $request) use (&$products): Response {
            $line = $request->getMethod() . ' ' . $request->getUri()->getPath();

            if ($line === 'POST /v1/products') {
                return ++$products === 1
                    ? self::jsonResponse(['id' => 'prod_first'])
                    : self::jsonResponse(['error' => ['message' => 'no more products']], 400);
            }

            return str_starts_with($line, 'GET ')
                ? self::jsonResponse(self::subscription())
                : self::jsonResponse(['id' => 'prod_first', 'active' => false]);
        });

        try {
            $this->adapter($fake)->changePlan(
                'sub_test_1',
                [self::inlineItem(), self::inlineItem('Add-on')],
                ProrationBillingMode::DoNotBill
            );
            self::fail('A failed product must throw.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('no more products', $e->getMessage());
        }

        self::assertSame(
            [
                'GET /v1/subscriptions/sub_test_1',
                'POST /v1/products',
                'POST /v1/products',
                'POST /v1/products/prod_first',
            ],
            self::requestLines($fake)
        );
    }

    /**
     * @return array<string, array{ProrationBillingMode, string}>
     */
    public static function allowedProrationModes(): array
    {
        return [
            'prorated immediately' => [ProrationBillingMode::ProratedImmediately, 'always_invoice'],
            'prorated next period' => [ProrationBillingMode::ProratedNextBillingPeriod, 'create_prorations'],
            'do not bill' => [ProrationBillingMode::DoNotBill, 'none'],
        ];
    }

    #[DataProvider('allowedProrationModes')]
    public function testChangePlanMapsProrationModes(ProrationBillingMode $mode, string $proration): void
    {
        $fake = $this->changePlanFake();

        $this->adapter($fake)->changePlan('sub_test_1', [SubscriptionItem::catalogPrice('price_pro')], $mode);

        self::assertSame($proration, self::formOf($fake->requests()[1])['proration_behavior']);
    }

    /**
     * @return array<string, array{ProrationBillingMode}>
     */
    public static function fullProrationModes(): array
    {
        return [
            'full immediately' => [ProrationBillingMode::FullImmediately],
            'full next period' => [ProrationBillingMode::FullNextBillingPeriod],
        ];
    }

    #[DataProvider('fullProrationModes')]
    public function testChangePlanRefusesTheFullProrationModesBeforeSendingAnything(ProrationBillingMode $mode): void
    {
        $fake = $this->changePlanFake();

        try {
            $this->adapter($fake)->changePlan('sub_test_1', [SubscriptionItem::catalogPrice('price_pro')], $mode);
            self::fail('A Full* mode has no Stripe equivalent and must be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString($mode->name, $e->getMessage());
            self::assertStringContainsString('billing anchor', $e->getMessage());
            self::assertStringContainsString('ProratedImmediately', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount());
    }

    /**
     * @return array<string, array{PaymentFailureBehaviour, string}>
     */
    public static function paymentFailureBehaviours(): array
    {
        return [
            'prevent change' => [PaymentFailureBehaviour::PreventChange, 'error_if_incomplete'],
            'apply change' => [PaymentFailureBehaviour::ApplyChange, 'allow_incomplete'],
        ];
    }

    #[DataProvider('paymentFailureBehaviours')]
    public function testChangePlanMapsPaymentFailureBehaviour(PaymentFailureBehaviour $behaviour, string $payment): void
    {
        $fake = $this->changePlanFake();

        $this->adapter($fake)->changePlan(
            'sub_test_1',
            [SubscriptionItem::catalogPrice('price_pro')],
            ProrationBillingMode::ProratedImmediately,
            $behaviour
        );

        self::assertSame($payment, self::formOf($fake->requests()[1])['payment_behavior']);
    }

    public function testChangePlanDefaultsToPreventingTheChangeOnPaymentFailure(): void
    {
        $fake = $this->changePlanFake();

        $this->adapter($fake)->changePlan(
            'sub_test_1',
            [SubscriptionItem::catalogPrice('price_pro')],
            ProrationBillingMode::ProratedImmediately
        );

        self::assertSame('error_if_incomplete', self::formOf($fake->requests()[1])['payment_behavior']);
    }

    public function testChangePlanSendsTheIdempotencyKeyAndDerivesOnePerProduct(): void
    {
        $fake = $this->changePlanFake();

        $this->adapter($fake)->changePlan(
            'sub_test_1',
            [SubscriptionItem::catalogPrice('price_a'), self::inlineItem()],
            ProrationBillingMode::ProratedImmediately,
            idempotencyKey: 'chg-9'
        );

        $keys = array_map(
            static fn (RequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri()->getPath()
                . ' ' . $r->getHeaderLine('Idempotency-Key'),
            $fake->requests()
        );

        self::assertSame(
            [
                'GET /v1/subscriptions/sub_test_1 ',
                'POST /v1/products chg-9:product:1',
                'POST /v1/products/prod_new ',
                'POST /v1/subscriptions/sub_test_1 chg-9',
                'POST /v1/products/prod_new ',
            ],
            $keys
        );
    }

    public function testARetryWithTheSameKeyReactivatesTheProductBeforeTheUpdate(): void
    {
        $fake = $this->changePlanFake();

        $this->adapter($fake)->changePlan(
            'sub_test_1',
            [self::inlineItem()],
            ProrationBillingMode::ProratedImmediately,
            idempotencyKey: 'chg-9'
        );

        $requests = $fake->requests();
        self::assertSame(
            [
                'GET /v1/subscriptions/sub_test_1',
                'POST /v1/products',
                'POST /v1/products/prod_new',
                'POST /v1/subscriptions/sub_test_1',
                'POST /v1/products/prod_new',
            ],
            self::requestLines($fake)
        );
        self::assertSame(['active' => 'true'], self::formOf($requests[2]));
        self::assertFalse($requests[2]->hasHeader('Idempotency-Key'));
        self::assertSame('chg-9', $requests[3]->getHeaderLine('Idempotency-Key'));
        self::assertSame(['active' => 'false'], self::formOf($requests[4]));
        self::assertFalse($requests[4]->hasHeader('Idempotency-Key'));
    }

    public function testChangePlanRefusesWhenStripeReturnsOnlyPartOfTheItemList(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(self::subscription([
            'items' => ['object' => 'list', 'has_more' => true, 'data' => [['id' => 'si_a']]],
        ])));

        try {
            $this->adapter($fake)->changePlan('sub_test_1', [self::inlineItem()], ProrationBillingMode::DoNotBill);
            self::fail('A partial item list must be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('sub_test_1', $e->getMessage());
            self::assertStringContainsString('more items', $e->getMessage());
        }

        self::assertSame(1, $fake->requestCount());
    }

    public function testChangePlanSendsNoIdempotencyKeyUnlessGivenOne(): void
    {
        $fake = $this->changePlanFake();

        $this->adapter($fake)->changePlan('sub_test_1', [self::inlineItem()], ProrationBillingMode::DoNotBill);

        foreach ($fake->requests() as $request) {
            self::assertFalse($request->hasHeader('Idempotency-Key'));
        }

        self::assertSame(
            [
                'GET /v1/subscriptions/sub_test_1',
                'POST /v1/products',
                'POST /v1/subscriptions/sub_test_1',
                'POST /v1/products/prod_new',
            ],
            self::requestLines($fake)
        );
    }

    public function testChangePlanRefusesAnEmptyOrMixedIntervalItemSet(): void
    {
        $fake = $this->changePlanFake();
        $monthly = SubscriptionItem::inline(
            new LineItem('Plan', new Money(1000, 'USD')),
            new BillingCycle(BillingInterval::Month)
        );
        $yearly = SubscriptionItem::inline(
            new LineItem('Add-on', new Money(500, 'USD')),
            new BillingCycle(BillingInterval::Year)
        );

        foreach ([[], [$monthly, $yearly]] as $items) {
            try {
                $this->adapter($fake)->changePlan('sub_test_1', $items, ProrationBillingMode::DoNotBill);
                self::fail('An empty or mixed-interval item set must be refused.');
            } catch (CheckoutException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }

        self::assertSame(0, $fake->requestCount());
    }

    public function testChangePlanRefusesAnElementThatIsNotASubscriptionItem(): void
    {
        $fake = $this->changePlanFake();

        try {
            $this->adapter($fake)->changePlan(
                'sub_test_1',
                [SubscriptionItem::catalogPrice('price_a'), 'price_b'],
                ProrationBillingMode::DoNotBill
            );
            self::fail('A bare string is not a SubscriptionItem.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('[1]', $e->getMessage());
            self::assertStringContainsString('SubscriptionItem', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount());
    }

    public function testChangePlanRefusesACatalogueItemThatIsNotAPrice(): void
    {
        $fake = $this->changePlanFake();

        try {
            $this->adapter($fake)->changePlan(
                'sub_test_1',
                [SubscriptionItem::catalogPrice('prod_x')],
                ProrationBillingMode::DoNotBill
            );
            self::fail('A product id is not a price id.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('prod_x', $e->getMessage());
            self::assertStringContainsString('product id', $e->getMessage());
        }

        self::assertSame(0, $fake->requestCount());
    }

    public function testChangePlanRefusesASubscriptionReferenceThatIsNotASubscription(): void
    {
        $fake = $this->changePlanFake();

        try {
            $this->adapter($fake)->changePlan('cs_x', [self::inlineItem()], ProrationBillingMode::DoNotBill);
            self::fail('A checkout session id is not a subscription id.');
        } catch (CheckoutException) {
            self::assertSame(0, $fake->requestCount());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedItemLists(): array
    {
        return [
            'items is not a list' => [['items' => 'nope']],
            'data is not a list' => [['items' => ['data' => 'nope']]],
            'id is not a string' => [['items' => ['data' => [['id' => ['si_x']]]]]],
        ];
    }

    /**
     * @param array<string, mixed> $subscription
     */
    #[DataProvider('malformedItemLists')]
    public function testChangePlanRefusesAMalformedExistingItemList(array $subscription): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(
            array_merge(self::subscription(), $subscription)
        ));

        try {
            $this->adapter($fake)->changePlan(
                'sub_test_1',
                [SubscriptionItem::catalogPrice('price_pro')],
                ProrationBillingMode::DoNotBill
            );
            self::fail('A malformed item list must be refused.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('items', $e->getMessage());
        }

        self::assertSame(1, $fake->requestCount());
    }

    // ---------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------

    private static function inlineItem(string $description = 'Pro plan'): SubscriptionItem
    {
        return SubscriptionItem::inline(
            new LineItem($description, new Money(4900, 'USD')),
            new BillingCycle(BillingInterval::Month)
        );
    }

    /**
     * Answers the GET with a subscription holding `si_1900` and `si_500`, product creation with
     * `prod_new`, the update with a subscription, and an archive with an inactive product.
     */
    private function changePlanFake(?Response $update = null, ?Response $archive = null): FakeHttpClient
    {
        return new FakeHttpClient(static function (RequestInterface $request) use ($update, $archive): Response {
            $path = $request->getUri()->getPath();

            return match (true) {
                $request->getMethod() === 'GET' => self::jsonResponse(self::subscription()),
                $path === '/v1/products' => self::jsonResponse(['id' => 'prod_new', 'active' => true]),
                str_starts_with($path, '/v1/products/') => $archive ?? self::jsonResponse(['id' => 'prod_new', 'active' => false]),
                default => $update ?? self::jsonResponse(self::subscription()),
            };
        });
    }

    /**
     * @return list<string>
     */
    private static function requestLines(FakeHttpClient $fake): array
    {
        return array_map(
            static fn (RequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri()->getPath(),
            $fake->requests()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function formOf(RequestInterface $request): array
    {
        parse_str((string) $request->getBody(), $form);

        return $form;
    }

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
     * An endive-shape subscription: periods only on the items, `flexible` billing mode, and the
     * merchant reference stamped into metadata. Two items — 1900 + 2 x 500 — make 2900.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function subscription(array $overrides = []): array
    {
        $item = static fn (int $unit, int $quantity): array => [
            'id' => 'si_' . $unit,
            'object' => 'subscription_item',
            'quantity' => $quantity,
            'current_period_start' => 1700000000,
            'current_period_end' => 1702592000,
            'price' => [
                'id' => 'price_' . $unit,
                'unit_amount' => $unit,
                'currency' => 'usd',
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ],
        ];

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
            'items' => ['object' => 'list', 'data' => [$item(1900, 1), $item(500, 2)]],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function subscriptionEvent(string $eventId, string $type, array $overrides = [], int $created = 1700000123): string
    {
        return json_encode([
            'id' => $eventId,
            'type' => $type,
            'created' => $created,
            'data' => ['object' => self::subscription($overrides)],
        ], JSON_THROW_ON_ERROR);
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
