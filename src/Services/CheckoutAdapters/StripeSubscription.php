<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\CheckoutAdapters;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Monad\Clarity\Services\Checkout;
use Monad\Clarity\Services\Checkout\BillingCycle;
use Monad\Clarity\Services\Checkout\BillingInterval;
use Monad\Clarity\Services\Checkout\CallbackEvent;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\CheckoutSession;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\PaymentFailureBehaviour;
use Monad\Clarity\Services\Checkout\ProrationBillingMode;
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Services\Checkout\RefundResult;
use Monad\Clarity\Services\Checkout\ResumeBilling;
use Monad\Clarity\Services\Checkout\ScheduledChange;
use Monad\Clarity\Services\Checkout\ScheduledChangeAction;
use Monad\Clarity\Services\Checkout\SubscriptionEffectiveFrom;
use Monad\Clarity\Services\Checkout\SubscriptionEvent;
use Monad\Clarity\Services\Checkout\SubscriptionItem;
use Monad\Clarity\Services\Checkout\SubscriptionSnapshot;
use Monad\Clarity\Services\Checkout\SubscriptionStatus;
use Monad\Clarity\Services\Checkout\TransactionSnapshot;
use Monad\Clarity\Services\Checkout\TransactionStatus;
use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\HttpClientException;
use Throwable;

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
     * `Money(0, $currency)` and read the real figure back off CheckoutSession::$amount. With a
     * trial, that figure is what the checkout charges today, which is zero — not the plan's
     * price; read that off the subscription's recurringAmount once it exists.
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
     *
     * **For an invoice, `amount` is the amount due** — what the card is charged — not the
     * invoice's total. The two differ whenever credit is applied, and a downgrade through
     * changePlan() raises an invoice whose total is negative and whose amount due is zero.
     * paymentReference is the PaymentIntent that paid, when one did; otherwise the attempt in
     * flight, or null.
     */
    public function retrieveStatus(string $reference, int $timeoutSeconds = 30): TransactionSnapshot
    {
        if (str_starts_with($reference, 'cs_')) {
            $session = $this->fetchSession($reference, $timeoutSeconds);
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
            $invoice = $this->fetchInvoice($reference, $timeoutSeconds);
            $status = $this->mapInvoiceStatus($invoice);

            return new TransactionSnapshot(
                gateway: self::GATEWAY,
                gatewayReference: $this->requireString($invoice, 'id'),
                status: $status,
                amount: $this->amountDueOf($invoice, $reference),
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
     * Read a subscription's current state straight from Stripe — the way to learn what became of
     * one whose callback never arrived. Pass the `sub_` that subscriptionReferenceOf() or
     * parseSubscriptionCallback() gave you; the `cs_` createCheckout() returned is refused
     * before any request is made.
     *
     * @throws CheckoutException if $subscriptionReference is not a subscription id, or Stripe's
     *         answer cannot be read as a subscription.
     */
    public function retrieveSubscription(string $subscriptionReference, int $timeoutSeconds = 30): SubscriptionSnapshot
    {
        $this->assertSubscriptionReference($subscriptionReference);

        return $this->snapshotFrom(
            $this->send('GET', '/subscriptions/' . rawurlencode($subscriptionReference), [], $timeoutSeconds)
        );
    }

    /**
     * Verify a `customer.subscription.*` callback and normalise it.
     *
     * The transaction parser and this one are separate doors, because a subscription event is
     * not a payment event and neither can be read as the other. An application routes on the
     * event type's prefix and calls the matching one.
     *
     * @param array<string, string> $headers
     * @throws CheckoutException if the signature is absent, malformed, stale, or does not
     *         verify; or if the payload is not a subscription event this adapter understands —
     *         including one that is signed but malformed. Nothing else escapes.
     */
    public function parseSubscriptionCallback(string $rawBody, array $headers): SubscriptionEvent
    {
        // Verification comes first, before the body is decoded, inspected or branched on.
        $this->verifySignature($rawBody, $headers);

        $event = json_decode($rawBody, associative: true);
        $event = is_array($event) ? $event : [];

        // Signed does not mean well-formed: every field is checked for its type before it is
        // used, because a cast of an array raises a warning the framework turns into an
        // ErrorException that would escape this method's CheckoutException contract.
        if (!is_string($event['id'] ?? null) || !is_string($event['type'] ?? null)) {
            throw new CheckoutException('Stripe webhook payload verified but carried no event id or type.');
        }

        $type = $event['type'];
        $object = $event['data']['object'] ?? null;

        if (!is_array($object)) {
            throw new CheckoutException(sprintf('Stripe webhook "%s" carried no event object.', $type));
        }

        // An endpoint receives every event type enabled on it, so invoice.* and checkout.session.*
        // arrive beside the customer.subscription.* ones. Without this guard they would parse
        // "successfully" into a SubscriptionEvent whose reference is an invoice or a session.
        $objectType = is_string($object['object'] ?? null) ? $object['object'] : 'unknown';

        if ($objectType !== 'subscription' || !str_starts_with($type, 'customer.subscription.')) {
            throw new CheckoutException(sprintf(
                'Stripe webhook "%s" carries a "%s" object, not a customer.subscription event — '
                . 'parseSubscriptionCallback() cannot interpret it. Endpoints receive every enabled event '
                . 'type; route invoice.* and checkout.session.* events to parseCallback() instead.',
                $type,
                $objectType
            ));
        }

        return new SubscriptionEvent(
            gateway: self::GATEWAY,
            eventId: $event['id'],
            eventType: $type,
            occurredAt: $this->occurredAt($event, $type),
            subscription: $this->snapshotFrom($object),
            raw: $event,
        );
    }

    /**
     * Cancel a subscription.
     *
     * **Cancelling immediately refunds nothing on Stripe.** Paddle prorates a refund for the
     * unused part of the period; Stripe does not. A refund is a separate refund() call that you
     * decide on. This adapter sends no `prorate` and no `invoice_now`, because a prorated credit
     * lands on the customer's balance, and a balance on a cancelled customer is money nobody
     * will see.
     *
     * **For the next billing period the returned snapshot will still say Active.** The
     * cancellation is scheduled for the date the paid-for period ends and the subscription stays
     * active until then, with a ScheduledChange describing what is coming. Withdraw it with
     * removeScheduledChange().
     *
     * $effectiveFrom is required and has no default. Gateways disagree about what their default
     * should be, which is exactly why an adapter should not pick.
     *
     * @throws CheckoutException if $subscriptionReference is not a sub_ id, or Stripe refuses.
     */
    public function cancel(
        string $subscriptionReference,
        SubscriptionEffectiveFrom $effectiveFrom,
        int $timeoutSeconds = 30,
    ): SubscriptionSnapshot {
        $this->assertSubscriptionReference($subscriptionReference);

        $path = '/subscriptions/' . rawurlencode($subscriptionReference);

        return $this->snapshotFrom(
            $effectiveFrom === SubscriptionEffectiveFrom::Immediately
                ? $this->send('DELETE', $path, [], $timeoutSeconds)
                : $this->send('POST', $path, ['cancel_at_period_end' => 'true'], $timeoutSeconds)
        );
    }

    /**
     * Withdraw a cancellation scheduled for the end of the period, leaving the subscription to
     * renew as before. It is the inverse of cancel() with NextBillingPeriod and sends exactly
     * the one field that call set.
     *
     * A cancellation scheduled outside this adapter with an explicit `cancel_at` date is
     * withdrawn by Stripe's own rules for that field, not by anything this method adds. A
     * subscription that has already ended cannot be revived, and Stripe refuses.
     *
     * @throws CheckoutException if $subscriptionReference is not a sub_ id, or Stripe refuses.
     */
    public function removeScheduledChange(string $subscriptionReference, int $timeoutSeconds = 30): SubscriptionSnapshot
    {
        $this->assertSubscriptionReference($subscriptionReference);

        return $this->snapshotFrom($this->send(
            'POST',
            '/subscriptions/' . rawurlencode($subscriptionReference),
            ['cancel_at_period_end' => 'false'],
            $timeoutSeconds
        ));
    }

    /**
     * Pause a subscription — billing stops and, by convention, so does service.
     *
     * **A Stripe pause takes effect at once, and carries no resume date.** Both facts were
     * established against the live test-mode API, not read from documentation. So the signature
     * stays PaddleSubscription's but three of its arguments are refused, each before any request
     * is made: NextBillingPeriod (a pause scheduled for later needs a subscription schedule,
     * which this adapter does not build), $resumeAt and $onResume (call resume() when the time
     * comes — there is nothing on Stripe's side to hold the date or the choice).
     *
     * **A pause credits the unused part of the period.** Under Stripe's default invoicing
     * behaviour the unused time becomes a pending proration, which lands on the invoice raised
     * at resume. Unlike Paddle, where a paused subscription keeps its period, a Stripe pause
     * settles it, and this adapter sends nothing to change that: the default is the documented
     * behaviour.
     *
     * **A paused subscription's period ends at the moment of the pause.** Stripe moves
     * current_period_end there, so the snapshot's currentPeriodEndsAt and accessEndsAt() are that
     * moment and nextBilledAt is null.
     *
     * **A subscription that is still trialing cannot be paused.** Stripe refuses it ("Can't
     * pause a subscription that is trialing…") and this adapter lets that message surface
     * rather than guess at a rule Stripe may relax.
     *
     * @throws CheckoutException if $subscriptionReference is not a sub_ id, if $effectiveFrom
     *         is NextBillingPeriod, if $resumeAt or $onResume is given, or if Stripe refuses.
     */
    public function pause(
        string $subscriptionReference,
        SubscriptionEffectiveFrom $effectiveFrom,
        ?DateTimeInterface $resumeAt = null,
        ?ResumeBilling $onResume = null,
        int $timeoutSeconds = 30,
    ): SubscriptionSnapshot {
        $this->assertSubscriptionReference($subscriptionReference);

        if ($effectiveFrom === SubscriptionEffectiveFrom::NextBillingPeriod) {
            throw new CheckoutException(
                'A Stripe subscription pauses immediately — there is no pause that waits for the next billing '
                . 'period. Scheduling one needs a subscription schedule, which this adapter does not create. '
                . 'Pass SubscriptionEffectiveFrom::Immediately, or pause when the period ends.'
            );
        }

        if ($resumeAt !== null) {
            throw new CheckoutException(
                'Stripe\'s pause carries no resume date, so $resumeAt cannot be honoured and is refused rather '
                . 'than silently dropped. Pause without it, and call resume() when the time comes.'
            );
        }

        if ($onResume !== null) {
            throw new CheckoutException(
                'Stripe\'s pause records no choice about how billing restarts, so $onResume cannot be honoured '
                . 'and is refused rather than silently dropped. Pass it to resume() instead, when the time comes.'
            );
        }

        return $this->snapshotFrom($this->send(
            'POST',
            '/subscriptions/' . rawurlencode($subscriptionReference) . '/pause',
            ['type' => 'subscription'],
            $timeoutSeconds
        ));
    }

    /**
     * Resume a subscription that is **already paused**.
     *
     * **This may charge the customer immediately, and this adapter collects that charge itself.**
     * Resuming with StartNewBillingPeriod answers from Stripe with the subscription still
     * `paused` and a new invoice that is `open` with `auto_advance` off — Stripe never collects it
     * on its own. Returning that as a success would leave a subscription the caller believes is
     * running and Stripe believes is paused, so when the resume raises an open invoice this
     * method pays it, then re-reads the subscription and returns what Stripe reports. That
     * invoice carries the proration credited at pause time. With ContinueExistingBillingPeriod,
     * a resume inside the original period raised no charge when this adapter was verified; a
     * pause that outlasts the period may still invoice on resume, and any invoice the resume
     * leaves open is paid here exactly the same way, whichever anchor was chosen.
     *
     * **This can return normally with the status still `Paused`.** When Stripe raised nothing to
     * pay, the `/resume` response is returned as it stands, and it is Stripe's state at that
     * moment. The returned status is authoritative: check it rather than assuming the resume
     * took effect.
     *
     * If the payment is refused, or never gets an answer, the exception says so and the
     * subscription **is still paused as far as this call knows**, with the invoice left open.
     * Confirm with retrieveSubscription(), then retry. If the payment went through but the
     * subscription could not be re-read, the exception says the invoice was paid and the resume
     * has very likely taken effect; confirm with retrieveSubscription() rather than resuming
     * again.
     *
     * NextBillingPeriod is not a legal choice here: a Stripe resume is immediate, and is refused
     * locally for the same reason pause() refuses it.
     *
     * @throws CheckoutException if $subscriptionReference is not a sub_ id, if $effectiveFrom
     *         is NextBillingPeriod, if Stripe refuses, if the resume invoice cannot be paid, or
     *         if the subscription cannot be re-read after it was.
     */
    public function resume(
        string $subscriptionReference,
        SubscriptionEffectiveFrom $effectiveFrom,
        ResumeBilling $onResume = ResumeBilling::StartNewBillingPeriod,
        int $timeoutSeconds = 30,
    ): SubscriptionSnapshot {
        $this->assertSubscriptionReference($subscriptionReference);

        if ($effectiveFrom === SubscriptionEffectiveFrom::NextBillingPeriod) {
            throw new CheckoutException(
                'A Stripe subscription resumes immediately — there is no resume that waits for the next billing '
                . 'period. Pass SubscriptionEffectiveFrom::Immediately.'
            );
        }

        $path = '/subscriptions/' . rawurlencode($subscriptionReference);
        $resumed = $this->send('POST', $path . '/resume', [
            'billing_cycle_anchor' => [
                'type' => $onResume === ResumeBilling::StartNewBillingPeriod ? 'now' : 'unchanged',
            ],
            'expand' => ['latest_invoice'],
        ], $timeoutSeconds);

        $invoiceId = $this->openInvoiceOf($resumed, $timeoutSeconds);

        if ($invoiceId === null) {
            return $this->snapshotFrom($resumed);
        }

        try {
            $this->send('POST', '/invoices/' . rawurlencode($invoiceId) . '/pay', [], $timeoutSeconds);
        } catch (CheckoutException|HttpClientException $e) {
            throw new CheckoutException(sprintf(
                'Subscription %1$s is still paused as far as this call knows: resuming raised invoice %2$s, '
                . 'and paying it failed, so the resume has not been seen to take effect. Confirm where things '
                . 'stand with retrieveSubscription(), then retry resume() once the customer\'s payment method '
                . 'is fixed, or resume with ResumeBilling::ContinueExistingBillingPeriod, which raised no charge '
                . 'inside the original period when this adapter was verified. Paying failed with: %3$s',
                $subscriptionReference,
                $invoiceId,
                $e->getMessage()
            ), previous: $e);
        }

        try {
            return $this->snapshotFrom($this->send('GET', $path, [], $timeoutSeconds));
        } catch (CheckoutException|HttpClientException $e) {
            throw new CheckoutException(sprintf(
                'Invoice %2$s was paid, so the resume of subscription %1$s has very likely taken effect, but '
                . 'reading the subscription back failed. Confirm with retrieveSubscription() rather than calling '
                . 'resume() again. Reading it failed with: %3$s',
                $subscriptionReference,
                $invoiceId,
                $e->getMessage()
            ), previous: $e);
        }
    }

    /**
     * Move a subscription onto a different set of items — a new plan, a new term, a new quantity.
     *
     * **This replaces the subscription's whole item set; it never adds.** Every item the
     * subscription bills for now is removed and `$items` becomes the complete list, so an add-on
     * left out of `$items` is gone. An empty list is refused, because it would strip every item
     * off the subscription, which is a cancellation by another name.
     *
     * **With ProratedImmediately this call charges the customer's card.** Stripe invoices the
     * proration at once, so a timeout or a dropped connection leaves you not knowing whether the
     * charge happened. Always pass `$idempotencyKey` on such a call, and reuse the same key to
     * retry it. What the retry does depends on how far the first attempt got:
     *
     * - The update never ran: the retry reuses the same products, re-activates them (an earlier
     *   attempt may have archived them) and runs the update now.
     * - The update ran and was declined: Stripe replays the decline.
     * - The update ran and changed the items: Stripe **refuses** the retry, because the same key
     *   now arrives with different parameters (the subscription's items are no longer the ones
     *   the first attempt replaced). That refusal means the change already happened. Confirm it
     *   with retrieveSubscription(), and never retry with a fresh key, whatever Stripe's error
     *   text suggests: a new key is a new request, and it could charge a second time.
     * - The first attempt met a server error (a 5xx): Stripe saves that answer against the key
     *   too, and replays it to the retry, so the retry tells you nothing new.
     *
     * So route **every** outcome of a keyed retry other than success to retrieveSubscription()
     * before deciding anything: the subscription's items, not the retry's error, say whether the
     * change happened.
     *
     * Without a key a blind retry can charge twice, so read the subscription with
     * retrieveSubscription() first. The key derives one for each product this call creates;
     * activating and archiving a product carry none, because each simply sets a state and a
     * replayed answer would not re-apply it.
     *
     * A subscription with more items than Stripe embeds in one response (it embeds a single page)
     * is refused rather than paged through, since an item left out would keep billing.
     *
     * Proration maps onto Stripe's `proration_behavior`: ProratedImmediately is `always_invoice`,
     * ProratedNextBillingPeriod is `create_prorations` and DoNotBill is `none`. The two Full*
     * modes are refused before any request is made: Stripe has no equivalent that does not also
     * reset the billing anchor, which moves the renewal date as a side effect nobody asked for.
     * PreventChange sends `payment_behavior=error_if_incomplete`, so a declined proration leaves
     * the subscription unchanged and throws; ApplyChange sends `allow_incomplete`, so the change
     * stands and the invoice is left for Stripe's retries.
     *
     * An inline item cannot name its product inline on an update, so a product is created for it
     * first, named for its line description, and **archived once the change has been made**,
     * whether the change succeeded or was refused. That leaves one inactive product in your
     * Stripe catalogue for each inline plan, exactly what Checkout itself leaves. If archiving a
     * product fails after the change succeeded, the change is still returned rather than thrown —
     * a throw would invite a retry that could charge again — and that product may remain active
     * in your catalogue until you archive it.
     *
     * @param list<SubscriptionItem> $items the complete list the subscription should bill for.
     * @throws CheckoutException if $subscriptionReference is not a sub_ id, if $items is empty,
     *         is not made of SubscriptionItems, mixes billing cycles or holds a catalogue id
     *         that is not a price, if the proration mode is FullImmediately or
     *         FullNextBillingPeriod, or if Stripe refuses.
     */
    public function changePlan(
        string $subscriptionReference,
        array $items,
        ProrationBillingMode $prorationBillingMode,
        PaymentFailureBehaviour $onPaymentFailure = PaymentFailureBehaviour::PreventChange,
        int $timeoutSeconds = 30,
        ?string $idempotencyKey = null,
    ): SubscriptionSnapshot {
        $this->assertSubscriptionReference($subscriptionReference);
        $this->assertChangeableItems($items);

        $proration = match ($prorationBillingMode) {
            ProrationBillingMode::ProratedImmediately => 'always_invoice',
            ProrationBillingMode::ProratedNextBillingPeriod => 'create_prorations',
            ProrationBillingMode::DoNotBill => 'none',
            ProrationBillingMode::FullImmediately,
            ProrationBillingMode::FullNextBillingPeriod => throw new CheckoutException(sprintf(
                'Stripe has no equivalent of ProrationBillingMode::%s that does not also reset the billing '
                . 'anchor, which would move the renewal date as a side effect. Use ProratedImmediately, '
                . 'ProratedNextBillingPeriod, or DoNotBill.',
                $prorationBillingMode->name
            )),
        };

        $path = '/subscriptions/' . rawurlencode($subscriptionReference);
        $existing = $this->itemIdsOf($subscriptionReference, $this->send('GET', $path, [], $timeoutSeconds));

        $params = [
            'items' => array_map(
                static fn (string $id): array => ['id' => $id, 'deleted' => 'true'],
                $existing
            ),
            'proration_behavior' => $proration,
            'payment_behavior' => $onPaymentFailure === PaymentFailureBehaviour::PreventChange
                ? 'error_if_incomplete'
                : 'allow_incomplete',
        ];

        $products = [];

        try {
            foreach ($items as $index => $item) {
                if ($item->isCatalogPrice()) {
                    $params['items'][] = ['price' => $item->priceId, 'quantity' => $item->quantity];

                    continue;
                }

                $products[$index] = $this->requireString($this->send(
                    'POST',
                    '/products',
                    ['name' => $item->lineItem->description],
                    $timeoutSeconds,
                    $idempotencyKey === null ? null : "{$idempotencyKey}:product:{$index}"
                ), 'id');

                if ($idempotencyKey !== null) {
                    // A retry replays the product's creation, and an earlier attempt may have archived
                    // it since; Stripe refuses an inactive product on a subscription update.
                    $this->send(
                        'POST',
                        '/products/' . rawurlencode($products[$index]),
                        ['active' => 'true'],
                        $timeoutSeconds
                    );
                }

                $params['items'][] = [
                    'price_data' => [
                        'currency' => strtolower($item->lineItem->unitPrice->currency),
                        'unit_amount' => $item->lineItem->unitPrice->minorUnits,
                        'product' => $products[$index],
                        'recurring' => [
                            'interval' => $item->billingCycle->interval->value,
                            'interval_count' => $item->billingCycle->frequency,
                        ],
                    ],
                    'quantity' => $item->quantity,
                ];
            }

            return $this->snapshotFrom($this->send('POST', $path, $params, $timeoutSeconds, $idempotencyKey));
        } finally {
            $this->archiveProducts($products, $timeoutSeconds);
        }
    }

    /**
     * Refund all or part of a payment. Stripe refunds act on a PaymentIntent, so the reference
     * is resolved to one: a `pi_` is used as is, an `in_` is read for the PaymentIntent that
     * paid it, and a `cs_` is read through its invoice. Either invoice must be `paid`, and the
     * PaymentIntent chosen is one whose own payment status is `paid`, never an earlier attempt
     * that was declined. A refund that cannot exist — an invoice that is not paid, including a
     * first payment still processing; one paid without a PaymentIntent, such as a zero-total
     * trial; a session the customer never completed — is refused in plain words and never
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
     * refused because Stripe refused one when this adapter was verified, and failing at
     * construction is kinder than failing at the first checkout. Whether Stripe refuses it in
     * every setting is a release-gate question (ReleaseNotes_1.11.0.md §5.2); the guard stands
     * until it is answered.
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
                'A trial must be at least 2 days here: Stripe refused a one-day trial when this adapter was '
                . 'verified, so this adapter will not send one. Pass a longer trial, or none.'
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
     * The PaymentIntent behind an invoice. Current API versions list every attempt under
     * `payments.data[*]`, and only when `payments` is expanded; each entry carries its own
     * `status`, so a declined attempt and the retry that paid sit side by side. **The paid one
     * is chosen.** Failing that, the first attempt listed is reported — a payment still
     * processing is the best answer to "which payment is this" — unless $paidOnly, which a
     * refund passes so that it is never posted against a payment that did not pay.
     *
     * Older API versions carry a top-level `payment_intent` instead, read only when no payments
     * list is present at all. A trial's zero-amount invoice lists none and an unexpanded webhook
     * carries neither, and null is the honest answer.
     *
     * @param array<string, mixed> $invoice
     */
    private function paymentIntentOfInvoice(array $invoice, bool $paidOnly = false): ?string
    {
        $payments = $invoice['payments']['data'] ?? null;

        if (!is_array($payments)) {
            return $this->referenceOf($invoice['payment_intent'] ?? null);
        }

        $attempt = null;

        foreach ($payments as $entry) {
            $payment = is_array($entry) ? ($entry['payment'] ?? null) : null;

            if (!is_array($payment) || ($payment['type'] ?? null) !== 'payment_intent') {
                continue;
            }

            $reference = $this->referenceOf($payment['payment_intent'] ?? null);

            if ($reference !== null && ($entry['status'] ?? null) === 'paid') {
                return $reference;
            }

            $attempt ??= $reference;
        }

        return $paidOnly ? null : $attempt;
    }

    /**
     * The PaymentIntent a refund of $reference is posted against, which must be one that paid.
     * The invoice is refused unless Stripe reports it `paid` — an asynchronous first payment
     * leaves it `open` with a PaymentIntent that has collected nothing yet — and then only a
     * payment whose own status is `paid` is chosen.
     *
     * @param array<string, mixed> $invoice
     * @throws CheckoutException naming $reference, before anything is posted.
     */
    private function paidPaymentIntentOf(array $invoice, string $reference): string
    {
        $subject = str_starts_with($reference, 'cs_')
            ? sprintf('The invoice behind checkout session %s', $reference)
            : sprintf('Invoice %s', $reference);
        $status = $invoice['status'] ?? null;

        if ($status !== 'paid') {
            throw new CheckoutException(sprintf(
                '%s is not paid (it is %s), so there is nothing to refund.',
                $subject,
                is_string($status) ? '"' . $status . '"' : 'in an unknown state'
            ));
        }

        return $this->paymentIntentOfInvoice($invoice, paidOnly: true) ?? throw new CheckoutException(sprintf(
            '%s was paid without a Stripe PaymentIntent — a zero-total trial, customer credit, or marked paid '
            . 'out of band — so there is no payment here to refund.',
            $subject
        ));
    }

    /**
     * A checkout session with its first invoice's payments expanded, so the PaymentIntent behind
     * the first charge can be read; unexpanded, a session carries only a bare invoice id.
     *
     * @return array<string, mixed>
     */
    private function fetchSession(string $reference, int $timeoutSeconds): array
    {
        return $this->send(
            'GET',
            '/checkout/sessions/' . rawurlencode($reference),
            ['expand' => ['invoice.payments']],
            $timeoutSeconds
        );
    }

    /**
     * An invoice with its payments expanded: at the pinned version that is the only place its
     * PaymentIntent appears.
     *
     * @return array<string, mixed>
     */
    private function fetchInvoice(string $reference, int $timeoutSeconds): array
    {
        return $this->send('GET', '/invoices/' . rawurlencode($reference), ['expand' => ['payments']], $timeoutSeconds);
    }

    /**
     * An invoice's amount, read as what the card is charged: `amount_due`, which Stripe never
     * reports below zero. The `total` is not used, because credit makes the two differ and a
     * downgrade's credit invoice has a negative total, which no Money can hold. Nothing is cast,
     * and the currency is checked here so that Money cannot throw anything but this.
     *
     * @param array<string, mixed> $invoice
     * @throws CheckoutException if `amount_due` is not a non-negative integer or `currency` is
     *         not a three-letter code.
     */
    private function amountDueOf(array $invoice, string $reference): Money
    {
        $due = $invoice['amount_due'] ?? null;
        $currency = $invoice['currency'] ?? null;

        if (!is_int($due) || $due < 0 || !is_string($currency) || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
            throw new CheckoutException(sprintf(
                'Stripe invoice %s carried no amount_due and currency this adapter can read — amount_due must be '
                . 'a whole number of minor units, zero or more — so its amount cannot be reported.',
                $reference
            ));
        }

        return new Money($due, $currency);
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
     * The id of the invoice a resume raised, when it is `open` and so waiting to be paid; null
     * when there is none or it is in any other state. The resume call expands `latest_invoice`,
     * so its status is normally in hand; a bare id means the expansion did not happen and the
     * invoice is read to learn what it is. Nothing is cast: an id that is not a string reads as
     * no invoice.
     *
     * @param array<string, mixed> $resumed
     */
    private function openInvoiceOf(array $resumed, int $timeoutSeconds): ?string
    {
        $invoice = $resumed['latest_invoice'] ?? null;

        if (is_string($invoice) && $invoice !== '') {
            $invoice = $this->send('GET', '/invoices/' . rawurlencode($invoice), [], $timeoutSeconds);
        }

        if (!is_array($invoice) || ($invoice['status'] ?? null) !== 'open') {
            return null;
        }

        return $this->requireString($invoice, 'id');
    }

    /**
     * @param array<mixed> $items
     * @throws CheckoutException if the list is empty, holds a non-item, mixes cycles, or names a
     *         catalogue entry that is not a price.
     */
    private function assertChangeableItems(array $items): void
    {
        if ($items === []) {
            throw new CheckoutException(
                'A plan change replaces a subscription\'s whole item set rather than adding to it, so an empty '
                . 'list would strip every item off it — a cancellation by another name. Call cancel() if that '
                . 'is what you mean.'
            );
        }

        $cycle = null;

        foreach ($items as $index => $item) {
            if (!$item instanceof SubscriptionItem) {
                throw new CheckoutException(sprintf(
                    'Item [%s] is %s, not a SubscriptionItem. Build each with SubscriptionItem::catalogPrice() '
                    . 'or SubscriptionItem::inline().',
                    $index,
                    get_debug_type($item)
                ));
            }

            if ($item->isCatalogPrice() && !str_starts_with($item->priceId, 'price_')) {
                throw new CheckoutException(str_starts_with($item->priceId, 'prod_')
                    ? sprintf(
                        '"%s" is a Stripe product id, not a price id. A product is the thing being sold and a '
                        . 'price is what it costs on one billing cycle, so a plan change needs the price — the '
                        . 'price_... shown beneath the product in Stripe > Product catalogue.',
                        $item->priceId
                    )
                    : sprintf('A catalogue item needs a Stripe price id (price_...), got "%s".', $item->priceId));
            }

            if ($item->billingCycle === null) {
                continue;
            }

            if ($cycle !== null && !$cycle->equals($item->billingCycle)) {
                throw new CheckoutException(sprintf(
                    'Every item on one subscription must bill on the same cycle, but this change mixes %s with '
                    . '%s. Move the add-ons onto the new term alongside the plan.',
                    $cycle->describe(),
                    $item->billingCycle->describe()
                ));
            }

            $cycle = $item->billingCycle;
        }
    }

    /**
     * @param array<string, mixed> $subscription
     * @return list<string>
     * @throws CheckoutException if the subscription's items are not a list of objects with ids.
     */
    private function itemIdsOf(string $reference, array $subscription): array
    {
        if (($subscription['items']['has_more'] ?? false) === true) {
            throw new CheckoutException(sprintf(
                'Subscription %s has more items than Stripe returned in one page, so replacing them all cannot '
                . 'be done safely here: any item left out would keep billing.',
                $reference
            ));
        }

        $data = $subscription['items']['data'] ?? null;

        if (!is_array($subscription['items'] ?? null) || !is_array($data) || !array_is_list($data)) {
            throw new CheckoutException('Stripe response was missing the subscription\'s items list.');
        }

        return array_map(function (mixed $item): string {
            if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === '') {
                throw new CheckoutException('Stripe response held an entry in the subscription\'s items without an id.');
            }

            return $item['id'];
        }, $data);
    }

    /**
     * Archive the products a plan change created. This runs whatever became of the change, so it
     * must never throw: an archive failure may not hide the change's own error, and a change that
     * succeeded is not undone, or retried into a second charge, by housekeeping.
     *
     * @param array<int, string> $products product id by item index
     */
    private function archiveProducts(array $products, int $timeoutSeconds): void
    {
        foreach ($products as $index => $productId) {
            try {
                $this->send(
                    'POST',
                    '/products/' . rawurlencode($productId),
                    ['active' => 'false'],
                    $timeoutSeconds
                );
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * @throws CheckoutException if $reference is not a Stripe subscription id.
     */
    private function assertSubscriptionReference(string $reference): void
    {
        if (str_starts_with($reference, 'sub_')) {
            return;
        }

        throw new CheckoutException(str_starts_with($reference, 'cs_')
            ? sprintf(
                '"%s" is a checkout session id, not a subscription id. A Stripe subscription is born when the '
                . 'customer completes the session and gets its own sub_ identifier then — read it with '
                . 'subscriptionReferenceOf() once the session has completed, or off the '
                . 'customer.subscription.created callback.',
                $reference
            )
            : sprintf(
                'A subscription operation needs a Stripe subscription id (sub_...), got "%s".',
                $reference === '' ? 'an empty string' : $reference
            ));
    }

    /**
     * Stripe stamps every event with `created`, in unix seconds. It is the only clock a callback
     * carries, and an application orders deliveries by it, so a missing or non-integer value is
     * refused rather than replaced with the time of arrival.
     *
     * @param array<string, mixed> $event
     */
    private function occurredAt(array $event, string $type): DateTimeImmutable
    {
        $created = $event['created'] ?? null;

        if (!is_int($created)) {
            throw new CheckoutException(sprintf(
                'Stripe webhook "%s" verified but carried no integer `created` timestamp to date the event by.',
                $type
            ));
        }

        return $this->utc($created);
    }

    private function utc(int $unixSeconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimestamp($unixSeconds);
    }

    /**
     * A Stripe subscription, normalised. Everything Stripe reports that this object cannot
     * represent is refused as a CheckoutException; nothing malformed is cast.
     *
     * @param array<string, mixed> $subscription
     * @throws CheckoutException
     */
    private function snapshotFrom(array $subscription): SubscriptionSnapshot
    {
        $id = $this->requireString($subscription, 'id');
        $status = $this->mapSubscriptionStatus($subscription);
        $item = $this->firstItemOf($subscription);

        // Stripe moved the billing period from the subscription onto its items in the 2025-03-31
        // (basil) API version. The pinned version carries only the items'; an endpoint pinned to
        // an older one still delivers the subscription's, so both are read, items first.
        $periodStart = $this->unixOf($item['current_period_start'] ?? null)
            ?? $this->unixOf($subscription['current_period_start'] ?? null);
        $periodEnd = $this->unixOf($item['current_period_end'] ?? null)
            ?? $this->unixOf($subscription['current_period_end'] ?? null);
        $scheduledChange = $this->scheduledCancellationOf($subscription, $id, $status, $periodEnd);
        $metadata = $subscription['metadata'] ?? null;
        $reference = is_array($metadata) && is_string($metadata['reference'] ?? null) && $metadata['reference'] !== ''
            ? $metadata['reference']
            : null;

        return new SubscriptionSnapshot(
            gateway: self::GATEWAY,
            gatewayReference: $id,
            status: $status,
            scheduledChange: $scheduledChange,
            reference: $reference,
            transactionReference: null,
            customerReference: $this->referenceOf($subscription['customer'] ?? null),
            recurringAmount: $this->recurringAmountOf($subscription, $id),
            billingCycle: $this->billingCycleOf($item),
            nextBilledAt: ($status === SubscriptionStatus::Active || $status === SubscriptionStatus::Trialing)
                && $scheduledChange === null ? $periodEnd : null,
            currentPeriodStartsAt: $periodStart,
            currentPeriodEndsAt: $periodEnd,
            raw: $subscription,
        );
    }

    /**
     * Stripe's eight statuses folded into five. `incomplete` and `unpaid` are PastDue because
     * both are a subscription whose money has not arrived and may still, and `Cancelled` is
     * terminal. An unrecognised status is refused by name rather than guessed at
     * (`ReleaseNotes_1.4.0.md` §2.7): silently mapping a new state to Active would keep serving
     * a customer Stripe no longer considers one.
     *
     * @param array<string, mixed> $subscription
     */
    private function mapSubscriptionStatus(array $subscription): SubscriptionStatus
    {
        $status = $subscription['status'] ?? null;

        return match ($status) {
            'active' => SubscriptionStatus::Active,
            'trialing' => SubscriptionStatus::Trialing,
            'past_due', 'incomplete', 'unpaid' => SubscriptionStatus::PastDue,
            'paused' => SubscriptionStatus::Paused,
            'canceled', 'incomplete_expired' => SubscriptionStatus::Cancelled,
            default => throw new CheckoutException(sprintf(
                'Stripe subscription %s is in status "%s", which StripeSubscription does not recognise. '
                . 'Refusing to guess what a state it has never seen means for the customer\'s access.',
                is_string($subscription['id'] ?? null) ? $subscription['id'] : '(unidentified)',
                is_string($status) ? $status : 'a non-string value'
            )),
        };
    }

    /**
     * A cancellation Stripe has scheduled but not yet performed — `cancel_at_period_end`, or a
     * `cancel_at` date. The subscription stays in its current status until then, which is why
     * it is a ScheduledChange and not a status. A cancelled subscription schedules nothing.
     *
     * @param array<string, mixed> $subscription
     */
    private function scheduledCancellationOf(
        array $subscription,
        string $id,
        SubscriptionStatus $status,
        ?DateTimeImmutable $periodEnd,
    ): ?ScheduledChange {
        $cancelAt = $subscription['cancel_at'] ?? null;

        if ($cancelAt !== null && !is_int($cancelAt)) {
            throw new CheckoutException(sprintf(
                'Stripe subscription %s carried a `cancel_at` that is not a unix timestamp.',
                $id
            ));
        }

        if ($status === SubscriptionStatus::Cancelled
            || (($subscription['cancel_at_period_end'] ?? null) !== true && $cancelAt === null)) {
            return null;
        }

        $effectiveAt = $cancelAt !== null ? $this->utc($cancelAt) : $periodEnd;

        if ($effectiveAt === null) {
            throw new CheckoutException(sprintf(
                'Stripe subscription %s is scheduled to cancel but carries no date for it — neither `cancel_at` '
                . 'nor a current period end — so the cancellation cannot be represented.',
                $id
            ));
        }

        return new ScheduledChange(ScheduledChangeAction::Cancel, $effectiveAt);
    }

    /**
     * The sum of price x quantity over every item, in the one currency they share.
     *
     * Null when any item has no fixed unit amount — a tiered or metered price is billed from
     * usage, so "what does this renew for" has no honest figure, and null says so where a
     * failure would turn a valid subscription into an unreadable one. Zero items, a negative
     * amount, a malformed currency, or two currencies are not that: they are refused.
     *
     * @param array<string, mixed> $subscription
     */
    private function recurringAmountOf(array $subscription, string $id): ?Money
    {
        $items = $subscription['items']['data'] ?? null;

        if (!is_array($items) || $items === []) {
            throw new CheckoutException(sprintf('Stripe subscription %s carried no items to price it from.', $id));
        }

        $total = null;
        $fixed = true;

        try {
            foreach ($items as $item) {
                $price = is_array($item) ? ($item['price'] ?? null) : null;

                if (!is_array($price)) {
                    throw new CheckoutException(sprintf('Stripe subscription %s carried an item with no price.', $id));
                }

                $unitAmount = $price['unit_amount'] ?? null;
                $quantity = $item['quantity'] ?? 1;

                if ($unitAmount === null) {
                    $fixed = false;

                    continue;
                }

                if (!is_int($unitAmount) || !is_int($quantity) || !is_string($price['currency'] ?? null)) {
                    throw new CheckoutException(sprintf(
                        'Stripe subscription %s carried an item whose unit_amount, quantity or currency is malformed.',
                        $id
                    ));
                }

                // Money does its arithmetic in plain ints, and an overflow becomes a float that its
                // typed constructor answers with a TypeError. Refuse before that can happen.
                if ($quantity > 0 && $unitAmount > intdiv(PHP_INT_MAX, $quantity)) {
                    throw $this->tooLargeToRepresent($id);
                }

                $line = (new Money($unitAmount, $price['currency']))->multipliedBy($quantity);

                if ($total !== null && $line->minorUnits > PHP_INT_MAX - $total->minorUnits) {
                    throw $this->tooLargeToRepresent($id);
                }

                $total = $total?->plus($line) ?? $line;
            }
        } catch (InvalidArgumentException $e) {
            throw new CheckoutException(
                sprintf('Stripe subscription %s carried an amount that cannot be read: %s', $id, $e->getMessage()),
                previous: $e
            );
        }

        return $fixed ? $total : null;
    }

    private function tooLargeToRepresent(string $id): CheckoutException
    {
        return new CheckoutException(sprintf(
            'Stripe subscription %s carried prices whose total is too large to represent as an amount.',
            $id
        ));
    }

    /**
     * Descriptive, not load-bearing, so a cycle this library cannot name — an unknown interval, a
     * count that is not a positive integer — reads as absent rather than failing the subscription.
     *
     * @param array<string, mixed> $item
     */
    private function billingCycleOf(array $item): ?BillingCycle
    {
        $recurring = $item['price']['recurring'] ?? null;

        if (!is_array($recurring) || !is_string($recurring['interval'] ?? null)) {
            return null;
        }

        $interval = BillingInterval::tryFrom($recurring['interval']);
        $count = $recurring['interval_count'] ?? 1;

        return $interval !== null && is_int($count) && $count >= 1 ? new BillingCycle($interval, $count) : null;
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    private function firstItemOf(array $subscription): array
    {
        $first = $subscription['items']['data'][0] ?? null;

        return is_array($first) ? $first : [];
    }

    private function unixOf(mixed $value): ?DateTimeImmutable
    {
        return is_int($value) ? $this->utc($value) : null;
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
            return $this->paidPaymentIntentOf($this->fetchInvoice($reference, $request->timeoutSeconds), $reference);
        }

        if (str_starts_with($reference, 'cs_')) {
            $invoice = $this->fetchSession($reference, $request->timeoutSeconds)['invoice'] ?? null;

            if (!is_array($invoice)) {
                throw new CheckoutException(sprintf(
                    'Checkout session %s carries no invoice to refund. A session the customer never completed has '
                    . 'none, and so has no payment behind it.',
                    $reference
                ));
            }

            return $this->paidPaymentIntentOf($invoice, $reference);
        }

        throw new CheckoutException(sprintf(
            'StripeSubscription refunds a PaymentIntent (pi_...), an invoice (in_...) or a checkout session '
            . '(cs_...), not "%s".',
            $reference
        ));
    }
}
