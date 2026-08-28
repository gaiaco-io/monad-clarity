<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\CheckoutAdapters;

use Monad\Clarity\Services\Checkout;
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
use Monad\Clarity\Utils\ConstantTime;
use Monad\Clarity\Utils\HMAC;

/**
 * Stripe Checkout adapter — the hosted payment page, via the Checkout Sessions API
 * (`/v1/checkout/sessions`), with refunds through `/v1/refunds` and callbacks through
 * Stripe's signed webhook scheme.
 *
 * Stripe's REST API is form-encoded, not JSON, so requests go out through HttpClient::post()
 * with a `http_build_query` body rather than postJson(). Nested parameters
 * (`line_items[0][price_data][currency]`) are exactly what http_build_query emits for a
 * nested array, so the shape below reads the way Stripe's own documentation does.
 *
 * Stripe expects amounts in the currency's smallest unit and currency codes in lower case —
 * Money already holds the former, and the latter is applied at the wire boundary only, so
 * nothing above this class has to remember Stripe's casing.
 *
 * @package Monad\Clarity\Services\CheckoutAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class StripeCheckout extends Checkout
{
    private const DEFAULT_BASE_URI = 'https://api.stripe.com/v1';
    private const API_VERSION = '2024-06-20';
    private const GATEWAY = 'stripe_checkout';

    /**
     * Stripe's documented replay window for webhook signatures. A callback whose timestamp
     * falls outside it is refused even when the signature itself is valid — otherwise a
     * captured request stays replayable forever.
     */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    /**
     * The only three values Stripe's `reason` field accepts. Anything else is preserved as
     * metadata rather than dropped or sent through to be rejected.
     */
    private const REFUND_REASONS = ['duplicate', 'fraudulent', 'requested_by_customer'];

    /**
     * @param string $apiKey Stripe secret key (`sk_...`).
     * @param string $webhookSecret Signing secret for this endpoint (`whsec_...`), issued
     *        per webhook endpoint and distinct from the API key. Empty disables
     *        parseCallback() with an explicit error rather than silently accepting
     *        unverified callbacks.
     */
    public function __construct(
        string $apiKey,
        HttpClient $httpClient,
        private readonly string $webhookSecret = '',
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        parent::__construct($apiKey, $httpClient);
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $params = [
            'mode' => 'payment',
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => $request->reference,
            'line_items' => $this->lineItemParams($request),
        ];

        if ($request->customerEmail !== null) {
            $params['customer_email'] = $request->customerEmail;
        }

        if ($request->metadata !== []) {
            $params['metadata'] = $request->metadata;
        }

        // Stripe reads the merchant reference back off the PaymentIntent during
        // reconciliation, and the session's client_reference_id does not propagate there.
        $params['payment_intent_data']['metadata']['reference'] = $request->reference;

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
            amount: $this->amountOf($session, $request->amount),
            paymentReference: $this->referenceOf($session['payment_intent'] ?? null),
            raw: $session,
        );
    }

    public function retrieveStatus(string $reference, int $timeoutSeconds = 30): TransactionSnapshot
    {
        // The PaymentIntent is expanded because a Checkout Session never reports a failure
        // on its own — `payment_status` only ever distinguishes paid from unpaid. Without
        // this, a payment that failed asynchronously would re-query as `pending` forever,
        // which defeats the point of re-query being the reconciliation path for a callback
        // that never arrived (§9.6.3 → §9.6.5).
        $session = $this->send(
            'GET',
            '/checkout/sessions/' . rawurlencode($reference),
            ['expand' => ['payment_intent']],
            $timeoutSeconds
        );
        $status = $this->mapSessionStatus($session);

        return new TransactionSnapshot(
            gateway: self::GATEWAY,
            gatewayReference: $this->requireString($session, 'id'),
            status: $status,
            amount: $this->amountOf($session),
            failureReason: $status === TransactionStatus::Failed ? $this->failureReasonOf($session) : null,
            paymentReference: $this->referenceOf($session['payment_intent'] ?? null),
            raw: $session,
        );
    }

    public function parseCallback(string $rawBody, array $headers): CallbackEvent
    {
        $this->verifySignature($rawBody, $headers);

        /** @var array<string, mixed> $event */
        $event = json_decode($rawBody, associative: true) ?: [];

        if (!isset($event['id'], $event['type'])) {
            throw new CheckoutException('Stripe webhook payload verified but carried no event id or type.');
        }

        $type = (string) $event['type'];
        $object = $event['data']['object'] ?? [];

        if (!is_array($object)) {
            throw new CheckoutException(sprintf('Stripe webhook "%s" carried no event object.', $type));
        }

        $status = match ($type) {
            'checkout.session.async_payment_succeeded' => TransactionStatus::Success,
            'checkout.session.async_payment_failed' => TransactionStatus::Failed,
            'checkout.session.expired' => TransactionStatus::Cancelled,
            default => $this->mapSessionStatus($object),
        };

        return new CallbackEvent(
            gateway: self::GATEWAY,
            eventId: (string) $event['id'],
            eventType: $type,
            gatewayReference: $this->requireString($object, 'id'),
            status: $status,
            failureReason: $status === TransactionStatus::Failed ? $this->failureReasonOf($object, $type) : null,
            paymentReference: $this->referenceOf($object['payment_intent'] ?? null),
            raw: $event,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $paymentIntent = $this->resolvePaymentIntent($request);
        $params = ['payment_intent' => $paymentIntent];

        if ($request->amount !== null) {
            $params['amount'] = $request->amount->minorUnits;
        }

        if ($request->reason !== null) {
            // Stripe rejects a reason outside its own enum. A merchant's free-text reason
            // is worth keeping either way, so it rides along as metadata instead of
            // failing the refund over a vocabulary mismatch.
            if (in_array($request->reason, self::REFUND_REASONS, true)) {
                $params['reason'] = $request->reason;
            } else {
                $params['metadata']['reason'] = $request->reason;
            }
        }

        $refund = $this->send('POST', '/refunds', $params, $request->timeoutSeconds, $request->idempotencyKey());

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
     * Stripe refunds act on a PaymentIntent, never on a Checkout Session. A merchant who
     * kept only the session id would otherwise have to know that and make the extra call
     * themselves, so a `cs_` reference is resolved here — one additional GET, and only when
     * the reference is actually a session.
     */
    private function resolvePaymentIntent(RefundRequest $request): string
    {
        if (!str_starts_with($request->reference, 'cs_')) {
            return $request->reference;
        }

        $session = $this->send(
            'GET',
            '/checkout/sessions/' . rawurlencode($request->reference),
            [],
            $request->timeoutSeconds
        );

        $paymentIntent = $this->referenceOf($session['payment_intent'] ?? null);

        if ($paymentIntent === null) {
            throw new CheckoutException(sprintf(
                'Checkout session %s has no payment to refund — it was never completed.',
                $request->reference
            ));
        }

        return $paymentIntent;
    }

    /**
     * Stripe's signature scheme: `Stripe-Signature: t=<unix>,v1=<hex hmac>`, where the
     * signed payload is `<t>.<raw body>`. Multiple v1 signatures appear while a signing
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
                'This StripeCheckout was constructed without a webhook signing secret, so callbacks cannot be '
                . 'verified. Pass the endpoint\'s whsec_... secret to accept callbacks.'
            );
        }

        $header = self::header($headers, 'Stripe-Signature');

        if ($header === null || trim($header) === '') {
            throw new CheckoutException('Stripe callback carried no Stripe-Signature header.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
            throw new CheckoutException(sprintf('Stripe-Signature header was malformed: "%s".', $header));
        }

        $age = time() - (int) $timestamp;

        if (abs($age) > self::SIGNATURE_TOLERANCE_SECONDS) {
            throw new CheckoutException(sprintf(
                'Stripe callback timestamp is %d seconds outside the %d-second tolerance — refusing it as a replay.',
                abs($age) - self::SIGNATURE_TOLERANCE_SECONDS,
                self::SIGNATURE_TOLERANCE_SECONDS
            ));
        }

        $expected = HMAC::sign($timestamp . '.' . $rawBody, $this->webhookSecret);

        foreach ($signatures as $candidate) {
            if (ConstantTime::equals($expected, $candidate)) {
                return;
            }
        }

        throw new CheckoutException('Stripe callback signature did not verify against this endpoint\'s signing secret.');
    }

    /**
     * Stripe charges the sum of the line items it is given, so when a caller supplies none
     * a single line standing for the whole amount is synthesised — the customer still sees
     * an itemised page, and the charge still matches CheckoutRequest::$amount exactly.
     *
     * @return list<array<string, mixed>>
     */
    private function lineItemParams(CheckoutRequest $request): array
    {
        if ($request->lineItems === []) {
            return [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->amount->currency),
                    'unit_amount' => $request->amount->minorUnits,
                    'product_data' => ['name' => $request->reference],
                ],
            ]];
        }

        return array_map(
            static fn ($item): array => [
                'quantity' => $item->quantity,
                'price_data' => [
                    'currency' => strtolower($item->unitPrice->currency),
                    'unit_amount' => $item->unitPrice->minorUnits,
                    'product_data' => ['name' => $item->description],
                ],
            ],
            $request->lineItems
        );
    }

    /**
     * A Checkout Session's state is two fields, not one: `status` covers the page's
     * lifecycle and `payment_status` the money. A session can be `complete` while payment
     * is still `unpaid` — delayed methods settle hours later — and calling that a success
     * would release goods against a payment that has not arrived.
     *
     * @param array<string, mixed> $session
     */
    private function mapSessionStatus(array $session): TransactionStatus
    {
        $paymentStatus = isset($session['payment_status']) ? (string) $session['payment_status'] : null;

        if ($paymentStatus === 'paid' || $paymentStatus === 'no_payment_required') {
            return TransactionStatus::Success;
        }

        // A failure is only ever visible on the PaymentIntent, and only when it has been
        // expanded — retrieveStatus() asks for it; a webhook payload carries a bare id, and
        // that path reads the failure from the event type instead.
        $intent = $session['payment_intent'] ?? null;

        if (is_array($intent)) {
            if ((string) ($intent['status'] ?? '') === 'canceled') {
                return TransactionStatus::Cancelled;
            }

            if (isset($intent['last_payment_error'])) {
                return TransactionStatus::Failed;
            }
        }

        return match (isset($session['status']) ? (string) $session['status'] : null) {
            'expired' => TransactionStatus::Cancelled,
            default => TransactionStatus::Pending,
        };
    }

    /**
     * @param array<string, mixed> $object
     */
    private function failureReasonOf(array $object, ?string $eventType = null): ?string
    {
        $intent = $object['payment_intent'] ?? null;

        foreach ([$object, is_array($intent) ? $intent : []] as $candidate) {
            $error = $candidate['last_payment_error'] ?? null;

            if (is_array($error) && isset($error['message'])) {
                return (string) $error['message'];
            }
        }

        // Better the gateway's own event name than nothing: the failure_reason column
        // exists so an operator can tell why a payment failed without opening a dashboard.
        return $eventType;
    }

    /**
     * Stripe returns a related object either as a bare id or, when expanded, as a nested
     * object. Both shapes reduce to the id.
     */
    private function referenceOf(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && isset($value['id'])) {
            return (string) $value['id'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function amountOf(array $session, ?Money $fallback = null): Money
    {
        $amount = $session['amount_total'] ?? null;
        $currency = $session['currency'] ?? null;

        if (!is_numeric($amount) || !is_string($currency) || $currency === '') {
            return $fallback ?? throw new CheckoutException(
                'Stripe session carried no amount_total/currency to report the transaction amount from.'
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
            throw new CheckoutException(sprintf('Stripe response was missing the expected "%s" field.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        array $params,
        int $timeoutSeconds,
        ?string $idempotencyKey = null,
    ): array {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Stripe-Version' => self::API_VERSION,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $client = $this->httpClient->withTimeoutSeconds($timeoutSeconds);
        $uri = $this->baseUri . $path;

        if ($method === 'GET') {
            $response = $client->get($params === [] ? $uri : $uri . '?' . http_build_query($params), $headers);
        } else {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $response = $client->post($uri, http_build_query($params), $headers);
        }

        $this->assertSuccessful($response);

        return $this->decodeJsonBody($response);
    }
}
