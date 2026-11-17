<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\CheckoutAdapters;

use Monad\Clarity\Services\Checkout;
use Monad\Clarity\Services\Checkout\BillingCycle;
use Monad\Clarity\Services\Checkout\BillingInterval;
use Monad\Clarity\Services\Checkout\CallbackEvent;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\CheckoutSession;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Services\Checkout\RefundResult;
use Monad\Clarity\Services\Checkout\TransactionSnapshot;
use Monad\Clarity\Services\Checkout\TransactionStatus;
use Monad\Clarity\Services\HttpClient;

/**
 * Stripe adapter for **recurring** payments — subscriptions started through Checkout Sessions
 * in `subscription` mode, then read and changed through `/subscriptions`.
 *
 * It sits beside StripeCheckout exactly as PaddleSubscription sits beside PaddleCheckout: one
 * gateway, two genuinely different flows, one class each. Everything the two Stripe adapters
 * share lives in the SpeaksStripe trait, so the signed-callback scheme exists once.
 *
 * **A subscription is born from a checkout session, not created by an API call.**
 * createCheckout() sends a `subscription`-mode session — for a catalogue `price_...` this
 * adapter was constructed with, or an inline price carrying the billing cycle it was
 * constructed with — and Stripe creates the subscription when the customer completes it. So
 * the reference createCheckout() returns is a `cs_`, and the `sub_` every subscription
 * operation acts on does not exist yet. Two routes lead from one to the other, and an
 * application should handle both because either delivery can be dropped:
 *
 *   subscriptionReferenceOf($event)   reads `subscription` off a completed session
 *   parseSubscriptionCallback($body)  reads it off the `customer.subscription.created`
 *                                     delivery
 *
 * Unlike Paddle's, the first route is synchronous: a completed session already carries its
 * `sub_`. It still returns null for a session that is merely open.
 *
 * The four inherited Checkout methods stay strictly transaction-scoped, and that is not a
 * limitation: a subscription's money movements are checkout sessions and invoices.
 * retrieveStatus() re-queries either, refund() refunds the PaymentIntent behind either, and
 * parseCallback() accepts `checkout.session.*` and the `invoice.*` events of a subscription.
 * A renewal arrives as an invoice, so an application routes on the reference's prefix: `cs_`
 * goes to the ledger, `in_` is handled by the application (ReleaseNotes_1.11.0.md §2.7).
 *
 * One mapping deliberately differs from StripeCheckout: **an unpaid invoice is Pending, never
 * Failed**, because a failed renewal is dunning, not the end. See mapInvoiceStatus().
 *
 * Stripe takes idempotency keys on every write, so createCheckout() and refund() are safe to
 * retry.
 *
 * @package Monad\Clarity\Services\CheckoutAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class StripeSubscription extends Checkout
{
    use SpeaksStripe;

    /**
     * Pinned apart from StripeCheckout's: this is the earliest version with a real `/pause`
     * endpoint, and one adapter moving must not move the other (ReleaseNotes_1.11.0.md §2.1).
     */
    private const API_VERSION = '2026-09-30.endive';
    private const GATEWAY = 'stripe_subscription';

    /**
     * One adapter instance means one plan's terms, in both of its two modes — a merchant with
     * monthly and annual tiers constructs two of these. That is adapter configuration rather
     * than request data because the inherited createCheckout() signature is fixed and
     * CheckoutRequest is frozen (`ReleaseNotes_1.4.0.md` §2.4).
     *
     * @param string $apiKey Stripe secret key (`sk_...`).
     * @param BillingCycle|null $billingCycle How often this adapter's subscriptions charge,
     *        for an inline plan. **Pass null when passing $catalogPriceId** — a catalogue price
     *        states its own cycle, and a second answer here could only contradict it. Exactly
     *        one of the two is required; prefer forCatalogPrice() to passing nulls positionally.
     * @param BillingCycle|null $trialPeriod A free period before the first charge. Stripe
     *        takes whole days, so only `Day` (two or more) and `Week` are accepted; `Month` and
     *        `Year` are refused rather than silently turned into 30 or 365 days. Allowed in
     *        catalogue mode too, because a Stripe price carries no trial of its own.
     * @param string $webhookSecret Signing secret for this endpoint (`whsec_...`). Empty
     *        disables callback parsing with an explicit error rather than silently accepting
     *        unverified callbacks.
     * @param string|null $catalogPriceId A recurring `price_...` the merchant maintains in
     *        Stripe, which switches this adapter into catalogue mode. The price states the
     *        amount, the currency and the billing cycle, so none of those is passed here.
     * @throws CheckoutException if neither or both of $billingCycle and $catalogPriceId are
     *         given, if $catalogPriceId is not a price id, or if $trialPeriod is a length
     *         Stripe cannot express.
     */
    public function __construct(
        string $apiKey,
        HttpClient $httpClient,
        private readonly ?BillingCycle $billingCycle,
        private readonly ?BillingCycle $trialPeriod = null,
        private readonly string $webhookSecret = '',
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
        private readonly ?string $catalogPriceId = null,
    ) {
        self::assertCatalogPriceId($catalogPriceId);
        self::assertOnePlanSource($billingCycle, $catalogPriceId);

        if ($trialPeriod !== null) {
            self::trialDays($trialPeriod);
        }

        parent::__construct($apiKey, $httpClient);
    }

    /**
     * A subscription on a plan the merchant publishes in the Stripe dashboard.
     *
     * The plan's terms are read from the catalogue rather than restated here, which is why this
     * constructor takes no billing cycle: naming a `price_...` is the whole description of what
     * is being sold. Prefer it to the primary constructor for catalogue mode — it is the same
     * object either way, but this one cannot be handed a cycle that contradicts the price.
     *
     * A trial period is accepted here, unlike on PaddleSubscription, because a Stripe price
     * carries no trial of its own and the checkout is where one is attached.
     */
    public static function forCatalogPrice(
        string $apiKey,
        HttpClient $httpClient,
        string $catalogPriceId,
        ?BillingCycle $trialPeriod = null,
        string $webhookSecret = '',
        string $baseUri = self::DEFAULT_BASE_URI,
    ): self {
        return new self(
            $apiKey,
            $httpClient,
            billingCycle: null,
            trialPeriod: $trialPeriod,
            webhookSecret: $webhookSecret,
            baseUri: $baseUri,
            catalogPriceId: $catalogPriceId,
        );
    }

    /**
     * Begin a subscription by creating the checkout session that will produce it.
     *
     * The returned session's gatewayReference is the **session** — a `cs_`, not a `sub_` —
     * and its paymentReference is null: a subscription-mode session has no PaymentIntent of
     * its own, the first invoice does. Use subscriptionReferenceOf() or
     * parseSubscriptionCallback() to learn the `sub_` once the customer completes the page.
     *
     * In catalogue mode CheckoutRequest::$amount is inert (`ReleaseNotes_1.7.0.md` §2.3): pass
     * `Money(0, $currency)` and read the real figure back off CheckoutSession::$amount.
     */
    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $params = [
            'mode' => 'subscription',
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => $request->reference,
            'line_items' => $this->catalogPriceId !== null
                ? [['price' => $this->catalogPriceId, 'quantity' => 1]]
                : $this->lineItemParams($request, [
                    'interval' => $this->billingCycle?->interval->value,
                    'interval_count' => $this->billingCycle?->frequency,
                ]),
        ];

        if ($request->customerEmail !== null) {
            $params['customer_email'] = $request->customerEmail;
        }

        if ($request->metadata !== []) {
            $params['metadata'] = $request->metadata;
        }

        // client_reference_id stays on the session and never reaches the subscription, so the
        // merchant reference is stamped onto the subscription itself. `flexible` billing mode
        // is what lets pause() and a later changePlan() act on it.
        $params['subscription_data']['metadata']['reference'] = $request->reference;
        $params['subscription_data']['billing_mode']['type'] = 'flexible';

        if ($this->trialPeriod !== null) {
            $params['subscription_data']['trial_period_days'] = self::trialDays($this->trialPeriod);
        }

        $session = $this->send(
            'POST',
            '/checkout/sessions',
            $params,
            $request->timeoutSeconds,
            $request->idempotencyKey()
        );

        return new CheckoutSession(
            gateway: self::GATEWAY,
            gatewayReference: $this->requireString($session, 'id'),
            redirectUrl: isset($session['url']) ? (string) $session['url'] : null,
            status: $this->mapSessionStatus($session),
            amount: $this->amountOf($session, $this->catalogPriceId === null ? $request->amount : null),
            paymentReference: null,
            raw: $session,
        );
    }

    /**
     * Re-query a payment: a `cs_` checkout session or an `in_` invoice, which is how a renewal
     * arrives. Anything else is refused by name — a `sub_` is a standing arrangement, not a
     * payment, and belongs to retrieveSubscription().
     */
    public function retrieveStatus(string $reference, int $timeoutSeconds = 30): TransactionSnapshot
    {
        if (str_starts_with($reference, 'cs_')) {
            // The invoice's payments are expanded so the PaymentIntent behind the first charge
            // is reported; a session carries only a bare invoice id.
            $session = $this->send(
                'GET',
                '/checkout/sessions/' . rawurlencode($reference),
                ['expand' => ['invoice.payments']],
                $timeoutSeconds
            );
            $invoice = $session['invoice'] ?? null;

            return new TransactionSnapshot(
                gateway: self::GATEWAY,
                gatewayReference: $this->requireString($session, 'id'),
                status: $this->mapSessionStatus($session),
                amount: $this->amountOf($session),
                paymentReference: is_array($invoice) ? $this->paymentIntentOfInvoice($invoice) : null,
                raw: $session,
            );
        }

        if (str_starts_with($reference, 'in_')) {
            $invoice = $this->send(
                'GET',
                '/invoices/' . rawurlencode($reference),
                ['expand' => ['payments']],
                $timeoutSeconds
            );
            $status = $this->mapInvoiceStatus($invoice);

            return new TransactionSnapshot(
                gateway: self::GATEWAY,
                gatewayReference: $this->requireString($invoice, 'id'),
                status: $status,
                amount: $this->amountOf($invoice),
                failureReason: $status === TransactionStatus::Failed ? 'Stripe marked this invoice uncollectible.' : null,
                paymentReference: $this->paymentIntentOfInvoice($invoice),
                raw: $invoice,
            );
        }

        throw new CheckoutException(sprintf(
            'StripeSubscription can re-query a checkout session (cs_...) or an invoice (in_...), not "%s". '
            . 'A subscription (sub_...) is read with retrieveSubscription().',
            $reference
        ));
    }

    /**
     * Verify a callback and normalise it. Accepts `checkout.session.*` events and the
     * `invoice.*` events of a **subscription** — a one-off invoice belongs to no subscription
     * and is refused. A renewal's reference is an `in_`; an application routes on the
     * reference's prefix, sending `cs_` to the ledger and handling `in_` itself.
     *
     * A renewal `invoice.payment_failed` is Pending, not Failed: Stripe is still retrying,
     * and `Failed` is terminal (`ReleaseNotes_1.4.0.md` §2.6). Subscription lifecycle events
     * are the other door, parseSubscriptionCallback().
     *
     * @param array<string, string> $headers
     * @throws CheckoutException if the signature is absent, malformed, stale, or does not
     *         verify; or if the payload is not an event this method understands.
     */
    public function parseCallback(string $rawBody, array $headers): CallbackEvent
    {
        // Verification comes first, before the body is decoded, inspected or branched on.
        $this->verifySignature($rawBody, $headers);

        /** @var array<string, mixed> $event */
        $event = json_decode($rawBody, associative: true) ?: [];

        // Signed does not mean well-formed: a field of the wrong type is refused here rather than
        // cast, because casting an array raises a warning, which the framework's error handler
        // turns into an ErrorException that escapes this method's CheckoutException contract.
        if (!is_string($event['id'] ?? null) || !is_string($event['type'] ?? null)) {
            throw new CheckoutException('Stripe webhook payload verified but carried no event id or type.');
        }

        $type = $event['type'];
        $object = $event['data']['object'] ?? [];

        if (!is_array($object)) {
            throw new CheckoutException(sprintf('Stripe webhook "%s" carried no event object.', $type));
        }

        // An endpoint receives every event type enabled on it, so product, charge and
        // customer.subscription events arrive beside the ones this method reads. Stripe stamps
        // every object with its own type, so the discriminator is already in the payload.
        $objectType = is_string($object['object'] ?? null) ? $object['object'] : 'unknown';

        if ($objectType === 'invoice') {
            return $this->invoiceEvent($event, $type, $object);
        }

        if ($objectType !== 'checkout.session') {
            throw new CheckoutException(sprintf(
                'Stripe webhook "%s" carries a "%s" object, not a checkout.session or a subscription invoice '
                . '— parseCallback() cannot interpret it. %s',
                $type,
                $objectType,
                $objectType === 'subscription'
                    ? 'Subscription lifecycle events go to parseSubscriptionCallback().'
                    : 'Endpoints receive every enabled event type; route only checkout.session.* and '
                        . 'invoice.* events here, or catch this and ignore the rest.'
            ));
        }

        $status = match ($type) {
            'checkout.session.async_payment_succeeded' => TransactionStatus::Success,
            'checkout.session.async_payment_failed' => TransactionStatus::Failed,
            'checkout.session.expired' => TransactionStatus::Cancelled,
            default => $this->mapSessionStatus($object),
        };

        return new CallbackEvent(
            gateway: self::GATEWAY,
            eventId: $event['id'],
            eventType: $type,
            gatewayReference: $this->requireString($object, 'id'),
            status: $status,
            failureReason: $status === TransactionStatus::Failed ? $this->failureReasonOf($object, $type) : null,
            // The webhook's `invoice` is a bare id, so the PaymentIntent is not knowable here.
            paymentReference: null,
            raw: $event,
        );
    }

    /**
     * The `sub_` a transaction belongs to, or null when it has none yet.
     *
     * From a session it is `subscription`, present once the customer has completed the page
     * and absent while it is open. From an invoice it is `parent.subscription_details.subscription`
     * on current API versions and a top-level `subscription` on older ones; both are read,
     * because a webhook endpoint pinned to an older version still delivers the old shape. Either
     * may be a bare id or an expanded object.
     */
    public function subscriptionReferenceOf(CallbackEvent|TransactionSnapshot $transaction): ?string
    {
        $source = $transaction instanceof CallbackEvent
            ? ($transaction->raw['data']['object'] ?? [])
            : $transaction->raw;

        if (!is_array($source)) {
            return null;
        }

        return ($source['object'] ?? null) === 'invoice'
            ? $this->invoiceSubscriptionOf($source)
            : $this->referenceOf($source['subscription'] ?? null);
    }

    /**
     * Refund all or part of a payment. Stripe refunds act on a PaymentIntent, so the reference
     * is resolved to one: a `pi_` is used as is, an `in_` is read for the PaymentIntent that
     * paid it, and a `cs_` is read through its invoice. A refund that cannot exist — a trial
     * that charged nothing, an invoice that is not paid — is refused in plain words and never
     * posted to Stripe.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $paymentIntent = $this->resolvePaymentIntent($request);
        $refund = $this->send(
            'POST',
            '/refunds',
            $this->refundParams($request, $paymentIntent),
            $request->timeoutSeconds,
            $request->idempotencyKey()
        );

        return new RefundResult(
            gateway: self::GATEWAY,
            gatewayRefundId: $this->requireString($refund, 'id'),
            reference: $paymentIntent,
            amount: new Money(
                (int) ($refund['amount'] ?? $request->amount?->minorUnits ?? 0),
                (string) ($refund['currency'] ?? $request->amount?->currency ?? 'USD')
            ),
            status: (string) ($refund['status'] ?? 'unknown'),
            reason: $request->reason,
            raw: $refund,
        );
    }

    protected function gatewayName(): string
    {
        return self::GATEWAY;
    }

    /**
     * A plan's terms come from exactly one place. Neither is a silent no-op that would send
     * Stripe a request with no price; both would be two answers to "how often does this
     * charge", and Stripe would honour the catalogue's.
     *
     * @throws CheckoutException
     */
    private static function assertOnePlanSource(?BillingCycle $billingCycle, ?string $catalogPriceId): void
    {
        if (($billingCycle === null) === ($catalogPriceId === null)) {
            throw new CheckoutException($billingCycle === null
                ? 'A StripeSubscription needs to know what it bills. Pass a $billingCycle to describe the '
                    . 'plan inline, or a $catalogPriceId (price_...) to bill a plan you publish in Stripe — '
                    . 'StripeSubscription::forCatalogPrice() is the readable way to do the latter.'
                : sprintf(
                    'This StripeSubscription was constructed with both a $billingCycle and the catalogue '
                        . 'price %s. The catalogue price already states how often it charges, so these are two '
                        . 'answers to one question and Stripe would honour the catalogue\'s. Pass one.',
                    $catalogPriceId
                ));
        }
    }

    /**
     * A checkout charges a price, not a product. The Stripe dashboard shows a product's id
     * beside its prices, and a `prod_` passed here would otherwise fail at the API with a
     * message about a line item, naming an id the merchant can see plainly exists.
     *
     * @throws CheckoutException if $catalogPriceId is present but is not a price id.
     */
    private static function assertCatalogPriceId(?string $catalogPriceId): void
    {
        if ($catalogPriceId === null || str_starts_with($catalogPriceId, 'price_')) {
            return;
        }

        throw new CheckoutException(str_starts_with($catalogPriceId, 'prod_')
            ? sprintf(
                '"%s" is a Stripe product id, not a price id. A product is the thing being sold and a price '
                . 'is what it costs on one billing cycle, so a checkout needs the price — the price_... '
                . 'shown beneath the product in Stripe > Product catalogue.',
                $catalogPriceId
            )
            : sprintf('A catalogue checkout needs a Stripe price id (price_...), got "%s".', $catalogPriceId));
    }

    /**
     * Stripe's `trial_period_days` is a whole number of days, and nothing else. A day is a
     * day, a week is seven; a month or a year has no fixed length, and converting one would
     * silently turn "one month" into thirty days, so those are refused. A one-day trial is
     * refused because Stripe refuses it ("The trial end date must be at least 2 days after…")
     * and failing at construction is kinder than failing at the first checkout.
     *
     * @throws CheckoutException
     */
    private static function trialDays(BillingCycle $trial): int
    {
        if ($trial->interval === BillingInterval::Month || $trial->interval === BillingInterval::Year) {
            throw new CheckoutException(sprintf(
                'Stripe\'s trial_period_days is a whole number of days, and a %s has no fixed length — '
                . 'converting it would silently turn "%s" into a count of days. Express the trial in days or '
                . 'weeks instead, for example new BillingCycle(BillingInterval::Day, 30).',
                $trial->interval->value,
                $trial->describe()
            ));
        }

        $days = $trial->interval === BillingInterval::Week ? 7 * $trial->frequency : $trial->frequency;

        if ($days < 2) {
            throw new CheckoutException(
                'Stripe requires a trial of at least 2 days — trial_period_days of 1 is refused with "The '
                . 'trial end date must be at least 2 days after the customer\'s test clock time". Pass a '
                . 'longer trial, or none.'
            );
        }

        return $days;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $invoice
     */
    private function invoiceEvent(array $event, string $type, array $invoice): CallbackEvent
    {
        if ($this->invoiceSubscriptionOf($invoice) === null) {
            throw new CheckoutException(sprintf(
                'Stripe webhook "%s" carries an invoice that belongs to no subscription — a one-off invoice '
                . 'is not something StripeSubscription charged, so parseCallback() will not interpret it.',
                $type
            ));
        }

        $status = $this->mapInvoiceStatus($invoice);

        return new CallbackEvent(
            gateway: self::GATEWAY,
            eventId: $event['id'],
            eventType: $type,
            gatewayReference: $this->requireString($invoice, 'id'),
            status: $status,
            failureReason: $status === TransactionStatus::Failed ? $type : null,
            paymentReference: $this->paymentIntentOfInvoice($invoice),
            raw: $event,
        );
    }

    /**
     * An invoice belongs to a subscription through `parent.subscription_details.subscription`
     * on current API versions, and through a top-level `subscription` on older ones. One live
     * payload carried both, another only the first.
     *
     * @param array<string, mixed> $invoice
     */
    private function invoiceSubscriptionOf(array $invoice): ?string
    {
        $parent = $invoice['parent'] ?? null;
        $details = is_array($parent) ? ($parent['subscription_details'] ?? null) : null;

        return $this->referenceOf(is_array($details) ? ($details['subscription'] ?? null) : null)
            ?? $this->referenceOf($invoice['subscription'] ?? null);
    }

    /**
     * The PaymentIntent that paid an invoice. Current API versions list it under
     * `payments.data[*].payment.payment_intent`, and only when `payments` is expanded; older
     * ones carry a top-level `payment_intent`. A trial's zero-amount invoice and an unexpanded
     * webhook carry neither, and null is the honest answer.
     *
     * @param array<string, mixed> $invoice
     */
    private function paymentIntentOfInvoice(array $invoice): ?string
    {
        $legacy = $this->referenceOf($invoice['payment_intent'] ?? null);

        if ($legacy !== null) {
            return $legacy;
        }

        $payments = $invoice['payments']['data'] ?? null;

        foreach (is_array($payments) ? $payments : [] as $entry) {
            $payment = is_array($entry) ? ($entry['payment'] ?? null) : null;

            if (is_array($payment) && ($payment['type'] ?? null) === 'payment_intent') {
                $reference = $this->referenceOf($payment['payment_intent'] ?? null);

                if ($reference !== null) {
                    return $reference;
                }
            }
        }

        return null;
    }

    /**
     * A renewal invoice's state, read as a payment's. **`open` is Pending even after a failed
     * attempt**: Stripe retries on its own schedule, and `Failed` is terminal, so marking a
     * dunning invoice there would lock a renewal that later recovers at `failed` — the
     * 1.4.0 §2.6 defect, asked of Stripe (`ReleaseNotes_1.11.0.md` §2.8). Only `uncollectible`
     * is Failed. An unrecognised state is Pending, the safe direction for a payment.
     *
     * @param array<string, mixed> $invoice
     */
    private function mapInvoiceStatus(array $invoice): TransactionStatus
    {
        return match (is_string($invoice['status'] ?? null) ? $invoice['status'] : null) {
            'paid' => TransactionStatus::Success,
            'uncollectible' => TransactionStatus::Failed,
            'void' => TransactionStatus::Cancelled,
            default => TransactionStatus::Pending,
        };
    }

    /**
     * A Checkout Session's state is two fields, not one: `status` covers the page's lifecycle
     * and `payment_status` the money. A trial session is `paid` at an amount of zero, which is
     * why `paid` is Success without a look at the amount.
     *
     * @param array<string, mixed> $session
     */
    private function mapSessionStatus(array $session): TransactionStatus
    {
        $paymentStatus = is_string($session['payment_status'] ?? null) ? $session['payment_status'] : null;

        if ($paymentStatus === 'paid' || $paymentStatus === 'no_payment_required') {
            return TransactionStatus::Success;
        }

        return ($session['status'] ?? null) === 'expired' ? TransactionStatus::Cancelled : TransactionStatus::Pending;
    }

    /**
     * Better the gateway's own event name than nothing: the failure_reason column exists so an
     * operator can tell why a payment failed without opening a dashboard.
     *
     * @param array<string, mixed> $object
     */
    private function failureReasonOf(array $object, string $eventType): string
    {
        $error = $object['last_payment_error'] ?? null;

        return is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : $eventType;
    }

    private function resolvePaymentIntent(RefundRequest $request): string
    {
        $reference = $request->reference;

        if (str_starts_with($reference, 'pi_')) {
            return $reference;
        }

        if (str_starts_with($reference, 'in_')) {
            $invoice = $this->send(
                'GET',
                '/invoices/' . rawurlencode($reference),
                ['expand' => ['payments']],
                $request->timeoutSeconds
            );

            if (($invoice['status'] ?? null) !== 'paid') {
                throw new CheckoutException(sprintf(
                    'Invoice %s is not paid (it is %s), so there is nothing to refund.',
                    $reference,
                    isset($invoice['status']) ? '"' . $invoice['status'] . '"' : 'in an unknown state'
                ));
            }

            return $this->paymentIntentOfInvoice($invoice) ?? throw $this->nothingChargedFor($reference);
        }

        if (str_starts_with($reference, 'cs_')) {
            $session = $this->send(
                'GET',
                '/checkout/sessions/' . rawurlencode($reference),
                ['expand' => ['invoice.payments']],
                $request->timeoutSeconds
            );
            $invoice = $session['invoice'] ?? null;

            return (is_array($invoice) ? $this->paymentIntentOfInvoice($invoice) : null)
                ?? throw $this->nothingChargedFor($reference);
        }

        throw new CheckoutException(sprintf(
            'StripeSubscription refunds a PaymentIntent (pi_...), an invoice (in_...) or a checkout session '
            . '(cs_...), not "%s".',
            $reference
        ));
    }

    private function nothingChargedFor(string $reference): CheckoutException
    {
        return new CheckoutException(sprintf(
            '%s %s has no payment to refund — nothing was charged. A trial starts at zero, and a session the '
            . 'customer never completed charged nothing either; the first real payment is the first renewal '
            . 'invoice.',
            str_starts_with($reference, 'cs_') ? 'Checkout session' : 'Invoice',
            $reference
        ));
    }
}
