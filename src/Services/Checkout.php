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
                (string) $response->getBody()
            ));
        }
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
