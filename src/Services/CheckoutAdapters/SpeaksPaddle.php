<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\CheckoutAdapters;

use Monad\Clarity\Services\Checkout\BillingCycle;
use Monad\Clarity\Services\Checkout\CallbackEvent;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Services\Checkout\RefundResult;
use Monad\Clarity\Services\Checkout\TransactionSnapshot;
use Monad\Clarity\Services\Checkout\TransactionStatus;
use Monad\Clarity\Utils\ConstantTime;
use Monad\Clarity\Utils\HMAC;

/**
 * Everything two Paddle adapters do identically: the signed-callback scheme, the `data`
 * envelope, cursor pagination, both ways of naming what is being sold — a catalogue price id
 * or an inline non-catalog price — and the three transaction-scoped operations that read the
 * same endpoints whether the money is one-time or recurring.
 *
 * Extracted rather than duplicated because the largest thing in here is the over-refund
 * guard, and 1.3.0's sandbox run found a real pagination defect inside it that 49 passing
 * tests could not see. A second copy of that code is a second copy of that class of bug,
 * waiting to drift out of step with the first.
 *
 * `Console\GeneratesFiles` is the precedent for a trait shared between sibling classes.
 *
 * **Usable only inside a `Services\Checkout` subclass** that also declares `$webhookSecret`,
 * `$taxCategory`, `$baseUri` and `$catalogPriceId`. It reaches the base class's `$apiKey`,
 * `$httpClient`, `assertSuccessful()`, `decodeJsonBody()` and `header()`, and states the two
 * things that genuinely differ between adapters as abstract methods rather than assuming either.
 *
 * Note what is deliberately absent: no reference to a gateway-name constant. A trait naming
 * a constant its using class defines would stamp one adapter's gateway onto the other's
 * value objects and error messages. `gatewayName()` is already the base class's answer to
 * that question, so every gateway label in here goes through it.
 *
 * @package Monad\Clarity\Services\CheckoutAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
trait SpeaksPaddle
{
    private const DEFAULT_BASE_URI = 'https://api.paddle.com';

    private const API_VERSION = '1';

    /** Paddle's largest accepted page size; its default is ten. */
    private const MAX_PER_PAGE = 200;

    /**
     * Paddle's own SDKs default to five seconds, which is not a replay window so much as a
     * clock-skew trap: a callback queued behind one slow request, or a host a few seconds
     * out of sync with NTP, is refused as forged. Five minutes still makes a captured
     * request unreplayable within any useful timeframe, and matches the sibling adapter.
     */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    /**
     * Paddle is a merchant of record, so every product it bills carries a tax category that
     * decides how it is taxed. `standard` covers ordinary goods and services; a merchant
     * selling ebooks, SaaS, or software has a category of its own and passes it here.
     */
    private const DEFAULT_TAX_CATEGORY = 'standard';

    /**
     * Paddle requires a reason on every adjustment. RefundRequest's is optional, so one
     * stands in — an empty reason would fail the refund over a formality.
     */
    private const DEFAULT_REFUND_REASON = 'Requested by merchant.';

    /**
     * The gateway identifier this adapter stamps onto every value object and error message —
     * `paddle_checkout` or `paddle_subscription`. Declared here so a class that uses this
     * trait without one fails at compile time rather than mislabelling a transaction.
     */
    abstract protected function gatewayName(): string;

    /**
     * Paddle's seven transaction states onto §9.6.5's four — deliberately NOT shared, because
     * `past_due` does not mean the same thing in both adapters.
     *
     * On a one-time payment it is a dead payment: `Failed`, which is terminal, and the ledger
     * refuses to move a transaction away from it. On a subscription renewal it is dunning —
     * Paddle Retain is still retrying, and the charge legitimately completes days later. An
     * adapter that inherited the one-time mapping would lock every recovered renewal at
     * `failed` forever while its status history quietly recorded the truth.
     *
     * So each adapter states its own, and neither can acquire the other's by accident.
     *
     * @param array<string, mixed> $transaction
     */
    abstract protected function mapTransactionStatus(array $transaction): TransactionStatus;

    public function retrieveStatus(string $reference, int $timeoutSeconds = 30): TransactionSnapshot
    {
        $transaction = $this->send('GET', '/transactions/' . rawurlencode($reference), [], $timeoutSeconds);
        $status = $this->mapTransactionStatus($transaction);

        return new TransactionSnapshot(
            gateway: $this->gatewayName(),
            gatewayReference: $this->requireString($transaction, 'id'),
            status: $status,
            amount: $this->amountOf($transaction),
            failureReason: $status === TransactionStatus::Failed ? $this->failureReasonOf($transaction) : null,
            paymentReference: null,
            raw: $transaction,
        );
    }

    public function parseCallback(string $rawBody, array $headers): CallbackEvent
    {
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

        // A Paddle notification destination delivers every event type subscribed on it, so
        // subscription.*, customer.*, adjustment.* and price.* arrive at the same URL as the
        // transaction events. Without this guard they parse "successfully" into a
        // CallbackEvent whose gatewayReference is a subscription or customer id, which is
        // not a transaction reference at all. Paddle prefixes both the event type and the
        // entity id with what they are, so the discriminator is already in the payload.
        if (!str_starts_with($type, 'transaction.')) {
            throw new CheckoutException(sprintf(
                'Paddle webhook "%s" is a %s event, not a transaction event — this adapter cannot interpret '
                . 'it. A notification destination delivers every event type subscribed on it; subscribe only '
                . 'to transaction.* events, or catch this and ignore the rest.',
                $type,
                strstr($type, '.', true) ?: 'unrecognised'
            ));
        }

        $reference = isset($data['id']) ? (string) $data['id'] : '';

        if (!str_starts_with($reference, 'txn_')) {
            throw new CheckoutException(sprintf(
                'Paddle webhook "%s" carried "%s" where a txn_ transaction id was expected.',
                $type,
                $reference === '' ? 'no id' : $reference
            ));
        }

        $status = match ($type) {
            'transaction.completed', 'transaction.paid' => TransactionStatus::Success,
            'transaction.canceled' => TransactionStatus::Cancelled,
            'transaction.payment_failed' => TransactionStatus::Failed,
            default => $this->mapTransactionStatus($data),
        };

        return new CallbackEvent(
            gateway: $this->gatewayName(),
            eventId: (string) $event['event_id'],
            eventType: $type,
            gatewayReference: $reference,
            status: $status,
            failureReason: $status === TransactionStatus::Failed ? $this->failureReasonOf($data, $type) : null,
            paymentReference: null,
            raw: $event,
        );
    }

    /**
     * Note one Paddle-specific operational constraint, confirmed live: while any refund on a
     * transaction is awaiting approval, Paddle refuses every further adjustment against it
     * with `adjustment_pending_refund_request`. Because live refunds are created
     * `pending_approval` and reviewed by Paddle, partial refunds on one transaction are in
     * practice serialised behind that review rather than issuable back to back.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $params = [
            'action' => 'refund',
            'transaction_id' => $request->reference,
            'reason' => $request->reason ?? self::DEFAULT_REFUND_REASON,
        ];

        if ($request->amount === null) {
            $params['type'] = 'full';
        } else {
            $params['type'] = 'partial';
            $params['items'] = $this->refundItems($request);
        }

        $adjustment = $this->send('POST', '/adjustments', $params, $request->timeoutSeconds);

        return new RefundResult(
            gateway: $this->gatewayName(),
            gatewayRefundId: $this->requireString($adjustment, 'id'),
            reference: $request->reference,
            amount: new Money(
                (int) ($adjustment['totals']['total'] ?? $request->amount?->minorUnits ?? 0),
                (string) (
                    $adjustment['currency_code']
                    ?? $adjustment['totals']['currency_code']
                    ?? $request->amount?->currency
                    ?? 'USD'
                )
            ),
            // `pending_approval` in live, `approved` in sandbox. Kept verbatim: a refund's
            // lifecycle is not a transaction's, and this is the gateway's own word for it.
            status: (string) ($adjustment['status'] ?? 'unknown'),
            reason: $request->reason,
            raw: $adjustment,
        );
    }

    /**
     * @throws CheckoutException unless exactly one checkout mode is configured.
     */
    private function assertCheckoutMode(): void
    {
        $configured = ($this->hostedCheckoutUrl !== null ? 1 : 0) + ($this->paymentPageUrl !== null ? 1 : 0);

        if ($configured === 1) {
            return;
        }

        $adapter = substr(static::class, (int) strrpos(static::class, '\\') + 1);

        throw new CheckoutException($configured === 0
            ? sprintf(
                'This %s was constructed without a checkout URL, so there is nowhere to send the customer. '
                    . 'Pass either $hostedCheckoutUrl — the link copied from Paddle > Checkout > Hosted '
                    . 'checkout — or $paymentPageUrl, your own approved page running Paddle.js.',
                $adapter
            )
            : sprintf(
                'This %s was constructed with both $hostedCheckoutUrl and $paymentPageUrl. A customer goes '
                    . 'to one checkout or the other, so pass exactly one.',
                $adapter
            ));
    }

    /**
     * Where to send the customer, which is the one place the two modes genuinely differ.
     *
     * A hosted checkout link is Paddle's own page, so it needs only the transaction to open
     * on — but its post-payment redirect is configured on the link itself, which is why
     * successUrl and cancelUrl travel in custom_data in that mode rather than being honoured
     * here. Your own page, by contrast, is yours to route: Paddle returns the link to it as
     * `checkout.url`, and this appends the two URLs for your Paddle.js call to pass on.
     *
     * @param array<string, mixed> $transaction
     */
    private function redirectUrlFor(array $transaction, CheckoutRequest $request): string
    {
        $reference = $this->requireString($transaction, 'id');

        if ($this->hostedCheckoutUrl !== null) {
            return self::withQuery($this->hostedCheckoutUrl, [
                'transaction_id' => $reference,
                'user_email' => $request->customerEmail,
            ]);
        }

        $checkoutUrl = $transaction['checkout']['url'] ?? null;
        $base = is_string($checkoutUrl) && $checkoutUrl !== ''
            ? $checkoutUrl
            : self::withQuery((string) $this->paymentPageUrl, ['_ptxn' => $reference]);

        return self::withQuery($base, [
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'user_email' => $request->customerEmail,
        ]);
    }

    /**
     * A copied hosted-checkout link may already carry defaults of its own, so parameters are
     * appended to whatever query string is there rather than replacing it.
     *
     * @param array<string, string|null> $params Null values are omitted.
     */
    private static function withQuery(string $url, array $params): string
    {
        $params = array_filter($params, static fn (?string $value): bool => $value !== null && $value !== '');

        if ($params === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * There are two honest ways to tell Paddle what is being sold, and an adapter is
     * constructed for one of them.
     *
     * **Inline** (the default) sends non-catalog prices and products — created inline, used
     * once, and never added to the merchant's Paddle catalogue. That is what keeps a Monad
     * application from having to seed a catalogue before it can take a payment, and it means
     * CheckoutRequest is the whole description of the sale.
     *
     * **Catalogue** sends a `price_id` the merchant already maintains in the Paddle
     * dashboard, and nothing else — no name, no amount, no currency, no billing cycle, no
     * tax category. Every one of those lives on the price, which is the point: an
     * application that bills published plans should not restate their prices in its own
     * code, because the two then disagree the first time one of them changes. It is the
     * one-time and subscription counterpart of `SubscriptionItem::catalogPrice()`, which
     * has always been the plan-change path's way of saying the same thing.
     *
     * **In inline mode a `billing_cycle` is what separates the two adapters**: its **absence**
     * makes a price one-time, and its presence makes the transaction create a subscription once
     * the customer pays. PaddleCheckout passes neither argument and so can never make one by
     * accident; PaddleSubscription passes its configured plan. In catalogue mode the price
     * carries its own cycle, so neither argument is read and neither adapter can override it.
     *
     * **That last clause cuts both ways, and it is a caller's responsibility rather than a
     * check this code can make.** A recurring `pri_...` given to PaddleCheckout produces a real
     * subscription, on an adapter with no cancel(), pause() or changePlan() to manage it, and
     * whose `past_due` means a dead payment rather than the dunning 1.4.0 §2.6 established it
     * means for a subscription. Nothing here can refuse it: a price id does not say whether it
     * recurs, and only Paddle knows. Give a recurring price to PaddleSubscription.
     *
     * @return list<array<string, mixed>>
     */
    private function itemParams(
        CheckoutRequest $request,
        ?BillingCycle $billingCycle = null,
        ?BillingCycle $trialPeriod = null
    ): array {
        if ($this->catalogPriceId !== null) {
            return [$this->catalogItemParam($request)];
        }

        if ($request->lineItems === []) {
            return [$this->itemParam($request->reference, $request->amount, 1, $billingCycle, $trialPeriod)];
        }

        return array_map(
            fn ($item): array => $this->itemParam(
                $item->description,
                $item->unitPrice,
                $item->quantity,
                $billingCycle,
                $trialPeriod
            ),
            $request->lineItems
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function itemParam(
        string $description,
        Money $unitPrice,
        int $quantity,
        ?BillingCycle $billingCycle = null,
        ?BillingCycle $trialPeriod = null
    ): array {
        $price = [
            'name' => $description,
            'description' => $description,
            'unit_price' => [
                'amount' => (string) $unitPrice->minorUnits,
                'currency_code' => $unitPrice->currency,
            ],
            'product' => [
                'name' => $description,
                'tax_category' => $this->taxCategory,
            ],
        ];

        // Omitted entirely rather than sent as null: Paddle reads an absent billing_cycle as
        // "one-time", and a present-but-null one is not the same statement.
        if ($billingCycle !== null) {
            $price['billing_cycle'] = self::cycleParams($billingCycle);
        }

        if ($trialPeriod !== null) {
            $price['trial_period'] = self::cycleParams($trialPeriod);
        }

        return ['quantity' => $quantity, 'price' => $price];
    }

    /**
     * A catalogue price is named and nothing more.
     *
     * Quantity is fixed at 1 — deliberately, and this is the boundary of the feature rather
     * than an oversight. Quantity is per-checkout data, and `CheckoutRequest` is semver-frozen
     * with nowhere to carry it (`ReleaseNotes_1.4.0.md` §2.4). Reading it off `$lineItems`
     * would be worse than not supporting it: a line item exists to state a unit price, so a
     * caller would be restating the catalogue's price to communicate a number that has nothing
     * to do with it — the exact duplication catalogue mode exists to remove. A seat count is
     * set after the fact through `changePlan()` with
     * `SubscriptionItem::catalogPrice($priceId, $quantity)`, which has carried a quantity since
     * 1.4.0.
     *
     * @return array<string, mixed>
     * @throws CheckoutException if the request also carries line items.
     */
    private function catalogItemParam(CheckoutRequest $request): array
    {
        if ($request->lineItems !== []) {
            throw new CheckoutException(sprintf(
                'This %s was constructed with the catalogue price %s, so the Paddle catalogue states what '
                    . 'this sale costs — but the CheckoutRequest also carries %d line item(s) naming their own '
                    . 'prices. Those are two answers to one question, and honouring either would silently '
                    . 'discard the other. Pass a request without line items, or construct the adapter without '
                    . 'a catalogue price to bill the items inline.',
                substr(static::class, (int) strrpos(static::class, '\\') + 1),
                $this->catalogPriceId,
                count($request->lineItems)
            ));
        }

        return ['price_id' => $this->catalogPriceId, 'quantity' => 1];
    }

    /**
     * The transaction's currency — sent in inline mode, omitted in catalogue mode.
     *
     * Omitted rather than passed through, because Paddle does **not** refuse a `currency_code`
     * that disagrees with the catalogue price's own. Confirmed against the live sandbox: a
     * USD price of 4900 sent with `currency_code: EUR` was accepted and silently converted to
     * 4218 EUR, and to 7668 JPY. So passing `CheckoutRequest::$amount`'s currency through
     * would let a caller re-denominate the merchant's published price at Paddle's rate, and
     * the wrong currency would surface as a wrong charge rather than as an error. With the
     * field absent Paddle bills the price exactly as published — confirmed the same way.
     *
     * @return array<string, string>
     */
    private function currencyParams(CheckoutRequest $request): array
    {
        return $this->catalogPriceId !== null ? [] : ['currency_code' => $request->amount->currency];
    }

    /**
     * Paddle price ids are `pri_`-prefixed. The check exists for one specific mistake: the
     * Paddle dashboard shows a product's id beside its prices, and a `pro_` passed here would
     * otherwise fail at the API with a message about an entity that cannot be found, naming an
     * id the merchant can see plainly exists.
     *
     * @throws CheckoutException if $catalogPriceId is present but is not a price id.
     */
    private static function assertCatalogPriceId(?string $catalogPriceId): void
    {
        if ($catalogPriceId === null || str_starts_with($catalogPriceId, 'pri_')) {
            return;
        }

        throw new CheckoutException(str_starts_with($catalogPriceId, 'pro_')
            ? sprintf(
                '"%s" is a Paddle product id, not a price id. A product is the thing being sold and a price '
                    . 'is what it costs on one billing cycle, so a checkout needs the price — the pri_... '
                    . 'shown beneath the product in Paddle > Catalog > Products.',
                $catalogPriceId
            )
            : sprintf('A catalogue checkout needs a Paddle price id (pri_...), got "%s".', $catalogPriceId));
    }

    /**
     * @return array{interval: string, frequency: int}
     */
    private static function cycleParams(BillingCycle $cycle): array
    {
        return ['interval' => $cycle->interval->value, 'frequency' => $cycle->frequency];
    }

    /**
     * custom_data is echoed back on every transaction.* webhook, which makes it the one
     * place a merchant reference can be written that reconciliation is guaranteed to see.
     * The adapter's own keys are merged last so a metadata key of the same name cannot
     * quietly displace the reference a payment is reconciled by.
     *
     * @return array<string, scalar>
     */
    private function customData(CheckoutRequest $request): array
    {
        return array_merge($request->metadata, [
            'reference' => $request->reference,
            'idempotency_key' => $request->idempotencyKey(),
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
        ]);
    }

    /**
     * Paddle refunds line items, not transactions, so a partial refund has to say which
     * items it comes off. The requested amount is filled against each item in order until
     * it is exhausted — which for the common single-item checkout is a straight
     * pass-through, and for several items spends the earlier ones first.
     *
     * @return list<array<string, string>>
     * @throws CheckoutException if the refund exceeds what is left to refund.
     */
    private function refundItems(RefundRequest $request): array
    {
        /** @var Money $amount */
        $amount = $request->amount;
        $outstanding = $amount->minorUnits;
        $items = [];

        foreach ($this->remainingByItem($request, $amount) as $itemId => $available) {
            if ($outstanding === 0) {
                break;
            }

            if ($available <= 0) {
                continue;
            }

            $take = min($available, $outstanding);
            $items[] = ['item_id' => $itemId, 'type' => 'partial', 'amount' => (string) $take];
            $outstanding -= $take;
        }

        if ($outstanding > 0) {
            throw new CheckoutException(sprintf(
                'Refunding %s from transaction %s would exceed what is left to refund by %d minor units — '
                . 'previous refunds have already been taken off it.',
                $amount->describe(),
                $request->reference,
                $outstanding
            ));
        }

        return $items;
    }

    /**
     * What each line item still has left to refund: its own total, less every refund already
     * taken off it. Reading the transaction alone would ignore prior refunds and produce an
     * allocation Paddle rejects the moment a second partial refund is issued.
     *
     * @return array<string, int> Item id to remaining minor units, in transaction order.
     * @throws CheckoutException if the transaction is in another currency.
     */
    private function remainingByItem(RefundRequest $request, Money $amount): array
    {
        $transaction = $this->send(
            'GET',
            '/transactions/' . rawurlencode($request->reference),
            [],
            $request->timeoutSeconds
        );

        $currency = (string) ($transaction['currency_code'] ?? '');

        if ($currency !== $amount->currency) {
            throw new CheckoutException(sprintf(
                'Cannot refund %s from transaction %s, which was taken in %s.',
                $amount->describe(),
                $request->reference,
                $currency === '' ? 'an unstated currency' : $currency
            ));
        }

        $remaining = [];
        $lineItems = $transaction['details']['line_items'] ?? [];

        foreach (is_array($lineItems) ? $lineItems : [] as $lineItem) {
            if (!is_array($lineItem) || !isset($lineItem['id'])) {
                continue;
            }

            $remaining[(string) $lineItem['id']] = (int) ($lineItem['totals']['total'] ?? 0);
        }

        if ($remaining === []) {
            throw new CheckoutException(sprintf(
                'Transaction %s carried no line items to refund against.',
                $request->reference
            ));
        }

        foreach ($this->refundedByItem($request) as $itemId => $refunded) {
            if (isset($remaining[$itemId])) {
                $remaining[$itemId] -= $refunded;
            }
        }

        return $remaining;
    }

    /**
     * How much has already been refunded per line item. Rejected and reversed adjustments
     * are skipped — the money came back, so it is refundable again.
     *
     * @return array<string, int>
     */
    private function refundedByItem(RefundRequest $request): array
    {
        $adjustments = $this->paginate(
            '/adjustments',
            ['transaction_id' => $request->reference],
            $request->timeoutSeconds
        );

        $refunded = [];

        foreach ($adjustments as $adjustment) {
            if (!is_array($adjustment) || (string) ($adjustment['action'] ?? '') !== 'refund') {
                continue;
            }

            if (in_array((string) ($adjustment['status'] ?? ''), ['rejected', 'reversed'], true)) {
                continue;
            }

            $items = $adjustment['items'] ?? [];

            foreach (is_array($items) ? $items : [] as $item) {
                if (!is_array($item) || !isset($item['item_id'])) {
                    continue;
                }

                $itemId = (string) $item['item_id'];
                $refunded[$itemId] = ($refunded[$itemId] ?? 0)
                    + (int) ($item['amount'] ?? $item['totals']['total'] ?? 0);
            }
        }

        return $refunded;
    }

    /**
     * Paddle's signature scheme: `Paddle-Signature: ts=<unix>;h1=<hex hmac>`, where the
     * signed payload is `<ts>:<raw body>`. Several h1 values can appear while a destination's
     * secret is being rotated, so any one matching is a pass.
     *
     * @param array<string, string> $headers
     * @throws CheckoutException if the header is absent or malformed, the timestamp is
     *         outside tolerance, or no signature matches.
     */
    private function verifySignature(string $rawBody, array $headers): void
    {
        if ($this->webhookSecret === '') {
            throw new CheckoutException(
                'This PaddleCheckout was constructed without a webhook signing secret, so callbacks cannot be '
                . 'verified. Pass the notification destination\'s pdl_ntfset_... secret to accept callbacks.'
            );
        }

        $header = self::header($headers, 'Paddle-Signature');

        if ($header === null || trim($header) === '') {
            throw new CheckoutException('Paddle callback carried no Paddle-Signature header.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(';', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 'ts') {
                $timestamp = $value;
            } elseif ($key === 'h1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
            throw new CheckoutException(sprintf('Paddle-Signature header was malformed: "%s".', $header));
        }

        $age = time() - (int) $timestamp;

        if (abs($age) > self::SIGNATURE_TOLERANCE_SECONDS) {
            throw new CheckoutException(sprintf(
                'Paddle callback timestamp is %d seconds outside the %d-second tolerance — refusing it as a replay.',
                abs($age) - self::SIGNATURE_TOLERANCE_SECONDS,
                self::SIGNATURE_TOLERANCE_SECONDS
            ));
        }

        $expected = HMAC::sign($timestamp . ':' . $rawBody, $this->webhookSecret);

        foreach ($signatures as $candidate) {
            if (ConstantTime::equals($expected, $candidate)) {
                return;
            }
        }

        throw new CheckoutException(
            'Paddle callback signature did not verify against this destination\'s signing secret.'
        );
    }

    /**
     * A Paddle transaction records every payment attempt against it, so the most recent one
     * carrying an error code is why the transaction failed.
     *
     * @param array<string, mixed> $transaction
     */
    private function failureReasonOf(array $transaction, ?string $eventType = null): ?string
    {
        $payments = $transaction['payments'] ?? [];

        foreach (array_reverse(is_array($payments) ? $payments : []) as $payment) {
            $errorCode = is_array($payment) ? ($payment['error_code'] ?? null) : null;

            if (is_string($errorCode) && $errorCode !== '') {
                return $errorCode;
            }
        }

        // Better the gateway's own event name than nothing: the failure_reason column
        // exists so an operator can tell why a payment failed without opening a dashboard.
        return $eventType;
    }

    /**
     * What `CheckoutSession::$amount` may fall back to when Paddle's response carries no total.
     *
     * In inline mode that is the request's own amount, which is the truth: the caller stated it
     * and the adapter sent it. **In catalogue mode there is no fallback**, because the request's
     * amount is inert there (`ReleaseNotes_1.7.0.md` §2.3) — the documented idiom is
     * `Money(0, $currency)`, so falling back to it would report a fabricated zero, in a currency
     * the catalogue price need not even be denominated in, as though it were the sum charged.
     * A missing total is a broken response, and the exception amountOf() raises for one is the
     * honest answer. 1.7.0 shipped the fallback on both paths; that was the defect this fixes.
     */
    private function amountFallback(CheckoutRequest $request): ?Money
    {
        return $this->catalogPriceId !== null ? null : $request->amount;
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function amountOf(array $transaction, ?Money $fallback = null): Money
    {
        $amount = $transaction['details']['totals']['grand_total'] ?? null;
        $currency = $transaction['currency_code'] ?? null;

        if (!is_numeric($amount) || !is_string($currency) || $currency === '') {
            return $fallback ?? throw new CheckoutException(
                'Paddle transaction carried no details.totals.grand_total/currency_code to report the '
                . 'transaction amount from.'
            );
        }

        return new Money((int) $amount, $currency);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws CheckoutException if $key is missing or empty.
     */
    private function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new CheckoutException(sprintf('Paddle response was missing the expected "%s" field.', $key));
        }

        return $value;
    }

    /**
     * Every Paddle response wraps its payload in a `data` envelope beside a `meta` block of
     * request ids and pagination. Unwrapping it here is what lets the rest of this class
     * read an entity's own fields rather than reaching through an envelope each time.
     *
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    private function send(string $method, string $path, array $params, int $timeoutSeconds): array
    {
        $body = $this->sendRaw($method, $path, $params, $timeoutSeconds);
        $data = $body['data'] ?? null;

        return is_array($data) ? $data : $body;
    }

    /**
     * Every page of a list endpoint, concatenated.
     *
     * Paddle paginates lists and its default page is small — ten for `/adjustments`. Reading
     * only the first page would make refundedByItem() under-count what has already been
     * refunded on a transaction with many partial refunds, and the over-refund guard would
     * then wave through the very thing it exists to stop. Found against the live sandbox: the
     * mocked suite cannot see a page size it never returns.
     *
     * @param array<string, mixed> $params
     * @return list<mixed>
     */
    private function paginate(string $path, array $params, int $timeoutSeconds): array
    {
        $params['per_page'] = self::MAX_PER_PAGE;
        $rows = [];

        do {
            $body = $this->sendRaw('GET', $path, $params, $timeoutSeconds);
            $page = is_array($body['data'] ?? null) ? array_values($body['data']) : [];

            foreach ($page as $row) {
                $rows[] = $row;
            }

            $last = end($page);
            $cursor = is_array($last) && isset($last['id']) ? (string) $last['id'] : null;
            $params['after'] = $cursor;
        } while (($body['meta']['pagination']['has_more'] ?? false) === true && $cursor !== null);

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> The whole response body, envelope included.
     */
    private function sendRaw(string $method, string $path, array $params, int $timeoutSeconds): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Paddle-Version' => self::API_VERSION,
        ];

        $client = $this->httpClient->withTimeoutSeconds($timeoutSeconds);
        $uri = $this->baseUri . $path;

        // PATCH is hand-encoded rather than reaching for a patchJson() on HttpClient: that
        // would widen a shipped service's public surface to save one json_encode, and this
        // method is already the single place every Paddle request passes through.
        $response = match ($method) {
            'GET' => $client->get($params === [] ? $uri : $uri . '?' . http_build_query($params), $headers),
            'PATCH' => $client->patch(
                $uri,
                json_encode($params, JSON_THROW_ON_ERROR),
                $headers + ['Content-Type' => 'application/json']
            ),
            default => $client->postJson($uri, $params, $headers),
        };

        $this->assertSuccessful($response);

        return $this->decodeJsonBody($response);
    }
}
