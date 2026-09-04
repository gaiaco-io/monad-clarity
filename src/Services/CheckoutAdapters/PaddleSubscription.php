<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\CheckoutAdapters;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
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

/**
 * Paddle Billing adapter for **recurring** payments — subscriptions started through the
 * Transactions API, then read and changed through `/subscriptions`.
 *
 * It sits beside PaddleCheckout exactly as §9.4 reserves StripeConnectExpress beside
 * StripeCheckout: one gateway, two genuinely different flows, one class each. Everything the
 * two share lives in the SpeaksPaddle trait, so the signed-callback scheme and the over-refund
 * guard exist once rather than twice.
 *
 * **A subscription is born from a transaction, not created by an API call.** There is no
 * `POST /subscriptions`. createCheckout() sends a transaction for a recurring price — either a
 * catalogue `pri_...` this adapter was constructed with, or an inline price carrying the
 * billing cycle it was constructed with — and Paddle creates the subscription when the
 * customer actually pays. So the reference createCheckout() returns is a `txn_`, and the `sub_`
 * every operation below acts on does not exist yet. Two routes lead from one to the other, and
 * an application should handle both because either delivery can be dropped:
 *
 *   subscriptionReferenceOf($event)   reads `subscription_id` off a settled transaction
 *   parseSubscriptionCallback($body)  reads it off the `subscription.created` delivery,
 *                                     which also names the transaction it came from
 *
 * The four inherited Checkout methods stay strictly transaction-scoped, and that is not a
 * limitation: a subscription's money movements *are* transactions. retrieveStatus() re-queries
 * a renewal, refund() refunds one, and parseCallback() still accepts only `transaction.*`.
 * Subscription lifecycle is the separate surface below, with its own value objects, because
 * TransactionStatus has four cases describing whether one payment moved and none of them
 * honestly describes a standing arrangement.
 *
 * One mapping deliberately differs from PaddleCheckout: **`past_due` is Pending here, not
 * Failed.** See mapTransactionStatus().
 *
 * Paddle takes no idempotency keys on any endpoint (ReleaseNotes_1.3.0.md §2.3). cancel(),
 * pause() and resume() are naturally idempotent, so a retry is harmless. changePlan() with an
 * immediate proration mode is **not** — it charges — so it must not be retried blindly.
 *
 * One operational constraint worth knowing before it is discovered in production, confirmed
 * live: **while any change is scheduled on a subscription, Paddle refuses every further change
 * to it** with `subscription_locked_pending_changes`. A cancellation set for the end of the
 * period therefore blocks pausing, re-planning, and cancelling immediately for as long as it
 * stands. removeScheduledChange() is the way back out.
 *
 * @package Monad\Clarity\Services\CheckoutAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class PaddleSubscription extends Checkout
{
    use SpeaksPaddle;

    private const GATEWAY = 'paddle_subscription';

    /**
     * One adapter instance means one plan's terms, in both of its two modes — a merchant with
     * monthly and annual tiers constructs two of these, and one with three tiers on two cycles
     * constructs six. That is adapter configuration rather than request data because the
     * inherited createCheckout() signature is fixed and CheckoutRequest is frozen
     * (`ReleaseNotes_1.4.0.md` §2.4), and because it is the honest reading anyway.
     *
     * @param BillingCycle|null $billingCycle How often this adapter's subscriptions charge,
     *        for an inline plan. **Pass null when passing $catalogPriceId** — a catalogue price
     *        states its own cycle, and a second answer here could only contradict it. Exactly
     *        one of the two is required; prefer the forCatalogPrice() constructor over passing
     *        nulls positionally.
     * @param BillingCycle|null $trialPeriod A free period before the first charge, if the plan
     *        offers one. Same shape as a billing cycle, because it is the same kind of measure.
     *        Inline mode only — a catalogue price carries its own trial, and a plan configured
     *        with one produces a first transaction of zero rather than no transaction at all.
     * @param string $webhookSecret Signing secret for this notification destination
     *        (`pdl_ntfset_...`). Empty disables both callback parsers with an explicit error
     *        rather than silently accepting unverified callbacks.
     * @param string|null $hostedCheckoutUrl Paddle's own hosted checkout link.
     * @param string|null $paymentPageUrl Your own approved page running Paddle.js. Pass
     *        exactly one of these two — see assertCheckoutMode().
     * @param string $taxCategory Paddle is the merchant of record, so this decides how the
     *        sale is taxed. The `standard` default is right for ordinary goods and services
     *        and **wrong for most subscriptions**: a recurring charge is usually SaaS,
     *        software, or ebooks, each of which has a category of its own that must be passed.
     *        Unused in catalogue mode — a catalogue product carries its own.
     * @param string $baseUri `https://sandbox-api.paddle.com` for the sandbox.
     * @param string|null $catalogPriceId A recurring `pri_...` the merchant already maintains
     *        in Paddle, which switches this adapter into catalogue mode. The price states the
     *        amount, the currency, the billing cycle, the trial and the tax category, so none
     *        of those is passed here and none can drift out of step with the dashboard.
     * @throws CheckoutException if neither or both of $billingCycle and $catalogPriceId are
     *         given, or if $catalogPriceId is not a price id.
     */
    public function __construct(
        string $apiKey,
        HttpClient $httpClient,
        private readonly ?BillingCycle $billingCycle,
        private readonly ?BillingCycle $trialPeriod = null,
        private readonly string $webhookSecret = '',
        private readonly ?string $hostedCheckoutUrl = null,
        private readonly ?string $paymentPageUrl = null,
        private readonly string $taxCategory = self::DEFAULT_TAX_CATEGORY,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
        private readonly ?string $catalogPriceId = null,
    ) {
        self::assertCatalogPriceId($catalogPriceId);
        self::assertOnePlanSource($billingCycle, $trialPeriod, $catalogPriceId);

        parent::__construct($apiKey, $httpClient);
    }

    /**
     * A subscription on a plan the merchant publishes in the Paddle dashboard.
     *
     * The plan's terms are read from the catalogue rather than restated here, which is why
     * this constructor takes no billing cycle, no trial period and no tax category: naming a
     * `pri_...` is the whole description of what is being sold. Prefer it to the primary
     * constructor for catalogue mode — it is the same object either way, but this one cannot
     * be handed a cycle that contradicts the price.
     */
    public static function forCatalogPrice(
        string $apiKey,
        HttpClient $httpClient,
        string $catalogPriceId,
        string $webhookSecret = '',
        ?string $hostedCheckoutUrl = null,
        ?string $paymentPageUrl = null,
        string $baseUri = self::DEFAULT_BASE_URI,
    ): self {
        return new self(
            $apiKey,
            $httpClient,
            billingCycle: null,
            webhookSecret: $webhookSecret,
            hostedCheckoutUrl: $hostedCheckoutUrl,
            paymentPageUrl: $paymentPageUrl,
            baseUri: $baseUri,
            catalogPriceId: $catalogPriceId,
        );
    }

    /**
     * A plan's terms come from exactly one place. Neither is a silent no-op that would send
     * Paddle a one-time price and quietly never create a subscription; both would be two
     * answers to "how often does this charge", and Paddle would honour the catalogue's while
     * the adapter's constructor argument said otherwise.
     *
     * @throws CheckoutException
     */
    private static function assertOnePlanSource(
        ?BillingCycle $billingCycle,
        ?BillingCycle $trialPeriod,
        ?string $catalogPriceId
    ): void {
        if (($billingCycle === null) === ($catalogPriceId === null)) {
            throw new CheckoutException($billingCycle === null
                ? 'A PaddleSubscription needs to know what it bills. Pass a $billingCycle to describe the '
                    . 'plan inline, or a $catalogPriceId (pri_...) to bill a plan you publish in Paddle — '
                    . 'PaddleSubscription::forCatalogPrice() is the readable way to do the latter.'
                : sprintf(
                    'This PaddleSubscription was constructed with both a $billingCycle and the catalogue '
                        . 'price %s. The catalogue price already states how often it charges, so these are two '
                        . 'answers to one question and Paddle would honour the catalogue\'s. Pass one.',
                    $catalogPriceId
                ));
        }

        if ($catalogPriceId !== null && $trialPeriod !== null) {
            throw new CheckoutException(sprintf(
                'This PaddleSubscription was constructed with both a $trialPeriod and the catalogue price %s. '
                    . 'A trial belongs to the price in Paddle, so set it there — passing one here would be '
                    . 'ignored, which is worse than being refused.',
                $catalogPriceId
            ));
        }
    }

    /**
     * Begin a subscription by creating the transaction that will produce it.
     *
     * The returned session's gatewayReference is the **transaction** — a `txn_`, not a `sub_`.
     * Paddle creates the subscription when this transaction is paid, so until the customer
     * pays there is no subscription to return a handle to. Use subscriptionReferenceOf() or
     * parseSubscriptionCallback() to learn the `sub_` once it exists.
     */
    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $this->assertCheckoutMode();

        $params = [
            'items' => $this->itemParams($request, $this->billingCycle, $this->trialPeriod),
            ...$this->currencyParams($request),
            'collection_mode' => 'automatic',
            'custom_data' => $this->customData($request),
        ];

        if ($this->paymentPageUrl !== null) {
            $params['checkout'] = ['url' => $this->paymentPageUrl];
        }

        $transaction = $this->send('POST', '/transactions', $params, $request->timeoutSeconds);

        return new CheckoutSession(
            gateway: self::GATEWAY,
            gatewayReference: $this->requireString($transaction, 'id'),
            redirectUrl: $this->redirectUrlFor($transaction, $request),
            status: $this->mapTransactionStatus($transaction),
            amount: $this->amountOf($transaction, $request->amount),
            paymentReference: null,
            raw: $transaction,
        );
    }

    /**
     * The subscription a settled transaction created or belongs to, or null if it has none.
     *
     * Null is an honest answer rather than a failure: a transaction that has not been paid yet
     * genuinely has no subscription, because Paddle creates one only when money moves.
     *
     * **Null does not settle the question even once the transaction has.** Confirmed against
     * the live sandbox: a transaction read back immediately after settling still carried
     * `subscription_id: null`, and the same transaction carried the id seconds later. Paddle
     * creates the subscription asynchronously. So treat null as "not yet" rather than "never" —
     * poll again, or let the `subscription.created` callback bring it, which is why the ledger
     * accepts the link from whichever side arrives first.
     */
    public function subscriptionReferenceOf(CallbackEvent|TransactionSnapshot $transaction): ?string
    {
        $source = $transaction instanceof CallbackEvent
            ? ($transaction->raw['data'] ?? [])
            : $transaction->raw;

        $reference = is_array($source) ? ($source['subscription_id'] ?? null) : null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * Re-query a subscription's current state — the reconciliation path for a callback that
     * never arrived, and the way to read a subscription an application has lost track of.
     */
    public function retrieveSubscription(string $subscriptionReference, int $timeoutSeconds = 30): SubscriptionSnapshot
    {
        $this->assertSubscriptionReference($subscriptionReference);

        return $this->snapshotFrom(
            $this->send('GET', '/subscriptions/' . rawurlencode($subscriptionReference), [], $timeoutSeconds)
        );
    }

    /**
     * Verify a `subscription.*` callback and normalise it.
     *
     * The transaction parser and this one are separate doors, because a subscription event is
     * not a transaction event and neither can be read as the other. An application routes on
     * the event type's prefix and calls the matching one.
     *
     * @param array<string, string> $headers
     * @throws CheckoutException if the signature is absent, malformed, stale, or does not
     *         verify; or if the payload is not a subscription event this adapter understands.
     */
    public function parseSubscriptionCallback(string $rawBody, array $headers): SubscriptionEvent
    {
        // Verification comes first, before the body is decoded, inspected or branched on.
        // Nothing below this line runs for bytes Paddle did not sign.
        $this->verifySignature($rawBody, $headers);

        /** @var array<string, mixed> $event */
        $event = json_decode($rawBody, associative: true) ?: [];

        if (!isset($event['event_id'], $event['event_type'])) {
            throw new CheckoutException('Paddle webhook payload verified but carried no event_id or event_type.');
        }

        $type = (string) $event['event_type'];
        $data = $event['data'] ?? [];

        if (!is_array($data)) {
            throw new CheckoutException(sprintf('Paddle webhook "%s" carried no event data.', $type));
        }

        // A notification destination delivers every event type subscribed on it, so
        // transaction.*, customer.* and adjustment.* arrive at the same URL. Without this
        // guard they would parse "successfully" into a SubscriptionEvent whose reference is a
        // transaction or customer id, which is not a subscription at all.
        if (!str_starts_with($type, 'subscription.')) {
            throw new CheckoutException(sprintf(
                'Paddle webhook "%s" is a %s event, not a subscription event — this adapter cannot interpret '
                . 'it. A notification destination delivers every event type subscribed on it; route on the '
                . 'event type and send transaction.* events to parseCallback() instead.',
                $type,
                strstr($type, '.', true) ?: 'unrecognised'
            ));
        }

        return new SubscriptionEvent(
            gateway: self::GATEWAY,
            eventId: (string) $event['event_id'],
            eventType: $type,
            occurredAt: $this->occurredAt($event, $type),
            subscription: $this->snapshotFrom($data),
            raw: $event,
        );
    }

    /**
     * Cancel a subscription.
     *
     * **The returned snapshot will usually still say Active.** Cancelling for the next billing
     * period schedules the cancellation for the date the customer's paid-for period ends; the
     * subscription stays active until then, with a ScheduledChange describing what is coming.
     * Only $effectiveFrom = Immediately ends it on the spot, and Paddle then prorates a refund
     * for the unused part — a different product decision, not a faster version of the same one.
     *
     * $effectiveFrom is required and has no default, though Paddle has one. Gateways disagree
     * about what their default should be, which is exactly why an adapter should not pick.
     *
     * @throws CheckoutException if $subscriptionReference is not a sub_ id, or Paddle refuses.
     */
    public function cancel(
        string $subscriptionReference,
        SubscriptionEffectiveFrom $effectiveFrom,
        int $timeoutSeconds = 30
    ): SubscriptionSnapshot {
        return $this->act($subscriptionReference, 'cancel', ['effective_from' => $effectiveFrom->value], $timeoutSeconds);
    }

    /**
     * Pause a subscription — billing stops and, by convention, so does service.
     *
     * $onResume may only be given alongside $resumeAt. Paddle rejects it otherwise —
     * `cannot use on_resume if resume_at is not present` — which makes sense on reflection:
     * there is no billing period to decide about until a return date exists. Found against the
     * live sandbox, where a mocked fixture had happily accepted the invalid pairing.
     *
     * @param DateTimeInterface|null $resumeAt When to start again. Omit for a pause that runs
     *        until it is resumed by hand.
     * @param ResumeBilling|null $onResume Whether resuming continues the interrupted billing
     *        period or starts a fresh one. Requires $resumeAt.
     * @throws CheckoutException if $subscriptionReference is not a sub_ id, if $onResume is
     *         given without $resumeAt, or if Paddle refuses.
     */
    public function pause(
        string $subscriptionReference,
        SubscriptionEffectiveFrom $effectiveFrom,
        ?DateTimeInterface $resumeAt = null,
        ?ResumeBilling $onResume = null,
        int $timeoutSeconds = 30
    ): SubscriptionSnapshot {
        if ($onResume !== null && $resumeAt === null) {
            throw new CheckoutException(
                'A pause can only say how to resume billing if it also says when to resume: Paddle refuses '
                . 'on_resume without resume_at. Pass a $resumeAt, or drop $onResume and let the resume() '
                . 'call decide when the time comes.'
            );
        }

        $params = ['effective_from' => $effectiveFrom->value];

        // Both omitted rather than sent as null: an absent resume_at is an indefinite pause,
        // which is a different instruction from "resume at no particular time".
        if ($resumeAt !== null) {
            $params['resume_at'] = self::rfc3339($resumeAt);

            if ($onResume !== null) {
                $params['on_resume'] = $onResume->value;
            }
        }

        return $this->act($subscriptionReference, 'pause', $params, $timeoutSeconds);
    }

    /**
     * Resume a subscription that is **already paused**.
     *
     * Two constraints, both confirmed against the live sandbox rather than taken from the
     * documentation, which is wrong on each:
     *
     * 1. **`NextBillingPeriod` is not a legal choice here.** Paddle accepts only `immediately`
     *    for a resume, and answers anything else with a bare `bad_request` / "Invalid request."
     *    that names nothing. So the adapter refuses it locally and says why — the one thing a
     *    caller cannot work out from the gateway's own reply.
     * 2. **The subscription must actually be paused, not merely pause-scheduled.** Paddle's
     *    documentation says resuming an active subscription with a pending pause edits that
     *    pause's resume date; the API refuses it with `subscription_must_be_paused`. To move a
     *    scheduled pause, clear it with removeScheduledChange() and schedule it again.
     *
     * This may charge the customer immediately, so the call can take longer than the others
     * while a payment is attempted.
     *
     * @throws CheckoutException if $subscriptionReference is not a sub_ id, if $effectiveFrom
     *         is NextBillingPeriod, or if Paddle refuses.
     */
    public function resume(
        string $subscriptionReference,
        SubscriptionEffectiveFrom $effectiveFrom,
        ResumeBilling $onResume = ResumeBilling::StartNewBillingPeriod,
        int $timeoutSeconds = 30
    ): SubscriptionSnapshot {
        if ($effectiveFrom === SubscriptionEffectiveFrom::NextBillingPeriod) {
            throw new CheckoutException(
                'A subscription can only be resumed immediately. Paddle accepts no next-billing-period '
                . 'resume and refuses one as a bare "Invalid request." that names nothing, so this is '
                . 'refused here instead — pass SubscriptionEffectiveFrom::Immediately.'
            );
        }

        return $this->act($subscriptionReference, 'resume', [
            'effective_from' => $effectiveFrom->value,
            'on_resume' => $onResume->value,
        ], $timeoutSeconds);
    }

    /**
     * Withdraw a scheduled cancellation or pause, leaving the subscription running as it was.
     *
     * This is not a convenience. **While any change is scheduled, Paddle refuses every further
     * change to that subscription** with `subscription_locked_pending_changes` — so a merchant
     * who schedules a cancellation for the end of the period cannot then pause, change plan, or
     * cancel immediately until the schedule resolves or is withdrawn. Without this method a
     * customer who changed their mind would have to wait out their own cancellation. Confirmed
     * against the live sandbox, and the same shape of operational constraint as the pending
     * refund lock recorded on PaddleCheckout::refund().
     *
     * Safe when nothing is scheduled: the subscription comes back unchanged.
     *
     * @throws CheckoutException if $subscriptionReference is not a sub_ id, or Paddle refuses.
     */
    public function removeScheduledChange(
        string $subscriptionReference,
        int $timeoutSeconds = 30
    ): SubscriptionSnapshot {
        $this->assertSubscriptionReference($subscriptionReference);

        return $this->snapshotFrom($this->send(
            'PATCH',
            '/subscriptions/' . rawurlencode($subscriptionReference),
            ['scheduled_change' => null],
            $timeoutSeconds
        ));
    }

    /**
     * Move a subscription onto a different set of items — an upgrade, a downgrade, or a change
     * of term.
     *
     * **$items replaces the subscription's whole item set; it does not add to it.** Anything
     * left out is removed. For a single-plan subscription that is simply the new plan; for one
     * with add-ons, pass the add-ons again alongside the new tier or they are dropped.
     *
     * $prorationBillingMode is required and has no default, because the five modes differ by
     * real money charged to a real customer and there is none that is quietly right for every
     * flow. See ProrationBillingMode for which suits which.
     *
     * This is the one operation here that is unsafe to retry blindly: with an immediate
     * proration mode it charges, and Paddle accepts no idempotency keys.
     *
     * @param list<SubscriptionItem> $items The complete item set the subscription should bill.
     * @throws CheckoutException if the item list is empty, mixes billing intervals, or Paddle
     *         refuses the change.
     */
    public function changePlan(
        string $subscriptionReference,
        array $items,
        ProrationBillingMode $prorationBillingMode,
        PaymentFailureBehaviour $onPaymentFailure = PaymentFailureBehaviour::PreventChange,
        int $timeoutSeconds = 30
    ): SubscriptionSnapshot {
        $this->assertSubscriptionReference($subscriptionReference);
        $this->assertChangeableItems($items);

        $subscription = $this->send('PATCH', '/subscriptions/' . rawurlencode($subscriptionReference), [
            'items' => array_map(fn (SubscriptionItem $item): array => $this->planItemParams($item), $items),
            'proration_billing_mode' => $prorationBillingMode->value,
            'on_payment_failure' => $onPaymentFailure->value,
        ], $timeoutSeconds);

        return $this->snapshotFrom($subscription);
    }

    protected function gatewayName(): string
    {
        return self::GATEWAY;
    }

    /**
     * Paddle's transaction states onto §9.6.5's four — and the one place this adapter
     * deliberately disagrees with PaddleCheckout.
     *
     * PaddleCheckout reads `past_due` as Failed, which is terminal and correct for a one-time
     * payment that will not be retried. On a **renewal** it would be a silent revenue defect:
     * `past_due` is dunning, Paddle Retain keeps trying, and the charge legitimately completes
     * days later. Because the ledger refuses to move a transaction away from a terminal
     * status, the recovered renewal would be locked at `failed` for good while its status
     * history quietly recorded the truth.
     *
     * Pending is the honest reading — the money has not arrived and has not been given up on —
     * and it is the status that can still change.
     *
     * @param array<string, mixed> $transaction
     */
    protected function mapTransactionStatus(array $transaction): TransactionStatus
    {
        return match (isset($transaction['status']) ? (string) $transaction['status'] : null) {
            'completed', 'paid' => TransactionStatus::Success,
            'canceled' => TransactionStatus::Cancelled,
            default => TransactionStatus::Pending,
        };
    }

    /**
     * The three lifecycle endpoints differ only in their path segment and body, so they share
     * one implementation — including the reference guard, which every one of them needs.
     *
     * @param array<string, mixed> $params
     */
    private function act(
        string $subscriptionReference,
        string $operation,
        array $params,
        int $timeoutSeconds
    ): SubscriptionSnapshot {
        $this->assertSubscriptionReference($subscriptionReference);

        return $this->snapshotFrom($this->send(
            'POST',
            '/subscriptions/' . rawurlencode($subscriptionReference) . '/' . $operation,
            $params,
            $timeoutSeconds
        ));
    }

    /**
     * Two things Paddle will otherwise punish at a distance, refused here by name.
     *
     * An empty list is the dangerous one: because the update replaces rather than appends, it
     * would strip the subscription of everything it bills — a cancellation wearing a plan
     * change's clothes.
     *
     * Mixed billing intervals are rejected by Paddle, and can be caught locally for the inline
     * items whose cycles this adapter can actually see. A catalogue price keeps its interval in
     * the gateway, so a mixture involving one is left to Paddle to refuse — better its own
     * message than a guess made without the facts.
     *
     * @param list<SubscriptionItem> $items
     * @throws CheckoutException if the list is empty or mixes intervals.
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

        foreach ($items as $item) {
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
     * A catalogue price travels as its id; an inline one carries the same non-catalog price and
     * product shape createCheckout() sends, so a merchant who never seeded a catalogue can still
     * move customers between plans.
     *
     * @return array<string, mixed>
     */
    private function planItemParams(SubscriptionItem $item): array
    {
        if ($item->isCatalogPrice()) {
            return ['price_id' => $item->priceId, 'quantity' => $item->quantity];
        }

        /** @var \Monad\Clarity\Services\Checkout\LineItem $lineItem */
        $lineItem = $item->lineItem;

        return $this->itemParam(
            $lineItem->description,
            $lineItem->unitPrice,
            $item->quantity,
            $item->billingCycle
        );
    }

    /**
     * Paddle takes RFC 3339, and normalising to UTC keeps a merchant's own timezone out of a
     * date the gateway will echo back in its own.
     */
    private static function rfc3339(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::RFC3339);
    }

    /**
     * Every subscription operation acts on a `sub_`, and the commonest mistake is reaching for
     * the `txn_` createCheckout() returned. The guard costs one string comparison and the
     * message is worth more than the guard.
     *
     * @throws CheckoutException if $reference is not a Paddle subscription id.
     */
    private function assertSubscriptionReference(string $reference): void
    {
        if (str_starts_with($reference, 'sub_')) {
            return;
        }

        throw new CheckoutException(str_starts_with($reference, 'txn_')
            ? sprintf(
                '"%s" is a transaction id, not a subscription id. A Paddle subscription is born from a '
                . 'transaction and gets its own sub_ identifier once the customer pays — read it with '
                . 'subscriptionReferenceOf() once that transaction has settled, or off the '
                . 'subscription.created callback.',
                $reference
            )
            : sprintf(
                'A subscription operation needs a Paddle subscription id (sub_...), got "%s".',
                $reference === '' ? 'an empty string' : $reference
            ));
    }

    /**
     * Paddle's five subscription states onto Clarity's own.
     *
     * Unlike mapTransactionStatus(), an unrecognised value **throws** rather than defaulting.
     * Every case here is an assertion about whether a paying customer still has what they paid
     * for, and there is no safe direction to err in: defaulting to Paused locks out a paying
     * customer, defaulting to Active gives the product away. So the webhook fails loudly,
     * Paddle retries, and a human looks — which is the right response to a gateway that has
     * started sending a state this adapter has never heard of.
     *
     * @throws CheckoutException if the status is missing or unrecognised.
     */
    private function mapSubscriptionStatus(mixed $status): SubscriptionStatus
    {
        return match (is_string($status) ? $status : null) {
            'active' => SubscriptionStatus::Active,
            'trialing' => SubscriptionStatus::Trialing,
            'past_due' => SubscriptionStatus::PastDue,
            'paused' => SubscriptionStatus::Paused,
            // Paddle spells it with one l; Clarity spells it with two.
            'canceled' => SubscriptionStatus::Cancelled,
            default => throw new CheckoutException(sprintf(
                'Paddle reported subscription status "%s", which this adapter does not recognise. A '
                . 'subscription status decides whether a paying customer keeps access, so there is no safe '
                . 'default to fall back on — refusing the event is deliberate.',
                is_string($status) && $status !== '' ? $status : 'none'
            )),
        };
    }

    /**
     * One parser for both the webhook payload and the retrieval response, because Paddle
     * describes the same entity in both — so retrieve and parse can never drift into
     * disagreeing about what a subscription is in.
     *
     * @param array<array-key, mixed> $data
     */
    private function snapshotFrom(array $data): SubscriptionSnapshot
    {
        $customData = is_array($data['custom_data'] ?? null) ? $data['custom_data'] : [];
        $period = is_array($data['current_billing_period'] ?? null) ? $data['current_billing_period'] : [];

        return new SubscriptionSnapshot(
            gateway: self::GATEWAY,
            gatewayReference: $this->requireSubscriptionId($data),
            status: $this->mapSubscriptionStatus($data['status'] ?? null),
            scheduledChange: $this->scheduledChangeFrom($data),
            reference: self::stringOrNull($customData['reference'] ?? null),
            transactionReference: self::stringOrNull($data['transaction_id'] ?? null),
            customerReference: self::stringOrNull($data['customer_id'] ?? null),
            recurringAmount: $this->recurringAmountOf($data),
            billingCycle: self::cycleFrom($data['billing_cycle'] ?? null),
            nextBilledAt: self::dateOrNull($data['next_billed_at'] ?? null),
            currentPeriodStartsAt: self::dateOrNull($period['starts_at'] ?? null),
            currentPeriodEndsAt: self::dateOrNull($period['ends_at'] ?? null),
            raw: $data,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws CheckoutException if the id is absent or is not a subscription id.
     */
    private function requireSubscriptionId(array $data): string
    {
        $reference = isset($data['id']) ? (string) $data['id'] : '';

        if (!str_starts_with($reference, 'sub_')) {
            throw new CheckoutException(sprintf(
                'Paddle subscription payload carried "%s" where a sub_ subscription id was expected.',
                $reference === '' ? 'no id' : $reference
            ));
        }

        return $reference;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function scheduledChangeFrom(array $data): ?ScheduledChange
    {
        $change = $data['scheduled_change'] ?? null;

        if (!is_array($change) || !isset($change['action'])) {
            return null;
        }

        $action = ScheduledChangeAction::tryFrom((string) $change['action']);
        $effectiveAt = self::dateOrNull($change['effective_at'] ?? null);

        if ($action === null || $effectiveAt === null) {
            throw new CheckoutException(sprintf(
                'Paddle reported a scheduled change this adapter could not read: action "%s", effective_at "%s".',
                is_scalar($change['action'] ?? null) ? (string) $change['action'] : 'none',
                is_scalar($change['effective_at'] ?? null) ? (string) $change['effective_at'] : 'none'
            ));
        }

        return new ScheduledChange(
            action: $action,
            effectiveAt: $effectiveAt,
            // Only a pause resumes, and Paddle sends resume_at as null on the others.
            resumeAt: $action === ScheduledChangeAction::Pause
                ? self::dateOrNull($change['resume_at'] ?? null)
                : null,
        );
    }

    /**
     * What the subscription charges each cycle — the sum of its items' unit prices times their
     * quantities. Null when Paddle sent no items to add up, which happens on some event types.
     *
     * Money validates what it is given and throws InvalidArgumentException on a negative
     * amount, a currency code that is not ISO 4217, or a sum across two currencies. That is the
     * right behaviour for a value object and the wrong exception to let out of here: this
     * method sits under parseSubscriptionCallback(), which promises callers a single
     * CheckoutException, and a webhook handler catching that would take an uncaught fatal
     * instead. A verified payload the adapter cannot read is a gateway problem, so it is
     * reported as one.
     *
     * @param array<array-key, mixed> $data
     * @throws CheckoutException if the amounts cannot be read as one currency's money.
     */
    private function recurringAmountOf(array $data): ?Money
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $currency = self::stringOrNull($data['currency_code'] ?? null);
        $total = null;

        try {
            foreach ($items as $item) {
                $amount = is_array($item) ? ($item['price']['unit_price']['amount'] ?? null) : null;
                $itemCurrency = is_array($item)
                    ? self::stringOrNull($item['price']['unit_price']['currency_code'] ?? null)
                    : null;

                if (!is_numeric($amount)) {
                    continue;
                }

                $line = new Money(
                    (int) $amount * max(1, (int) ($item['quantity'] ?? 1)),
                    $itemCurrency ?? $currency ?? 'USD'
                );

                $total = $total === null ? $line : $total->plus($line);
            }
        } catch (InvalidArgumentException $e) {
            throw new CheckoutException(sprintf(
                'Paddle subscription %s carried item amounts this adapter could not read as a recurring '
                . 'charge: %s',
                isset($data['id']) ? (string) $data['id'] : 'payload',
                $e->getMessage()
            ), previous: $e);
        }

        return $total;
    }

    /**
     * The gateway's own timestamp for when an event happened, always in UTC.
     *
     * Required rather than optional: a subscription is one mutable row with no insert-only
     * history behind it, so ordering is the only thing standing between a redelivered older
     * event and a row that silently goes backwards. An event without it cannot be ordered and
     * is refused.
     *
     * @param array<string, mixed> $event
     * @throws CheckoutException if occurred_at is absent or unparseable.
     */
    private function occurredAt(array $event, string $type): DateTimeImmutable
    {
        $occurredAt = self::dateOrNull($event['occurred_at'] ?? null);

        if ($occurredAt === null) {
            throw new CheckoutException(sprintf(
                'Paddle webhook "%s" carried no readable occurred_at. A subscription is a single mutable '
                . 'record, so an event that cannot be ordered against what is already stored cannot be '
                . 'applied safely.',
                $type
            ));
        }

        return $occurredAt;
    }

    private static function cycleFrom(mixed $cycle): ?BillingCycle
    {
        if (!is_array($cycle) || !isset($cycle['interval'])) {
            return null;
        }

        $interval = BillingInterval::tryFrom((string) $cycle['interval']);

        return $interval === null
            ? null
            : new BillingCycle($interval, max(1, (int) ($cycle['frequency'] ?? 1)));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Paddle sends RFC 3339 with a `Z`. Normalised to UTC so every stored timestamp is
     * comparable regardless of the host's own timezone.
     */
    private static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
