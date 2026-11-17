<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\CheckoutAdapters;

use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\IdempotencyKeyReusedException;
use Monad\Clarity\Services\Checkout\LineItem;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Services\Checkout\TransactionStatus;
use Monad\Clarity\Services\CheckoutAdapters\StripeCheckout;
use Monad\Clarity\Utils\HMAC;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripeCheckoutTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_test_secret';

    // ---------------------------------------------------------------------------------
    // createCheckout — §9.6.1
    // ---------------------------------------------------------------------------------

    public function testCreateCheckoutSendsFormEncodedSessionAndParsesTheResponse(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => 2500,
            'currency' => 'usd',
            'payment_intent' => 'pi_test_456',
        ]));

        $session = $this->adapter($fake)->createCheckout(new CheckoutRequest(
            reference: 'ORDER-1001',
            amount: new Money(2500, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
            customerEmail: 'buyer@example.com',
            metadata: ['order_id' => '1001'],
        ));

        self::assertSame('stripe_checkout', $session->gateway);
        self::assertSame('cs_test_123', $session->gatewayReference);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_123', $session->redirectUrl);
        self::assertSame(TransactionStatus::Pending, $session->status);
        self::assertSame(2500, $session->amount->minorUnits);
        self::assertSame('USD', $session->amount->currency);
        self::assertSame('pi_test_456', $session->paymentReference);

        $request = $fake->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', (string) $request->getUri());
        self::assertSame('Bearer sk_test_key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

        $body = $fake->decodedLastRequestForm();
        self::assertSame('payment', $body['mode']);
        self::assertSame('https://shop.test/success', $body['success_url']);
        self::assertSame('https://shop.test/cancel', $body['cancel_url']);
        self::assertSame('ORDER-1001', $body['client_reference_id']);
        self::assertSame('buyer@example.com', $body['customer_email']);
        self::assertSame(['order_id' => '1001'], $body['metadata']);
        self::assertSame('ORDER-1001', $body['payment_intent_data']['metadata']['reference']);
    }

    public function testCreateCheckoutSendsTheMerchantReferenceAsAnIdempotencyKeyByDefault(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest());

        self::assertSame('ORDER-1001', $fake->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testCreateCheckoutSynthesisesOneLineItemWhenNoneAreGiven(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest());

        $lineItems = $fake->decodedLastRequestForm()['line_items'];
        self::assertCount(1, $lineItems);
        self::assertSame('1', $lineItems[0]['quantity']);
        self::assertSame('usd', $lineItems[0]['price_data']['currency']);
        self::assertSame('2500', $lineItems[0]['price_data']['unit_amount']);
        self::assertSame('ORDER-1001', $lineItems[0]['price_data']['product_data']['name']);
    }

    public function testCreateCheckoutSendsEachLineItemInStripesNestedShape(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        $this->adapter($fake)->createCheckout(new CheckoutRequest(
            reference: 'ORDER-1001',
            amount: new Money(2500, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
            lineItems: [
                new LineItem('Widget', new Money(1000, 'USD'), 2),
                new LineItem('Shipping', new Money(500, 'USD')),
            ],
        ));

        $lineItems = $fake->decodedLastRequestForm()['line_items'];
        self::assertCount(2, $lineItems);
        self::assertSame('Widget', $lineItems[0]['price_data']['product_data']['name']);
        self::assertSame('1000', $lineItems[0]['price_data']['unit_amount']);
        self::assertSame('2', $lineItems[0]['quantity']);
        self::assertSame('Shipping', $lineItems[1]['price_data']['product_data']['name']);
    }

    public function testCreateCheckoutThrowsOnAGatewayError(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            402,
            ['Content-Type' => 'application/json'],
            '{"error":{"message":"Your card was declined."}}'
        ));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/HTTP 402/');

        $this->adapter($fake)->createCheckout($this->checkoutRequest());
    }

    // ---------------------------------------------------------------------------------
    // Status mapping — a completed page is not a completed payment
    // ---------------------------------------------------------------------------------

    /**
     * @return list<array{string, string, TransactionStatus}>
     */
    public static function sessionStates(): array
    {
        return [
            ['open', 'unpaid', TransactionStatus::Pending],
            ['complete', 'paid', TransactionStatus::Success],
            ['complete', 'no_payment_required', TransactionStatus::Success],
            ['complete', 'unpaid', TransactionStatus::Pending],
            ['expired', 'unpaid', TransactionStatus::Cancelled],
        ];
    }

    #[DataProvider('sessionStates')]
    public function testRetrieveStatusMapsStripesTwoStateFieldsOntoOneStatus(
        string $status,
        string $paymentStatus,
        TransactionStatus $expected
    ): void {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 'cs_test_123',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'amount_total' => 2500,
            'currency' => 'usd',
        ]));

        $snapshot = $this->adapter($fake)->retrieveStatus('cs_test_123');

        self::assertSame($expected, $snapshot->status);
        self::assertSame('GET', $fake->lastRequest()->getMethod());
        self::assertStringStartsWith(
            'https://api.stripe.com/v1/checkout/sessions/cs_test_123',
            (string) $fake->lastRequest()->getUri()
        );
    }

    /**
     * `(int)` pins a numeric string past PHP_INT_MAX to PHP_INT_MAX and refuses such a float with a
     * warning, and Money refuses a negative amount or a malformed currency with an
     * InvalidArgumentException. None of those may escape an adapter; each is a CheckoutException.
     *
     * @return array<string, array{mixed, string, string}>
     */
    public static function unreadableSessionAmounts(): array
    {
        $tooLarge = 'Stripe session carried an amount_total too large to represent as an amount.';
        $unreadable = 'Stripe session carried an amount_total/currency this adapter cannot read: ';

        return [
            'past PHP_INT_MAX as a JSON number' => [1e30, 'usd', $tooLarge],
            'past PHP_INT_MAX as a numeric string' => ['99999999999999999999', 'usd', $tooLarge],
            'negative' => [-100, 'usd', $unreadable . 'A Money amount cannot be negative'],
            'a currency that is not ISO 4217' => [2500, 'us', $unreadable . 'Currency must be'],
        ];
    }

    #[DataProvider('unreadableSessionAmounts')]
    public function testRetrieveStatusRefusesAnAmountItCannotReadAsACheckoutException(mixed $amount, string $currency, string $message): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 'cs_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => $amount,
            'currency' => $currency,
        ]));

        self::assertRefusedWithoutAWarning($message, fn () => $this->adapter($fake)->retrieveStatus('cs_test_123'));
    }

    /**
     * The fallback is for an amount that is absent. One that is present and unreadable is a broken
     * response, and the request's own amount must not paper over it.
     */
    public function testCreateCheckoutRefusesAnUnrepresentableAmountEvenWithAFallback(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => '99999999999999999999',
            'currency' => 'usd',
        ]));

        self::assertRefusedWithoutAWarning(
            'Stripe session carried an amount_total too large to represent as an amount.',
            fn () => $this->adapter($fake)->createCheckout($this->checkoutRequest())
        );
    }

    /**
     * A refund result is read after Stripe has moved the money, so a throw here would invite a
     * retry. A figure that is unreadable reads as absent, falling back exactly as an absent one
     * always has, and never leaks a warning or Money's InvalidArgumentException.
     *
     * @return array<string, array{array<string, mixed>, int, string, string}>
     */
    public static function unreadableRefundFields(): array
    {
        $arr = ['not' => 'a scalar'];

        return [
            'an amount past PHP_INT_MAX' => [['amount' => 1e30], 0, 'USD', 'succeeded'],
            'an array amount' => [['amount' => $arr], 0, 'USD', 'succeeded'],
            'a negative amount' => [['amount' => -5], 0, 'USD', 'succeeded'],
            'an array currency' => [['currency' => $arr], 2500, 'USD', 'succeeded'],
            'a currency that is not ISO 4217' => [['currency' => 'us'], 0, 'USD', 'succeeded'],
            'an array status' => [['status' => $arr], 2500, 'USD', 'unknown'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('unreadableRefundFields')]
    public function testRefundReadsAnUnreadableResultFieldAsAbsentAndNeverThrows(
        array $overrides,
        int $minorUnits,
        string $currency,
        string $status
    ): void {
        $refund = array_merge(['id' => 're_test_1', 'amount' => 2500, 'currency' => 'usd', 'status' => 'succeeded'], $overrides);
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse($refund));

        $result = self::withoutAWarning(fn () => $this->adapter($fake)->refund(new RefundRequest(reference: 'pi_test_456')));

        self::assertSame($minorUnits, $result->amount->minorUnits);
        self::assertSame($currency, $result->amount->currency);
        self::assertSame($status, $result->status);
    }

    public function testCreateCheckoutReadsAnUnreadableUrlAsNone(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 'cs_test_123',
            'url' => ['not' => 'a string'],
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => 2500,
            'currency' => 'usd',
        ]));

        $session = self::withoutAWarning(fn () => $this->adapter($fake)->createCheckout($this->checkoutRequest()));

        self::assertNull($session->redirectUrl);
    }

    private static function withoutAWarning(callable $call): mixed
    {
        set_error_handler(static function (int $severity, string $text, string $file, int $line): bool {
            throw new \ErrorException($text, 0, $severity, $file, $line);
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Runs $call under the framework's own behaviour (Mediator::register turns every warning into
     * an ErrorException) and requires a CheckoutException whose message starts with $message.
     */
    private static function assertRefusedWithoutAWarning(string $message, callable $call): void
    {
        set_error_handler(static function (int $severity, string $text, string $file, int $line): bool {
            throw new \ErrorException($text, 0, $severity, $file, $line);
        });

        try {
            $call();
            self::fail('Expected the amount to be refused.');
        } catch (CheckoutException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testRetrieveStatusExpandsThePaymentIntentSoFailuresAreVisible(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::openSessionResponse());

        $this->adapter($fake)->retrieveStatus('cs_test_123');

        self::assertStringContainsString(
            'expand%5B0%5D=payment_intent',
            (string) $fake->lastRequest()->getUri()
        );
    }

    /**
     * A Checkout Session reports only paid/unpaid, so without the expanded PaymentIntent a
     * failed async payment would re-query as pending indefinitely.
     */
    public function testRetrieveStatusReportsAFailureCarriedOnTheExpandedPaymentIntent(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 'cs_test_123',
            'status' => 'complete',
            'payment_status' => 'unpaid',
            'amount_total' => 2500,
            'currency' => 'usd',
            'payment_intent' => [
                'id' => 'pi_test_456',
                'status' => 'requires_payment_method',
                'last_payment_error' => ['message' => 'The bank declined the debit.'],
            ],
        ]));

        $snapshot = $this->adapter($fake)->retrieveStatus('cs_test_123');

        self::assertSame(TransactionStatus::Failed, $snapshot->status);
        self::assertSame('The bank declined the debit.', $snapshot->failureReason);
        self::assertSame('pi_test_456', $snapshot->paymentReference);
    }

    public function testRetrieveStatusReportsACancelledPaymentIntentAsCancelled(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 'cs_test_123',
            'status' => 'complete',
            'payment_status' => 'unpaid',
            'amount_total' => 2500,
            'currency' => 'usd',
            'payment_intent' => ['id' => 'pi_test_456', 'status' => 'canceled'],
        ]));

        $snapshot = $this->adapter($fake)->retrieveStatus('cs_test_123');

        self::assertSame(TransactionStatus::Cancelled, $snapshot->status);
        self::assertNull($snapshot->failureReason);
    }

    // ---------------------------------------------------------------------------------
    // parseCallback signature verification — TestingStrategy tier 1
    // ---------------------------------------------------------------------------------

    public function testParseCallbackAcceptsAValidlySignedEventAndNormalisesIt(): void
    {
        $payload = self::eventPayload('evt_1', 'checkout.session.completed', ['payment_status' => 'paid']);

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame('evt_1', $event->eventId);
        self::assertSame('checkout.session.completed', $event->eventType);
        self::assertSame('cs_test_123', $event->gatewayReference);
        self::assertSame(TransactionStatus::Success, $event->status);
        self::assertNull($event->failureReason);
    }

    public function testParseCallbackRejectsATamperedPayload(): void
    {
        $payload = self::eventPayload('evt_1', 'checkout.session.completed', ['payment_status' => 'paid']);
        $headers = self::signedHeaders($payload);

        // The signature is genuine — for a different body. This is the attack the scheme exists to stop.
        $tampered = str_replace('2500', '1', $payload);
        self::assertNotSame($payload, $tampered);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseCallback($tampered, $headers);
    }

    public function testParseCallbackRejectsASignatureFromTheWrongSecret(): void
    {
        $payload = self::eventPayload('evt_1', 'checkout.session.completed');
        $headers = self::signedHeaders($payload, secret: 'whsec_someone_elses_secret');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseCallback($payload, $headers);
    }

    public function testParseCallbackRejectsATimestampOutsideTheReplayTolerance(): void
    {
        $payload = self::eventPayload('evt_1', 'checkout.session.completed');
        $headers = self::signedHeaders($payload, timestamp: time() - 3600);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/replay/');

        $this->adapter()->parseCallback($payload, $headers);
    }

    public function testParseCallbackRejectsAMissingSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/no Stripe-Signature header/');

        $this->adapter()->parseCallback(self::eventPayload('evt_1', 'checkout.session.completed'), []);
    }

    public function testParseCallbackRejectsAMalformedSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/malformed/');

        $this->adapter()->parseCallback(
            self::eventPayload('evt_1', 'checkout.session.completed'),
            ['Stripe-Signature' => 'v1=deadbeef']
        );
    }

    public function testParseCallbackRefusesToRunAtAllWithoutASigningSecret(): void
    {
        $payload = self::eventPayload('evt_1', 'checkout.session.completed');
        $adapter = new StripeCheckout('sk_test_key', new FakeHttpClient(static fn (): Response => self::openSessionResponse()));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/without a webhook signing secret/');

        $adapter->parseCallback($payload, self::signedHeaders($payload));
    }

    public function testParseCallbackMatchesTheSignatureHeaderCaseInsensitively(): void
    {
        $payload = self::eventPayload('evt_1', 'checkout.session.completed', ['payment_status' => 'paid']);
        $headers = ['stripe-signature' => self::signedHeaders($payload)['Stripe-Signature']];

        self::assertSame('evt_1', $this->adapter()->parseCallback($payload, $headers)->eventId);
    }

    public function testParseCallbackAcceptsAnyOneOfSeveralSignaturesDuringSecretRotation(): void
    {
        $payload = self::eventPayload('evt_1', 'checkout.session.completed', ['payment_status' => 'paid']);
        $timestamp = time();
        $valid = HMAC::sign($timestamp . '.' . $payload, self::WEBHOOK_SECRET);

        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, str_repeat('0', 64), $valid);

        self::assertSame('evt_1', $this->adapter()->parseCallback($payload, ['Stripe-Signature' => $header])->eventId);
    }

    public function testParseCallbackMapsAsyncFailureToFailedWithItsReason(): void
    {
        $payload = self::eventPayload('evt_2', 'checkout.session.async_payment_failed', [
            'payment_status' => 'unpaid',
            'last_payment_error' => ['message' => 'The bank declined the debit.'],
        ]);

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame(TransactionStatus::Failed, $event->status);
        self::assertSame('The bank declined the debit.', $event->failureReason);
    }

    /**
     * A Stripe endpoint receives every event type enabled on it, and the default is all of
     * them. Found by a live `stripe listen` run: without the object-type guard these parsed
     * "successfully" into a CallbackEvent whose gatewayReference was a product or charge id.
     *
     * @return list<array{string, string, string}>
     */
    public static function nonCheckoutEvents(): array
    {
        return [
            ['product.created', 'product', 'prod_V9bcHuoKi1ahsr'],
            ['price.created', 'price', 'price_1U9IP3AIdo'],
            ['charge.succeeded', 'charge', 'ch_3U9IP5AIdoJYd'],
            ['payment_intent.succeeded', 'payment_intent', 'pi_3U9IP5AIdoJYd'],
        ];
    }

    #[DataProvider('nonCheckoutEvents')]
    public function testParseCallbackRefusesAnEventThatIsNotACheckoutSession(
        string $type,
        string $objectType,
        string $objectId
    ): void {
        $payload = json_encode([
            'id' => 'evt_unrelated',
            'type' => $type,
            'data' => ['object' => ['id' => $objectId, 'object' => $objectType]],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/not a checkout\.session/');

        $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
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

    public function testParseCallbackMapsExpiryToCancelled(): void
    {
        $payload = self::eventPayload('evt_3', 'checkout.session.expired', ['status' => 'expired']);

        self::assertSame(
            TransactionStatus::Cancelled,
            $this->adapter()->parseCallback($payload, self::signedHeaders($payload))->status
        );
    }

    /**
     * Signed does not mean well-formed. Mediator::register() turns every warning into an
     * ErrorException, and an array hitting a string cast is a warning, so a field of the wrong
     * type must be refused as a CheckoutException or read as absent — never leaked as one.
     *
     * A null status means the payload is refused.
     *
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, ?TransactionStatus, ?string}>
     */
    public static function malformedEvents(): array
    {
        $arr = ['not' => 'a string'];

        return [
            'array event id is refused' => [static fn (array $e): array => ['id' => $arr] + $e, null, null],
            'array event type is refused' => [static fn (array $e): array => ['type' => $arr] + $e, null, null],
            'array object type is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['object'] = $arr;

                return $e;
            }, null, null],
            'array object id is refused' => [static function (array $e) use ($arr): array {
                $e['data']['object']['id'] = $arr;

                return $e;
            }, null, null],
            'array payment_status reads as unpaid' => [static function (array $e) use ($arr): array {
                $e['data']['object']['payment_status'] = $arr;

                return $e;
            }, TransactionStatus::Pending, null],
            'array session status reads as open' => [static function (array $e) use ($arr): array {
                $e['data']['object']['status'] = $arr;

                return $e;
            }, TransactionStatus::Pending, null],
            'array intent status reads as absent' => [static function (array $e) use ($arr): array {
                $e['data']['object']['payment_intent'] = ['id' => 'pi_test_456', 'status' => $arr];

                return $e;
            }, TransactionStatus::Pending, null],
            'array failure message falls back to the event type' => [static function (array $e) use ($arr): array {
                $e['type'] = 'checkout.session.async_payment_failed';
                $e['data']['object']['last_payment_error'] = ['message' => $arr];

                return $e;
            }, TransactionStatus::Failed, 'checkout.session.async_payment_failed'],
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $malform
     */
    #[DataProvider('malformedEvents')]
    public function testParseCallbackRefusesOrToleratesASignedButMalformedPayloadWithoutLeakingAWarning(
        callable $malform,
        ?TransactionStatus $status,
        ?string $failureReason
    ): void {
        $event = json_decode(self::eventPayload('evt_bad', 'checkout.session.completed'), true, flags: JSON_THROW_ON_ERROR);
        $payload = json_encode($malform($event), JSON_THROW_ON_ERROR);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $parsed = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
        } catch (CheckoutException $e) {
            self::assertNull($status, sprintf('Expected the payload to parse, but it was refused: %s', $e->getMessage()));

            return;
        } finally {
            restore_error_handler();
        }

        self::assertNotNull($status, 'Expected the payload to be refused, but it parsed.');
        self::assertSame($status, $parsed->status);
        self::assertSame($failureReason, $parsed->failureReason);
    }

    // ---------------------------------------------------------------------------------
    // refund — §9.6.6
    // ---------------------------------------------------------------------------------

    public function testRefundPostsAgainstThePaymentIntentDirectly(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 're_test_1',
            'amount' => 2500,
            'currency' => 'usd',
            'status' => 'succeeded',
        ]));

        $result = $this->adapter($fake)->refund(new RefundRequest(reference: 'pi_test_456'));

        self::assertSame(1, $fake->requestCount(), 'A payment intent reference needs no resolving call.');
        self::assertSame('re_test_1', $result->gatewayRefundId);
        self::assertSame('succeeded', $result->status);
        self::assertSame(2500, $result->amount->minorUnits);
        self::assertSame('https://api.stripe.com/v1/refunds', (string) $fake->lastRequest()->getUri());
        self::assertSame('pi_test_456', $fake->decodedLastRequestForm()['payment_intent']);
    }

    public function testRefundResolvesACheckoutSessionReferenceToItsPaymentIntentFirst(): void
    {
        $fake = new FakeHttpClient(static function (\Psr\Http\Message\RequestInterface $request): Response {
            return str_contains((string) $request->getUri(), '/checkout/sessions/')
                ? self::jsonResponse(['id' => 'cs_test_123', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 2500, 'currency' => 'usd', 'payment_intent' => 'pi_test_456'])
                : self::jsonResponse(['id' => 're_test_1', 'amount' => 1000, 'currency' => 'usd', 'status' => 'succeeded']);
        });

        $result = $this->adapter($fake)->refund(new RefundRequest(
            reference: 'cs_test_123',
            amount: new Money(1000, 'USD'),
        ));

        self::assertSame(2, $fake->requestCount());
        self::assertSame('pi_test_456', $result->reference);
        self::assertSame('pi_test_456', $fake->decodedLastRequestForm()['payment_intent']);
        self::assertSame('1000', $fake->decodedLastRequestForm()['amount']);
    }

    public function testRefundExplainsItselfWhenTheSessionWasNeverPaid(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse([
            'id' => 'cs_test_123',
            'status' => 'expired',
            'payment_status' => 'unpaid',
            'amount_total' => 2500,
            'currency' => 'usd',
        ]));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/never completed/');

        $this->adapter($fake)->refund(new RefundRequest(reference: 'cs_test_123'));
    }

    public function testRefundPassesAStripeRecognisedReasonThroughAsReason(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::refundResponse());

        $this->adapter($fake)->refund(new RefundRequest(reference: 'pi_test_456', reason: 'fraudulent'));

        self::assertSame('fraudulent', $fake->decodedLastRequestForm()['reason']);
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

    // ---------------------------------------------------------------------------------
    // a reused idempotency key — ReleaseNotes_1.12.0.md
    // ---------------------------------------------------------------------------------

    public function testCreateCheckoutReportsAReusedKeyAsItsOwnException(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::reusedKeyResponse('ORDER-1001'));

        try {
            $this->adapter($fake)->createCheckout($this->checkoutRequest());
            self::fail('A reused key must throw.');
        } catch (IdempotencyKeyReusedException $e) {
            self::assertSame('ORDER-1001', $e->idempotencyKey, 'The default key is the order reference.');
            self::assertSame(
                'Stripe refused idempotency key "ORDER-1001": a different request was already made under it, '
                . 'so no checkout session was created. A checkout\'s key is its order reference unless you pass '
                . 'one, so this usually means the same reference was sent again with a different amount, line '
                . 'items or URLs. If the order changed, give this attempt its own key, a new reference or an '
                . 'explicit idempotencyKey. Creating a session charges nothing, but the earlier session stays '
                . 'payable until it expires, so make sure the order can be paid only once.',
                $e->getMessage()
            );
        }
    }

    public function testCreateCheckoutReportsAnExplicitKeyItWasGiven(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::reusedKeyResponse('attempt-2'));
        $request = new CheckoutRequest(
            reference: 'ORDER-1001',
            amount: new Money(2500, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
            idempotencyKey: 'attempt-2',
        );

        try {
            $this->adapter($fake)->createCheckout($request);
            self::fail('A reused key must throw.');
        } catch (IdempotencyKeyReusedException $e) {
            self::assertSame('attempt-2', $e->idempotencyKey);
            self::assertSame('attempt-2', $fake->lastRequest()?->getHeaderLine('Idempotency-Key'));
        }
    }

    /**
     * send() takes no key and PHP drops an extra argument silently, so this is what would catch
     * a refund that stopped sending its key.
     */
    public function testRefundSendsItsDerivedIdempotencyKey(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::refundResponse());

        $this->adapter($fake)->refund(new RefundRequest(reference: 'pi_test_456'));

        self::assertSame('refund:pi_test_456:full', $fake->lastRequest()?->getHeaderLine('Idempotency-Key'));
    }

    public function testRefundReportsAReusedKeyAndWarnsAgainstAFreshOne(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::reusedKeyResponse('refund:pi_test_456:full'));

        try {
            $this->adapter($fake)->refund(new RefundRequest(reference: 'pi_test_456'));
            self::fail('A reused key must throw.');
        } catch (IdempotencyKeyReusedException $e) {
            self::assertSame('refund:pi_test_456:full', $e->idempotencyKey);
            self::assertSame(
                'Stripe refused idempotency key "refund:pi_test_456:full": a different request was already made '
                . 'under it, so no refund was made. A refund\'s key is derived from its reference and amount '
                . 'unless you pass one, so this usually means a refund of that amount on that payment was already '
                . 'requested with other details. Check the refunds already made on that payment before trying '
                . 'again: a new key is a new refund, and could refund the customer twice.',
                $e->getMessage()
            );
        }
    }

    /**
     * Only Stripe's own refusal of a reused key is that exception. A 409 is a request still in
     * flight under the key, which is a different situation with different advice.
     *
     * @return array<string, array{Response}>
     */
    public static function failuresThatAreNotAReusedKey(): array
    {
        return [
            'an ordinary 400' => [self::jsonResponse(['error' => [
                'type' => 'invalid_request_error',
                'message' => 'No such price: price_nope',
            ]], 400)],
            'a 409 for a request in flight' => [self::keyInUseResponse('ORDER-1001')],
            'a 409 with another code' => [self::jsonResponse(['error' => [
                'type' => 'invalid_request_error',
                'code' => 'lock_timeout',
                'message' => 'This object cannot be accessed right now because another API request is using it.',
            ]], 409)],
            'a 400 that is not JSON' => [new Response(400, [], '<html>Bad Request</html>')],
        ];
    }

    #[DataProvider('failuresThatAreNotAReusedKey')]
    public function testOtherFailuresUnderAKeyStayAPlainCheckoutException(Response $response): void
    {
        $fake = new FakeHttpClient(static fn (): Response => $response);

        try {
            $this->adapter($fake)->createCheckout($this->checkoutRequest());
            self::fail('A refused request must throw.');
        } catch (CheckoutException $e) {
            self::assertSame(CheckoutException::class, $e::class);
        }
    }

    /**
     * A key still held by a request in flight is a 409, `invalid_request_error`, code
     * `idempotency_key_in_use` — measured live, not the `idempotency_error` the 1.12.0 test
     * assumed. It stays a plain CheckoutException (ReleaseNotes_1.12.0.md §2.7), with advice of
     * its own: Stripe's text never says to keep the same key.
     */
    public function testCreateCheckoutExplainsAKeyStillInUse(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::keyInUseResponse('ORDER-1001'));

        try {
            $this->adapter($fake)->createCheckout($this->checkoutRequest());
            self::fail('A key in use must throw.');
        } catch (CheckoutException $e) {
            self::assertSame(CheckoutException::class, $e::class);
            self::assertSame(
                'Stripe is still processing an earlier request made under idempotency key "ORDER-1001", so '
                . 'this one was not carried out. Retry with the same key once that request has finished: Stripe '
                . 'will then answer with the earlier request\'s outcome. Do not switch to a new key, which would '
                . 'make a second, separate request.',
                $e->getMessage()
            );
        }
    }

    public function testRefundExplainsAKeyStillInUse(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::keyInUseResponse('refund:pi_test_456:full'));

        try {
            $this->adapter($fake)->refund(new RefundRequest(reference: 'pi_test_456'));
            self::fail('A key in use must throw.');
        } catch (CheckoutException $e) {
            self::assertSame(CheckoutException::class, $e::class);
            self::assertStringContainsString('idempotency key "refund:pi_test_456:full"', $e->getMessage());
            self::assertStringContainsString('Do not switch to a new key', $e->getMessage());
        }
    }

    public function testA409WithAnotherCodeKeepsTheGenericSummary(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::jsonResponse(['error' => [
            'type' => 'invalid_request_error',
            'code' => 'lock_timeout',
            'message' => 'This object cannot be accessed right now because another API request is using it.',
        ]], 409));

        try {
            $this->adapter($fake)->createCheckout($this->checkoutRequest());
            self::fail('A refused request must throw.');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('request failed with HTTP 409', $e->getMessage());
            self::assertStringNotContainsString('still processing', $e->getMessage());
        }
    }

    /**
     * The body Stripe returned live, at both pinned API versions; the run ids are in the CHANGELOG.
     */
    private static function keyInUseResponse(string $key): Response
    {
        return self::jsonResponse(['error' => [
            'type' => 'invalid_request_error',
            'code' => 'idempotency_key_in_use',
            'message' => 'There is currently another in-progress request using this Idempotent Key (that probably '
                . "means you submitted twice, and the other request is still going through): {$key}. Please try "
                . 'again later.',
        ]], 409);
    }

    private static function reusedKeyResponse(string $key): Response
    {
        return self::jsonResponse(['error' => [
            'type' => 'idempotency_error',
            'message' => "Keys for idempotent requests can only be used with the same parameters they were first "
                . "used with. Try using a key other than '{$key}' if you meant to execute a different request.",
        ]], 400);
    }

    private function adapter(?FakeHttpClient $fake = null): StripeCheckout
    {
        return new StripeCheckout(
            'sk_test_key',
            $fake ?? new FakeHttpClient(static fn (): Response => self::openSessionResponse()),
            self::WEBHOOK_SECRET
        );
    }

    private function checkoutRequest(): CheckoutRequest
    {
        return new CheckoutRequest(
            reference: 'ORDER-1001',
            amount: new Money(2500, 'USD'),
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
            'amount_total' => 2500,
            'currency' => 'usd',
        ]);
    }

    private static function refundResponse(): Response
    {
        return self::jsonResponse(['id' => 're_test_1', 'amount' => 2500, 'currency' => 'usd', 'status' => 'succeeded']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function eventPayload(string $eventId, string $type, array $overrides = []): string
    {
        return json_encode([
            'id' => $eventId,
            'type' => $type,
            'data' => ['object' => array_merge([
                'id' => 'cs_test_123',
                'object' => 'checkout.session',
                'status' => 'complete',
                'payment_status' => 'unpaid',
                'amount_total' => 2500,
                'currency' => 'usd',
                'payment_intent' => 'pi_test_456',
            ], $overrides)],
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
