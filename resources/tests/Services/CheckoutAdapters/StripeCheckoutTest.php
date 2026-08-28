<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\CheckoutAdapters;

use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
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

    public function testParseCallbackMapsExpiryToCancelled(): void
    {
        $payload = self::eventPayload('evt_3', 'checkout.session.expired', ['status' => 'expired']);

        self::assertSame(
            TransactionStatus::Cancelled,
            $this->adapter()->parseCallback($payload, self::signedHeaders($payload))->status
        );
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
