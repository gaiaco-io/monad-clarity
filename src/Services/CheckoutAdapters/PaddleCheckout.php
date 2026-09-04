<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\CheckoutAdapters;

use Monad\Clarity\Services\Checkout;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\CheckoutSession;
use Monad\Clarity\Services\Checkout\TransactionStatus;
use Monad\Clarity\Services\HttpClient;

/**
 * Paddle Billing adapter — one-time payments through the Transactions API
 * (`/transactions`), refunds through Adjustments (`/adjustments`), and callbacks through
 * Paddle's signed notification scheme.
 *
 * Everything this adapter shares with PaddleSubscription — the signed-callback scheme, the
 * `data` envelope, cursor pagination, both ways of naming what is sold — a catalogue price
 * id or an inline non-catalog price — and the three transaction-scoped operations that read
 * the same endpoints either way — lives in the SpeaksPaddle trait. What is left here is what
 * is genuinely one-time-only: the two checkout modes, and a `past_due` that means a dead
 * payment rather than a retry in progress.
 *
 * Paddle states amounts as strings in the currency's lowest denomination — which is exactly
 * what Money already holds — so nothing here converts, and the zero-decimal currencies
 * (JPY, KRW, CLP) need no special case. Currency codes are upper case on both sides.
 *
 * Three things differ from a conventional PSP, and each one shows up in the code below:
 *
 * 1. **There is no gateway-hosted page by default.** Paddle renders its checkout through
 *    Paddle.js. Either a hosted checkout link does the hosting for you, or your own approved
 *    page does — pass exactly one of $hostedCheckoutUrl or $paymentPageUrl, and see
 *    createCheckout() for what each mode can honour.
 * 2. **Paddle supports no idempotency keys, on any endpoint.** CheckoutRequest's key is
 *    carried in custom_data for audit only. A replayed createCheckout() creates a second
 *    Paddle transaction; it is a draft, so no money moves, but it is not Stripe's behaviour
 *    and a caller must not retry blindly. refund() guards what it can — see there.
 * 3. **Refunds are asynchronous.** A live refund is created `pending_approval` and Paddle
 *    reviews it; sandbox approves automatically. RefundResult::$status carries Paddle's own
 *    word for it verbatim, which is what that field is for. The transaction itself is
 *    unaffected — a refund is a record, not a status.
 *
 * @package Monad\Clarity\Services\CheckoutAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class PaddleCheckout extends Checkout
{
    use SpeaksPaddle;

    private const GATEWAY = 'paddle_checkout';

    /**
     * @param string $apiKey Paddle API key (`pdl_live_apikey_...` / `pdl_sdbx_apikey_...`).
     * @param string $webhookSecret Signing secret for this notification destination
     *        (`pdl_ntfset_...`), issued per destination and distinct from the API key.
     *        Empty disables parseCallback() with an explicit error rather than silently
     *        accepting unverified callbacks.
     * @param string|null $hostedCheckoutUrl The whole link copied from Paddle > Checkout >
     *        Hosted checkout, Paddle hosting the page for you. Pass it verbatim — the id
     *        after `hsc_` carries a second segment, so this is configuration to hand
     *        through, never an id to assemble.
     * @param string|null $paymentPageUrl Your own page running Paddle.js, which Paddle opens
     *        a checkout on when it sees `_ptxn` in the query string. Its domain must be
     *        approved under Paddle > Checkout > Website approval, including in sandbox —
     *        Paddle validates the override against that list and rejects an unapproved
     *        domain with `transaction_checkout_url_domain_is_not_approved`, so an
     *        unapproved page fails at createCheckout() rather than at the redirect.
     * @param string $taxCategory Paddle tax category for the products this adapter bills.
     *        `standard` covers ordinary goods and services; a merchant selling ebooks, SaaS,
     *        or software has a category of its own and must pass it, because Paddle is the
     *        merchant of record and this is what decides how the sale is taxed. Unused in
     *        catalogue mode — a catalogue product carries its own.
     * @param string $baseUri `https://sandbox-api.paddle.com` for the sandbox, which is a
     *        wholly separate environment — separate keys, catalogue, notification
     *        destinations, and refunds approved automatically rather than by review. Nothing
     *        crosses between the two, so pointing an adapter at it is this argument and
     *        nothing else.
     * @param string|null $catalogPriceId A `pri_...` the merchant already maintains in Paddle,
     *        which switches this adapter into catalogue mode: the price states the amount, the
     *        currency and the tax category, and CheckoutRequest states only who is buying and
     *        where to send them afterwards. Leave it null and the sale is described inline from
     *        the request, needing no catalogue at all. Pass it as a named argument —
     *        `new PaddleCheckout($key, $http, $secret, paymentPageUrl: $url,
     *        catalogPriceId: 'pri_...')` — since the arguments before it all have defaults that
     *        catalogue mode does not disturb. See itemParams() for what each mode sends.
     */
    public function __construct(
        string $apiKey,
        HttpClient $httpClient,
        private readonly string $webhookSecret = '',
        private readonly ?string $hostedCheckoutUrl = null,
        private readonly ?string $paymentPageUrl = null,
        private readonly string $taxCategory = self::DEFAULT_TAX_CATEGORY,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
        private readonly ?string $catalogPriceId = null,
    ) {
        self::assertCatalogPriceId($catalogPriceId);

        parent::__construct($apiKey, $httpClient);
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $this->assertCheckoutMode();

        $params = [
            'items' => $this->itemParams($request),
            ...$this->currencyParams($request),
            'collection_mode' => 'automatic',
            'custom_data' => $this->customData($request),
        ];

        // Point this transaction at the merchant's own page rather than the account-wide
        // default payment link, so one Paddle account can serve several applications.
        if ($this->paymentPageUrl !== null) {
            $params['checkout'] = ['url' => $this->paymentPageUrl];
        }

        $transaction = $this->send('POST', '/transactions', $params, $request->timeoutSeconds);

        return new CheckoutSession(
            gateway: self::GATEWAY,
            gatewayReference: $this->requireString($transaction, 'id'),
            redirectUrl: $this->redirectUrlFor($transaction, $request),
            status: $this->mapTransactionStatus($transaction),
            amount: $this->amountOf($transaction, $this->amountFallback($request)),
            // Paddle refunds act on the transaction itself, so there is no second
            // identifier for the underlying payment — which is the only thing this field
            // exists to carry.
            paymentReference: null,
            raw: $transaction,
        );
    }

    protected function gatewayName(): string
    {
        return self::GATEWAY;
    }

    /**
     * Paddle's seven transaction states onto §9.6.5's four. `draft`, `ready` and `billed`
     * are all "money has not moved yet", and anything unrecognised is treated the same way:
     * pending never releases goods, so an unfamiliar state errs in the safe direction.
     *
     * @param array<string, mixed> $transaction
     */
    protected function mapTransactionStatus(array $transaction): TransactionStatus
    {
        return match (isset($transaction['status']) ? (string) $transaction['status'] : null) {
            'completed', 'paid' => TransactionStatus::Success,
            // Paddle spells it with one l; §9.6.5 spells it with two.
            'canceled' => TransactionStatus::Cancelled,
            'past_due' => TransactionStatus::Failed,
            default => TransactionStatus::Pending,
        };
    }

}
