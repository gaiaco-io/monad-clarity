<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\CheckoutAdapters;

use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Utils\ConstantTime;
use Monad\Clarity\Utils\HMAC;
use Psr\Http\Message\ResponseInterface;

/**
 * Everything two Stripe adapters do identically: the form-encoded request, the signed-webhook
 * scheme, the wire shape of line items and refunds, and the small readers that turn a Stripe
 * object into a reference, an amount or a required field.
 *
 * Extracted rather than duplicated because the signature check is security-critical, and a
 * second copy of it is a second place for the replay window or the constant-time comparison
 * to drift out of step with the first. `SpeaksPaddle` is the precedent.
 *
 * **Usable only inside a `Services\Checkout` subclass** that also declares `API_VERSION`,
 * `$webhookSecret` and `$baseUri`. Each adapter pins its own `API_VERSION`, because the two
 * read different resources and one moving must not move the other. The trait reaches the base
 * class's `$apiKey`, `$httpClient`, `assertSuccessful()`, `decodeJsonBody()` and `header()`.
 *
 * Note what is deliberately absent: no reference to a gateway-name constant. Error messages
 * that name the adapter derive it from `static::class`, so a message never claims to come
 * from the sibling.
 *
 * @package Monad\Clarity\Services\CheckoutAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
trait SpeaksStripe
{
    private const DEFAULT_BASE_URI = 'https://api.stripe.com/v1';

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
                sprintf(
                    'This %s was constructed without a webhook signing secret, so callbacks cannot be '
                    . 'verified. Pass the endpoint\'s whsec_... secret to accept callbacks.',
                    substr(static::class, (int) strrpos(static::class, '\\') + 1)
                )
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
     * With `$recurring` given, every line is priced as a repeating charge on that cadence
     * — `price_data[recurring]` is how a subscription Checkout Session names its interval.
     *
     * @param array<string, mixed>|null $recurring
     * @return list<array<string, mixed>>
     */
    private function lineItemParams(CheckoutRequest $request, ?array $recurring = null): array
    {
        if ($request->lineItems === []) {
            $lines = [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->amount->currency),
                    'unit_amount' => $request->amount->minorUnits,
                    'product_data' => ['name' => $request->reference],
                ],
            ]];
        } else {
            $lines = array_map(
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

        if ($recurring === null) {
            return $lines;
        }

        return array_map(static function (array $line) use ($recurring): array {
            $line['price_data']['recurring'] = $recurring;

            return $line;
        }, $lines);
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

        // An id that is not a string is malformed, not an id: casting an array raises a warning
        // the framework turns into an ErrorException, so it reads as absent instead.
        if (is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '') {
            return $value['id'];
        }

        return null;
    }

    /**
     * A Checkout Session's total, `amount_total`. An invoice is not read here: StripeSubscription
     * reports an invoice by its amount due, which has rules of its own.
     *
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
        $response = $this->sendUnchecked($method, $path, $params, $timeoutSeconds, $idempotencyKey);

        $this->assertSuccessful($response);

        return $this->decodeJsonBody($response);
    }

    /**
     * Sends a request and hands back the response whatever its status, for a caller that must
     * tell one kind of refusal from another before `assertSuccessful()` flattens them all.
     *
     * @param array<string, mixed> $params
     */
    private function sendUnchecked(
        string $method,
        string $path,
        array $params,
        int $timeoutSeconds,
        ?string $idempotencyKey = null,
    ): ResponseInterface {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Stripe-Version' => self::API_VERSION,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $client = $this->httpClient->withTimeoutSeconds($timeoutSeconds);
        $uri = $this->baseUri . $path;

        if ($method === 'GET' || $method === 'DELETE') {
            // A DELETE carries no body and no Content-Type. Stripe reads its parameters, of which
            // an adapter should send none, from the query string exactly as it does for a GET.
            $uri = $params === [] ? $uri : $uri . '?' . http_build_query($params);

            return $method === 'GET' ? $client->get($uri, $headers) : $client->delete($uri, $headers);
        }

        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return $client->post($uri, http_build_query($params), $headers);
    }

    /**
     * Whether Stripe refused a request because its idempotency key had already been used with
     * different parameters: HTTP 400 and an `error.type` of `idempotency_error`. Read from the
     * structure, never the message, which is prose Stripe may reword. A body that is not JSON,
     * or not shaped so, is simply not this refusal.
     */
    private function isIdempotencyRefusal(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 400) {
            return false;
        }

        $body = json_decode((string) $response->getBody(), associative: true);

        return is_array($body)
            && is_array($body['error'] ?? null)
            && is_string($body['error']['type'] ?? null)
            && $body['error']['type'] === 'idempotency_error';
    }

    /**
     * The parameters of a refund against a PaymentIntent. Stripe rejects a reason outside its
     * own enum, and a merchant's free-text reason is worth keeping either way, so it rides
     * along as metadata instead of failing the refund over a vocabulary mismatch.
     *
     * @return array<string, mixed>
     */
    private function refundParams(RefundRequest $request, string $paymentIntent): array
    {
        $params = ['payment_intent' => $paymentIntent];

        if ($request->amount !== null) {
            $params['amount'] = $request->amount->minorUnits;
        }

        if ($request->reason !== null) {
            if (in_array($request->reason, self::REFUND_REASONS, true)) {
                $params['reason'] = $request->reason;
            } else {
                $params['metadata']['reason'] = $request->reason;
            }
        }

        return $params;
    }
}
