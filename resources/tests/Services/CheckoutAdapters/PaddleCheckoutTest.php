<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\CheckoutAdapters;

use Closure;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\LineItem;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Services\Checkout\TransactionStatus;
use Monad\Clarity\Services\CheckoutAdapters\PaddleCheckout;
use Monad\Clarity\Utils\HMAC;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class PaddleCheckoutTest extends TestCase
{
    private const WEBHOOK_SECRET = 'pdl_ntfset_test_secret';
    private const HOSTED_CHECKOUT_URL = 'https://pay.paddle.io/checkout/hsc_01jt8s46kx4nv91002z7vy4ecj_1as3scas9c';
    private const PAYMENT_PAGE_URL = 'https://shop.test/pay';
    private const CATALOG_PRICE_ID = 'pri_test_starter_monthly';

    // ---------------------------------------------------------------------------------
    // createCheckout — §9.6.1
    // ---------------------------------------------------------------------------------

    public function testCreateCheckoutSendsAJsonTransactionAndParsesTheResponse(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $session = $this->adapter($fake)->createCheckout($this->checkoutRequest());

        self::assertSame('paddle_checkout', $session->gateway);
        self::assertSame('txn_test_123', $session->gatewayReference);
        self::assertSame(TransactionStatus::Pending, $session->status);
        self::assertSame(2500, $session->amount->minorUnits);
        self::assertSame('USD', $session->amount->currency);
        self::assertNull($session->paymentReference, 'Paddle refunds the transaction itself — there is no second id.');

        $request = $fake->lastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.paddle.com/transactions', (string) $request->getUri());
        self::assertSame('Bearer pdl_sdbx_apikey_test', $request->getHeaderLine('Authorization'));
        self::assertSame('1', $request->getHeaderLine('Paddle-Version'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = $fake->decodedLastRequestBody();
        self::assertSame('USD', $body['currency_code']);
        self::assertSame('automatic', $body['collection_mode']);
    }

    public function testCreateCheckoutCarriesTheMerchantReferenceAndReturnUrlsInCustomData(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest());

        self::assertSame([
            'reference' => 'ORDER-1001',
            'idempotency_key' => 'ORDER-1001',
            'success_url' => 'https://shop.test/success',
            'cancel_url' => 'https://shop.test/cancel',
        ], $fake->decodedLastRequestBody()['custom_data']);
    }

    /**
     * custom_data is the only field echoed back on every webhook, so a metadata key that
     * displaced the reference would break reconciliation silently.
     */
    public function testCreateCheckoutKeepsItsOwnCustomDataKeysAheadOfCollidingMetadata(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake)->createCheckout(new CheckoutRequest(
            reference: 'ORDER-1001',
            amount: new Money(2500, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
            metadata: ['reference' => 'NOT-THE-ORDER', 'basket' => 'B-7'],
        ));

        $customData = $fake->decodedLastRequestBody()['custom_data'];
        self::assertSame('ORDER-1001', $customData['reference']);
        self::assertSame('B-7', $customData['basket']);
    }

    public function testCreateCheckoutSynthesisesOneItemWhenNoneAreGiven(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest());

        self::assertSame([[
            'quantity' => 1,
            'price' => [
                'name' => 'ORDER-1001',
                'description' => 'ORDER-1001',
                'unit_price' => ['amount' => '2500', 'currency_code' => 'USD'],
                'product' => ['name' => 'ORDER-1001', 'tax_category' => 'standard'],
            ],
        ]], $fake->decodedLastRequestBody()['items']);
    }

    public function testCreateCheckoutSendsEachLineItemAsANonCatalogPriceAndProduct(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake)->createCheckout(new CheckoutRequest(
            reference: 'ORDER-1001',
            amount: new Money(2500, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
            lineItems: [
                new LineItem('Field notebook', new Money(1000, 'USD'), 2),
                new LineItem('Postage', new Money(500, 'USD')),
            ],
        ));

        $items = $fake->decodedLastRequestBody()['items'];
        self::assertCount(2, $items);
        self::assertSame(2, $items[0]['quantity']);
        self::assertSame('Field notebook', $items[0]['price']['product']['name']);
        self::assertSame('1000', $items[0]['price']['unit_price']['amount']);
        self::assertSame('500', $items[1]['price']['unit_price']['amount']);
    }

    /**
     * An inline price carrying a billing_cycle is a subscription, not a one-time payment.
     * Checkout deals only in the latter, and the absence of that key is the whole guarantee.
     */
    public function testCreateCheckoutNeverSendsABillingCycle(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest());

        self::assertArrayNotHasKey('billing_cycle', $fake->decodedLastRequestBody()['items'][0]['price']);
    }

    /**
     * Paddle states amounts in the currency's lowest denomination, which for JPY is whole
     * yen. Money holds exactly that, so ¥1,200 must reach the wire as "1200" — a hundredfold
     * error is the failure this guards.
     */
    public function testCreateCheckoutSendsAZeroDecimalAmountUnscaled(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse([
            'currency_code' => 'JPY',
            'details' => ['totals' => ['grand_total' => '1200']],
        ]));

        $session = $this->adapter($fake)->createCheckout(new CheckoutRequest(
            reference: 'ORDER-1001',
            amount: new Money(1200, 'JPY'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
        ));

        $body = $fake->decodedLastRequestBody();
        self::assertSame('JPY', $body['currency_code']);
        self::assertSame('1200', $body['items'][0]['price']['unit_price']['amount']);
        self::assertSame(1200, $session->amount->minorUnits);
    }

    public function testCreateCheckoutAppendsTheTransactionToAHostedCheckoutLink(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $session = $this->adapter($fake)->createCheckout($this->checkoutRequest());

        self::assertSame(
            self::HOSTED_CHECKOUT_URL . '?transaction_id=txn_test_123',
            $session->redirectUrl
        );
        self::assertArrayNotHasKey(
            'checkout',
            $fake->decodedLastRequestBody(),
            'A hosted checkout link does the hosting, so there is no payment link to override.'
        );
    }

    public function testCreateCheckoutAppendsToAHostedLinkThatAlreadyCarriesAQueryString(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $adapter = new PaddleCheckout(
            'pdl_sdbx_apikey_test',
            $fake,
            self::WEBHOOK_SECRET,
            hostedCheckoutUrl: self::HOSTED_CHECKOUT_URL . '?theme=dark',
        );

        self::assertSame(
            self::HOSTED_CHECKOUT_URL . '?theme=dark&transaction_id=txn_test_123',
            $adapter->createCheckout($this->checkoutRequest())->redirectUrl
        );
    }

    public function testCreateCheckoutPassesTheCustomerEmailToTheHostedCheckout(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $session = $this->adapter($fake)->createCheckout(new CheckoutRequest(
            reference: 'ORDER-1001',
            amount: new Money(2500, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
            customerEmail: 'buyer@example.com',
        ));

        self::assertStringContainsString('user_email=buyer%40example.com', (string) $session->redirectUrl);
    }

    public function testCreateCheckoutOverridesThePaymentLinkAndReturnsPaddlesOwnCheckoutUrl(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $session = $this->pageAdapter($fake)->createCheckout($this->checkoutRequest());

        self::assertSame(
            ['url' => self::PAYMENT_PAGE_URL],
            $fake->decodedLastRequestBody()['checkout'],
            'One Paddle account can serve several applications, so the link is set per transaction.'
        );
        self::assertSame(
            'https://shop.test/pay?_ptxn=txn_test_123'
                . '&success_url=https%3A%2F%2Fshop.test%2Fsuccess&cancel_url=https%3A%2F%2Fshop.test%2Fcancel',
            $session->redirectUrl
        );
    }

    /**
     * Paddle composes checkout.url itself, but a transaction that has not reached a state
     * where it carries one still has to send the customer somewhere.
     */
    public function testCreateCheckoutComposesThePaymentPageUrlWhenPaddleReturnsNone(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse(['checkout' => null]));

        $session = $this->pageAdapter($fake)->createCheckout($this->checkoutRequest());

        self::assertStringStartsWith('https://shop.test/pay?_ptxn=txn_test_123&success_url=', (string) $session->redirectUrl);
    }

    public function testCreateCheckoutRefusesToRunWithoutACheckoutUrl(): void
    {
        $adapter = new PaddleCheckout(
            'pdl_sdbx_apikey_test',
            new FakeHttpClient(static fn (): Response => self::transactionResponse()),
            self::WEBHOOK_SECRET
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/without a checkout URL/');

        $adapter->createCheckout($this->checkoutRequest());
    }

    public function testCreateCheckoutRefusesToRunWithBothCheckoutUrls(): void
    {
        $adapter = new PaddleCheckout(
            'pdl_sdbx_apikey_test',
            new FakeHttpClient(static fn (): Response => self::transactionResponse()),
            self::WEBHOOK_SECRET,
            hostedCheckoutUrl: self::HOSTED_CHECKOUT_URL,
            paymentPageUrl: self::PAYMENT_PAGE_URL,
        );

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/pass exactly one/');

        $adapter->createCheckout($this->checkoutRequest());
    }

    public function testCreateCheckoutThrowsOnAGatewayError(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => new Response(
            400,
            ['Content-Type' => 'application/json'],
            '{"error":{"code":"transaction_immutable","detail":"Transaction cannot be changed."}}'
        ));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/HTTP 400/');

        $this->adapter($fake)->createCheckout($this->checkoutRequest());
    }

    // ---------------------------------------------------------------------------------
    // retrieveStatus — §9.6.3
    // ---------------------------------------------------------------------------------

    /**
     * Paddle's seven states onto §9.6.5's four. Note `canceled` — Paddle spells it with one
     * l, the enum with two, and a fixture copied from the Stripe suite would assert a status
     * that never actually arrives.
     *
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
            ['past_due', TransactionStatus::Failed],
        ];
    }

    #[DataProvider('transactionStates')]
    public function testRetrieveStatusMapsPaddlesTransactionStates(string $state, TransactionStatus $expected): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse(['status' => $state]));

        $snapshot = $this->adapter($fake)->retrieveStatus('txn_test_123');

        self::assertSame($expected, $snapshot->status);
        self::assertSame(2500, $snapshot->amount->minorUnits);

        $request = $fake->lastRequest();
        self::assertNotNull($request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.paddle.com/transactions/txn_test_123', (string) $request->getUri());
    }

    public function testRetrieveStatusReportsTheErrorCodeOfTheLastFailedPayment(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse([
            'status' => 'past_due',
            'payments' => [
                ['status' => 'error', 'error_code' => 'expired_card'],
                ['status' => 'error', 'error_code' => 'insufficient_funds'],
            ],
        ]));

        $snapshot = $this->adapter($fake)->retrieveStatus('txn_test_123');

        self::assertSame(TransactionStatus::Failed, $snapshot->status);
        self::assertSame('insufficient_funds', $snapshot->failureReason);
    }

    public function testRetrieveStatusLeavesTheFailureReasonNullOnASettledTransaction(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse([
            'status' => 'completed',
            'payments' => [['status' => 'captured', 'error_code' => null]],
        ]));

        self::assertNull($this->adapter($fake)->retrieveStatus('txn_test_123')->failureReason);
    }

    // ---------------------------------------------------------------------------------
    // parseCallback — §9.6.4
    // ---------------------------------------------------------------------------------

    public function testParseCallbackAcceptsAValidlySignedEventAndNormalisesIt(): void
    {
        $payload = self::eventPayload('evt_1', 'transaction.completed');

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame('paddle_checkout', $event->gateway);
        self::assertSame('evt_1', $event->eventId);
        self::assertSame('transaction.completed', $event->eventType);
        self::assertSame('txn_test_123', $event->gatewayReference);
        self::assertSame(TransactionStatus::Success, $event->status);
        self::assertNull($event->failureReason);
    }

    public function testParseCallbackRejectsATamperedPayload(): void
    {
        $payload = self::eventPayload('evt_1', 'transaction.completed');
        $headers = self::signedHeaders($payload);
        $tampered = str_replace('2500', '1', $payload);

        self::assertNotSame($payload, $tampered, 'The tamper must actually change the body.');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseCallback($tampered, $headers);
    }

    public function testParseCallbackRejectsASignatureFromTheWrongSecret(): void
    {
        $payload = self::eventPayload('evt_1', 'transaction.completed');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/did not verify/');

        $this->adapter()->parseCallback($payload, self::signedHeaders($payload, secret: 'pdl_ntfset_someone_else'));
    }

    public function testParseCallbackRejectsATimestampOutsideTheReplayTolerance(): void
    {
        $payload = self::eventPayload('evt_1', 'transaction.completed');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/replay/');

        $this->adapter()->parseCallback($payload, self::signedHeaders($payload, time() - 3600));
    }

    public function testParseCallbackRejectsAMissingSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/no Paddle-Signature header/');

        $this->adapter()->parseCallback(self::eventPayload('evt_1', 'transaction.completed'), []);
    }

    public function testParseCallbackRejectsAMalformedSignatureHeader(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/malformed/');

        $this->adapter()->parseCallback(
            self::eventPayload('evt_1', 'transaction.completed'),
            ['Paddle-Signature' => 'h1=deadbeef']
        );
    }

    public function testParseCallbackRefusesToRunAtAllWithoutASigningSecret(): void
    {
        $adapter = new PaddleCheckout(
            'pdl_sdbx_apikey_test',
            new FakeHttpClient(static fn (): Response => self::transactionResponse()),
            hostedCheckoutUrl: self::HOSTED_CHECKOUT_URL,
        );

        $payload = self::eventPayload('evt_1', 'transaction.completed');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/without a webhook signing secret/');

        $adapter->parseCallback($payload, self::signedHeaders($payload));
    }

    public function testParseCallbackMatchesTheSignatureHeaderCaseInsensitively(): void
    {
        $payload = self::eventPayload('evt_1', 'transaction.completed');
        $timestamp = time();

        $event = $this->adapter()->parseCallback($payload, [
            'paddle-signature' => sprintf('ts=%d;h1=%s', $timestamp, HMAC::sign($timestamp . ':' . $payload, self::WEBHOOK_SECRET)),
        ]);

        self::assertSame('txn_test_123', $event->gatewayReference);
    }

    public function testParseCallbackAcceptsAnyOneOfSeveralSignaturesDuringSecretRotation(): void
    {
        $payload = self::eventPayload('evt_1', 'transaction.completed');
        $timestamp = time();

        $event = $this->adapter()->parseCallback($payload, [
            'Paddle-Signature' => sprintf(
                'ts=%d;h1=%s;h1=%s',
                $timestamp,
                str_repeat('0', 64),
                HMAC::sign($timestamp . ':' . $payload, self::WEBHOOK_SECRET)
            ),
        ]);

        self::assertSame(TransactionStatus::Success, $event->status);
    }

    /**
     * A Paddle notification destination delivers every event type subscribed on it. Without
     * the guard these parse "successfully" into a CallbackEvent whose gatewayReference is a
     * subscription or customer id — the same defect a live `stripe listen` run found in the
     * sibling adapter.
     *
     * @return list<array{string, string}>
     */
    public static function nonTransactionEvents(): array
    {
        return [
            ['subscription.created', 'sub_01h8xce4x86pq'],
            ['adjustment.updated', 'adj_01h8xce4x86pq'],
            ['customer.created', 'ctm_01h8xce4x86pq'],
            ['price.updated', 'pri_01h8xce4x86pq'],
        ];
    }

    #[DataProvider('nonTransactionEvents')]
    public function testParseCallbackRefusesAnEventThatIsNotATransactionEvent(string $type, string $id): void
    {
        $payload = json_encode([
            'event_id' => 'evt_unrelated',
            'event_type' => $type,
            'data' => ['id' => $id, 'status' => 'active'],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/not a transaction event/');

        $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
    }

    public function testParseCallbackRefusesATransactionEventCarryingNoTransactionId(): void
    {
        $payload = self::eventPayload('evt_1', 'transaction.completed', ['id' => 'sub_01h8xce4x86pq']);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/where a txn_ transaction id was expected/');

        $this->adapter()->parseCallback($payload, self::signedHeaders($payload));
    }

    public function testParseCallbackMapsCancellationToCancelled(): void
    {
        $payload = self::eventPayload('evt_1', 'transaction.canceled', ['status' => 'canceled']);

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame(TransactionStatus::Cancelled, $event->status);
    }

    public function testParseCallbackMapsPaymentFailureToFailedWithItsReason(): void
    {
        $payload = self::eventPayload('evt_1', 'transaction.payment_failed', [
            'status' => 'past_due',
            'payments' => [['status' => 'error', 'error_code' => 'declined']],
        ]);

        $event = $this->adapter()->parseCallback($payload, self::signedHeaders($payload));

        self::assertSame(TransactionStatus::Failed, $event->status);
        self::assertSame('declined', $event->failureReason);
    }

    // ---------------------------------------------------------------------------------
    // refund — §9.6.6
    // ---------------------------------------------------------------------------------

    public function testRefundPostsAFullAdjustmentAgainstTheTransaction(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::adjustmentResponse());

        $result = $this->adapter($fake)->refund(new RefundRequest('txn_test_123'));

        self::assertSame(1, $fake->requestCount(), 'A full refund needs no line items resolving.');
        self::assertSame('adj_test_1', $result->gatewayRefundId);
        self::assertSame('txn_test_123', $result->reference);
        self::assertSame(2500, $result->amount->minorUnits);

        $request = $fake->lastRequest();
        self::assertNotNull($request);
        self::assertSame('https://api.paddle.com/adjustments', (string) $request->getUri());

        self::assertSame([
            'action' => 'refund',
            'transaction_id' => 'txn_test_123',
            'reason' => 'Requested by merchant.',
            'type' => 'full',
        ], $fake->decodedLastRequestBody());
    }

    public function testRefundPassesTheMerchantsOwnReasonThrough(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::adjustmentResponse());

        $result = $this->adapter($fake)->refund(new RefundRequest('txn_test_123', reason: 'Damaged in transit.'));

        self::assertSame('Damaged in transit.', $fake->decodedLastRequestBody()['reason']);
        self::assertSame('Damaged in transit.', $result->reason);
    }

    /**
     * Paddle reviews live refunds rather than settling them on the call, so the status is
     * whatever Paddle says it is — kept verbatim, because a refund's lifecycle is not a
     * transaction's and there is no honest mapping onto the four-case enum.
     */
    public function testRefundReportsPaddlesPendingApprovalStatusVerbatim(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::adjustmentResponse(['status' => 'pending_approval']));

        self::assertSame('pending_approval', $this->adapter($fake)->refund(new RefundRequest('txn_test_123'))->status);
    }

    public function testRefundResolvesLineItemsForAPartialRefund(): void
    {
        $fake = new FakeHttpClient(self::refundResponder([self::lineItem('txnitm_1', '2500')]));

        $this->adapter($fake)->refund(new RefundRequest('txn_test_123', new Money(1000, 'USD')));

        self::assertSame(3, $fake->requestCount(), 'The transaction and its prior refunds are both read first.');

        $body = $fake->decodedLastRequestBody();
        self::assertSame('partial', $body['type']);
        self::assertSame([['item_id' => 'txnitm_1', 'type' => 'partial', 'amount' => '1000']], $body['items']);
    }

    public function testRefundSpreadsAPartialRefundAcrossSeveralLineItems(): void
    {
        $fake = new FakeHttpClient(self::refundResponder([
            self::lineItem('txnitm_1', '1000'),
            self::lineItem('txnitm_2', '1500'),
        ]));

        $this->adapter($fake)->refund(new RefundRequest('txn_test_123', new Money(1800, 'USD')));

        self::assertSame([
            ['item_id' => 'txnitm_1', 'type' => 'partial', 'amount' => '1000'],
            ['item_id' => 'txnitm_2', 'type' => 'partial', 'amount' => '800'],
        ], $fake->decodedLastRequestBody()['items']);
    }

    /**
     * Allocating against each item's original total would ignore what has already come off
     * it, and Paddle would reject the second partial refund of a transaction.
     */
    public function testRefundSubtractsEarlierRefundsBeforeAllocating(): void
    {
        $fake = new FakeHttpClient(self::refundResponder(
            [self::lineItem('txnitm_1', '1000'), self::lineItem('txnitm_2', '1500')],
            [self::priorRefund('txnitm_1', '1000')]
        ));

        $this->adapter($fake)->refund(new RefundRequest('txn_test_123', new Money(500, 'USD')));

        self::assertSame(
            [['item_id' => 'txnitm_2', 'type' => 'partial', 'amount' => '500']],
            $fake->decodedLastRequestBody()['items']
        );
    }

    public function testRefundIgnoresARejectedEarlierRefund(): void
    {
        $fake = new FakeHttpClient(self::refundResponder(
            [self::lineItem('txnitm_1', '2500')],
            [self::priorRefund('txnitm_1', '2500', 'rejected')]
        ));

        $this->adapter($fake)->refund(new RefundRequest('txn_test_123', new Money(2500, 'USD')));

        self::assertSame(
            [['item_id' => 'txnitm_1', 'type' => 'partial', 'amount' => '2500']],
            $fake->decodedLastRequestBody()['items'],
            'A rejected refund never returned the money, so it is refundable again.'
        );
    }

    public function testRefundRefusesToExceedWhatIsLeftToRefund(): void
    {
        $fake = new FakeHttpClient(self::refundResponder(
            [self::lineItem('txnitm_1', '2500')],
            [self::priorRefund('txnitm_1', '2000')]
        ));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/exceed what is left to refund/');

        $this->adapter($fake)->refund(new RefundRequest('txn_test_123', new Money(1000, 'USD')));
    }

    /**
     * Paddle paginates /adjustments ten to a page. Reading only the first page would
     * under-count what has already come off the transaction, and the over-refund guard would
     * then permit the exact thing it exists to stop — so every page is read.
     */
    public function testRefundReadsEveryPageOfEarlierRefundsBeforeAllocating(): void
    {
        $fake = new FakeHttpClient(self::pagedRefundResponder());

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/exceed what is left to refund/');

        // 2500 taken, 2000 already refunded across two pages, so only 500 is left. Reading
        // page one alone would see 1000 refunded and wave this through.
        $this->adapter($fake)->refund(new RefundRequest('txn_test_123', new Money(600, 'USD')));
    }

    public function testRefundAsksForPaddlesLargestPageAndFollowsTheCursor(): void
    {
        $fake = new FakeHttpClient(self::pagedRefundResponder());

        try {
            $this->adapter($fake)->refund(new RefundRequest('txn_test_123', new Money(600, 'USD')));
        } catch (CheckoutException) {
            // The allocation refusal is asserted by the test above; this one is about the reads.
        }

        $adjustmentReads = array_values(array_filter(
            $fake->requests(),
            static fn ($request): bool => $request->getMethod() === 'GET'
                && str_contains((string) $request->getUri(), '/adjustments')
        ));

        self::assertCount(2, $adjustmentReads);
        self::assertStringContainsString('per_page=200', (string) $adjustmentReads[0]->getUri());
        self::assertStringContainsString('after=adj_page1', (string) $adjustmentReads[1]->getUri());
    }

    public function testRefundRefusesATransactionTakenInAnotherCurrency(): void
    {
        $fake = new FakeHttpClient(self::refundResponder([self::lineItem('txnitm_1', '2500')], [], 'EUR'));

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/which was taken in EUR/');

        $this->adapter($fake)->refund(new RefundRequest('txn_test_123', new Money(1000, 'USD')));
    }

    // ---------------------------------------------------------------------------------
    // createCheckout, catalogue mode — Q-015
    // ---------------------------------------------------------------------------------

    public function testCatalogueModeNamesThePriceAndSendsNothingElseAboutIt(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->catalogAdapter($fake)->createCheckout($this->checkoutRequest());

        self::assertSame(
            [['price_id' => self::CATALOG_PRICE_ID, 'quantity' => 1]],
            $fake->decodedLastRequestBody()['items']
        );
    }

    /**
     * Paddle silently converts a catalogue price into a currency_code that disagrees with it
     * rather than refusing one — confirmed live — so the field is omitted and the published
     * price is billed as published. See SpeaksPaddle::currencyParams().
     */
    public function testCatalogueModeOmitsCurrencyCode(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->catalogAdapter($fake)->createCheckout($this->checkoutRequest());

        self::assertArrayNotHasKey('currency_code', $fake->decodedLastRequestBody());
    }

    public function testInlineModeStillSendsCurrencyCode(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse());

        $this->adapter($fake)->createCheckout($this->checkoutRequest());

        self::assertSame('USD', $fake->decodedLastRequestBody()['currency_code']);
    }

    public function testCatalogueModeRefusesARequestThatAlsoCarriesLineItems(): void
    {
        $adapter = $this->catalogAdapter();

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/two answers to one question/');

        $adapter->createCheckout(new CheckoutRequest(
            reference: 'ORDER-1001',
            amount: new Money(2500, 'USD'),
            successUrl: 'https://shop.test/success',
            cancelUrl: 'https://shop.test/cancel',
            lineItems: [new LineItem('Pro plan', new Money(2500, 'USD'))],
        ));
    }

    public function testConstructorNamesTheProductPriceConfusionWhenGivenAProductId(): void
    {
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/is a Paddle product id, not a price id/');

        new PaddleCheckout(
            'pdl_sdbx_apikey_test',
            new FakeHttpClient(static fn (): Response => self::transactionResponse()),
            self::WEBHOOK_SECRET,
            catalogPriceId: 'pro_test_starter',
        );
    }

    // ---------------------------------------------------------------------------------
    // 1.7.1 — the inert request amount is no longer a fallback in catalogue mode
    // ---------------------------------------------------------------------------------

    public function testCatalogueModeRaisesRatherThanReportingTheInertRequestAmount(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse(['details' => []]));
        $adapter = $this->catalogAdapter($fake);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/carried no details\.totals\.grand_total/');

        $adapter->createCheckout($this->checkoutRequest());
    }

    public function testInlineModeStillFallsBackToTheRequestAmount(): void
    {
        $fake = new FakeHttpClient(static fn (): Response => self::transactionResponse(['details' => []]));

        $session = $this->adapter($fake)->createCheckout($this->checkoutRequest());

        self::assertSame(2500, $session->amount->minorUnits);
    }

    // ---------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------

    private function adapter(?FakeHttpClient $fake = null): PaddleCheckout
    {
        return new PaddleCheckout(
            'pdl_sdbx_apikey_test',
            $fake ?? new FakeHttpClient(static fn (): Response => self::transactionResponse()),
            self::WEBHOOK_SECRET,
            hostedCheckoutUrl: self::HOSTED_CHECKOUT_URL,
        );
    }

    private function pageAdapter(?FakeHttpClient $fake = null): PaddleCheckout
    {
        return new PaddleCheckout(
            'pdl_sdbx_apikey_test',
            $fake ?? new FakeHttpClient(static fn (): Response => self::transactionResponse()),
            self::WEBHOOK_SECRET,
            paymentPageUrl: self::PAYMENT_PAGE_URL,
        );
    }

    private function catalogAdapter(?FakeHttpClient $fake = null): PaddleCheckout
    {
        return new PaddleCheckout(
            'pdl_sdbx_apikey_test',
            $fake ?? new FakeHttpClient(static fn (): Response => self::transactionResponse()),
            self::WEBHOOK_SECRET,
            hostedCheckoutUrl: self::HOSTED_CHECKOUT_URL,
            catalogPriceId: self::CATALOG_PRICE_ID,
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

    /**
     * Every Paddle response wraps its entity in a `data` envelope beside a `meta` block.
     *
     * @param array<string, mixed> $overrides
     */
    private static function transactionResponse(array $overrides = []): Response
    {
        return self::jsonResponse([
            'data' => array_merge([
                'id' => 'txn_test_123',
                'status' => 'draft',
                'currency_code' => 'USD',
                'collection_mode' => 'automatic',
                'checkout' => ['url' => 'https://shop.test/pay?_ptxn=txn_test_123'],
                'details' => ['totals' => ['grand_total' => '2500', 'currency_code' => 'USD']],
            ], $overrides),
            'meta' => ['request_id' => 'req_test'],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function adjustmentResponse(array $overrides = []): Response
    {
        return self::jsonResponse([
            'data' => array_merge([
                'id' => 'adj_test_1',
                'action' => 'refund',
                'status' => 'approved',
                'currency_code' => 'USD',
                'totals' => ['total' => '2500'],
            ], $overrides),
            'meta' => ['request_id' => 'req_test'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function lineItem(string $id, string $total): array
    {
        return ['id' => $id, 'totals' => ['total' => $total]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function priorRefund(string $itemId, string $amount, string $status = 'approved'): array
    {
        return [
            'id' => 'adj_earlier',
            'action' => 'refund',
            'status' => $status,
            'items' => [['item_id' => $itemId, 'amount' => $amount]],
        ];
    }

    /**
     * A partial refund reads the transaction's line items and its prior adjustments before
     * it can say which items the refund comes off, so three calls happen in one refund().
     *
     * @param list<array<string, mixed>> $lineItems
     * @param list<array<string, mixed>> $adjustments
     */
    private static function refundResponder(array $lineItems, array $adjustments = [], string $currency = 'USD'): Closure
    {
        return static function (RequestInterface $request) use ($lineItems, $adjustments, $currency): Response {
            $uri = (string) $request->getUri();

            if ($request->getMethod() !== 'GET') {
                return self::adjustmentResponse();
            }

            if (str_contains($uri, '/transactions/')) {
                return self::transactionResponse([
                    'currency_code' => $currency,
                    'details' => ['line_items' => $lineItems, 'totals' => ['grand_total' => '2500']],
                ]);
            }

            return self::jsonResponse(['data' => $adjustments, 'meta' => ['request_id' => 'req_test']]);
        };
    }

    /**
     * One line item of 2500, with 2000 already refunded across two pages of adjustments.
     */
    private static function pagedRefundResponder(): Closure
    {
        return static function (RequestInterface $request): Response {
            $uri = (string) $request->getUri();

            if ($request->getMethod() !== 'GET') {
                return self::adjustmentResponse();
            }

            if (str_contains($uri, '/transactions/')) {
                return self::transactionResponse([
                    'details' => [
                        'line_items' => [self::lineItem('txnitm_1', '2500')],
                        'totals' => ['grand_total' => '2500'],
                    ],
                ]);
            }

            $onFirstPage = !str_contains($uri, 'after=');

            return self::jsonResponse([
                'data' => [array_merge(
                    self::priorRefund('txnitm_1', '1000'),
                    ['id' => $onFirstPage ? 'adj_page1' : 'adj_page2']
                )],
                'meta' => [
                    'request_id' => 'req_test',
                    'pagination' => ['per_page' => 200, 'has_more' => $onFirstPage],
                ],
            ]);
        };
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function eventPayload(string $eventId, string $type, array $overrides = []): string
    {
        return json_encode([
            'event_id' => $eventId,
            'event_type' => $type,
            'occurred_at' => '2026-08-30T09:00:00.000000Z',
            'data' => array_merge([
                'id' => 'txn_test_123',
                'status' => 'completed',
                'currency_code' => 'USD',
                'details' => ['totals' => ['grand_total' => '2500']],
                'custom_data' => ['reference' => 'ORDER-1001'],
            ], $overrides),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private static function signedHeaders(string $payload, ?int $timestamp = null, string $secret = self::WEBHOOK_SECRET): array
    {
        $timestamp ??= time();

        return [
            'Paddle-Signature' => sprintf('ts=%d;h1=%s', $timestamp, HMAC::sign($timestamp . ':' . $payload, $secret)),
        ];
    }
}
