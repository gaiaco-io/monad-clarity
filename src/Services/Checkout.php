<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use Monad\Clarity\Services\Checkout\CallbackEvent;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\CheckoutSession;
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Services\Checkout\RefundResult;
use Monad\Clarity\Services\Checkout\TransactionSnapshot;
use Monad\Clarity\Utils\Redactor;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared contract every payment gateway adapter (`Services\CheckoutAdapters\*`) implements
 * — four operations covering the gateway-facing half of ReleaseNotes §9.6:
 *
 *   createCheckout()  §9.6.1  begin a checkout / authorisation
 *   retrieveStatus()  §9.6.3  re-query a transaction's state
 *   parseCallback()   §9.6.4  verify and normalise a gateway callback
 *   refund()          §9.6.6  refund all or part of a settled transaction
 *
 * Each takes and returns provider-agnostic value objects from `Services\Checkout\*`, so
 * application code that switches gateways changes one constructor call and nothing else.
 *
 * The stateful half of §9.6 — transaction records (§9.6.2), status updates (§9.6.5), and
 * the insert-only status ledger (§9.6.8) — deliberately lives in `Checkout\TransactionLedger`,
 * not here. Architecture.md §7 defines this file as "a thin facade defining the shared
 * contract", and an adapter that also owned a database connection would be two things at
 * once: nine gateway integrations would each carry a copy of identical persistence logic,
 * and neither half could be tested without the other. The ledger is gateway-agnostic and
 * the adapters are stateless; the application composes them.
 *
 * As with LLM, there is no registry or provider-name dispatch — an adapter is constructed
 * directly with its own credentials and an HttpClient.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class Checkout
{
    public function __construct(
        protected readonly string $apiKey,
        protected readonly HttpClient $httpClient,
    ) {
    }

    /**
     * Begin a checkout. For a gateway-hosted page the returned session carries the URL to
     * redirect the customer to; for a custom page it carries the handle the merchant's own
     * payment form submits against (§9.5).
     *
     * @throws CheckoutException if the gateway rejects the request.
     */
    abstract public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Re-query a transaction's current state directly from the gateway — the
     * reconciliation path for when a callback never arrived.
     *
     * @throws CheckoutException if the gateway rejects the request or the reference is unknown.
     */
    abstract public function retrieveStatus(string $reference, int $timeoutSeconds = 30): TransactionSnapshot;

    /**
     * Verify a callback's authenticity and normalise it.
     *
     * $rawBody must be the request body byte for byte — `Request::rawBody()`, never a
     * re-encoded parse. Signatures are computed over exact bytes, so a body that has been
     * decoded and re-serialised will fail verification even when it is genuine, and (worse)
     * a scheme that tolerated it would be verifying something other than what was received.
     *
     * @param array<string, string> $headers Request headers, matched case-insensitively.
     * @throws CheckoutException if the signature is absent, malformed, stale, or does not verify.
     */
    abstract public function parseCallback(string $rawBody, array $headers): CallbackEvent;

    /**
     * Refund all or part of a settled transaction.
     *
     * @throws CheckoutException if the gateway rejects the refund.
     */
    abstract public function refund(RefundRequest $request): RefundResult;

    /**
     * The gateway identifier stamped onto every value object this adapter returns (e.g.
     * 'stripe_checkout') and used in this class's own error messages.
     */
    abstract protected function gatewayName(): string;

    /**
     * Case-insensitive header lookup — PSR-7, `$_SERVER`, and every gateway's own
     * documentation disagree on casing, and a callback rejected over a capital letter is
     * an outage that looks like a security failure.
     *
     * @param array<string, string> $headers
     */
    protected static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @throws CheckoutException if $response's status code isn't 2xx.
     */
    protected function assertSuccessful(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new CheckoutException(sprintf(
                '%s request failed with HTTP %d: %s',
                $this->gatewayName(),
                $status,
                self::summariseFailure((string) $response->getBody())
            ));
        }
    }

    /**
     * What a failed call may say about itself. Exception messages reach application logs, and
     * a declined Stripe card's body carries the whole PaymentIntent — `client_secret`, billing
     * details, card fingerprint — so the body is never embedded verbatim. A gateway's own
     * `error` object is reduced to its scalar fields (type, code, message, ...) and Paddle's
     * validation pairs and request id; anything nested beyond that is dropped. Bodies without
     * one are redacted and truncated.
     */
    private static function summariseFailure(string $body): string
    {
        $decoded = json_decode($body, associative: true);

        if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
            return self::summariseErrorObject($decoded);
        }

        if (is_array($decoded)) {
            $body = (string) json_encode(Redactor::redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return trim($body) === '' ? '(empty body)' : self::truncate($body, 500);
    }

    /**
     * `card_error (card_declined, generic_decline) — Your card was declined. [param=x] [request_id=y]`
     *
     * @param array<array-key, mixed> $decoded
     */
    private static function summariseErrorObject(array $decoded): string
    {
        $error = Redactor::redact(array_filter($decoded['error'], is_scalar(...)));
        $request = is_array($decoded['meta'] ?? null) ? Redactor::redact(array_filter($decoded['meta'], is_scalar(...))) : [];

        $text = static fn (string $key): ?string => isset($error[$key]) ? self::scalarText($error[$key]) : null;
        $codes = array_filter([$text('code'), $text('decline_code')], static fn (?string $c): bool => $c !== null && $c !== '');
        $sentence = $text('message') ?? $text('detail');

        $parts = [];
        $headline = trim(($text('type') ?? '') . ($codes === [] ? '' : ' (' . implode(', ', $codes) . ')'));
        if ($headline !== '') {
            $parts[] = $headline;
        }
        if ($sentence !== null && $sentence !== '') {
            $parts[] = ($parts === [] ? '' : '— ') . $sentence;
        }

        foreach ($error as $key => $value) {
            if (!in_array($key, ['type', 'code', 'decline_code', 'message', 'detail'], true)) {
                $parts[] = sprintf('[%s=%s]', $key, self::scalarText($value));
            }
        }

        $problems = [];
        foreach (is_array($decoded['error']['errors'] ?? null) ? $decoded['error']['errors'] : [] as $problem) {
            $pair = is_array($problem) ? Redactor::redact(array_filter($problem, is_scalar(...))) : [];
            $line = implode(': ', array_filter([
                isset($pair['field']) ? self::scalarText($pair['field']) : null,
                isset($pair['message']) ? self::scalarText($pair['message']) : null,
            ]));
            if ($line !== '') {
                $problems[] = $line;
            }
        }
        if ($problems !== []) {
            $parts[] = '[' . implode('; ', $problems) . ']';
        }

        if (isset($request['request_id'])) {
            $parts[] = '[request_id=' . self::scalarText($request['request_id']) . ']';
        }

        return $parts === [] ? '(no error details)' : self::truncate(implode(' ', $parts), 1000);
    }

    /** One line, whatever the gateway put in it. */
    private static function scalarText(mixed $value): string
    {
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : '');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }

    /**
     * @return array<string, mixed>
     * @throws CheckoutException if the body isn't valid JSON, or isn't a JSON object.
     */
    protected function decodeJsonBody(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CheckoutException(
                sprintf('%s returned a response that was not valid JSON: %s', $this->gatewayName(), $e->getMessage()),
                previous: $e
            );
        }

        if (!is_array($decoded)) {
            throw new CheckoutException(sprintf('%s returned a JSON response whose top level was not an object.', $this->gatewayName()));
        }

        return $decoded;
    }
}
