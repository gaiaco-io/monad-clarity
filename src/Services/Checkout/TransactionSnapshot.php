<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * A transaction's state as the gateway reports it right now (ReleaseNotes §9.6.3).
 *
 * Re-query exists because callbacks are not guaranteed: they are dropped, delayed, or
 * arrive while the merchant's site is down. Re-querying is the authoritative reconciliation
 * path, and the ledger treats a snapshot exactly as it treats a callback.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class TransactionSnapshot
{
    /**
     * @param string|null $failureReason The gateway's stated reason, recorded verbatim on
     *        the immutable status row (§9.6.8.2). Null unless $status is Failed.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $gateway,
        public string $gatewayReference,
        public TransactionStatus $status,
        public Money $amount,
        public ?string $failureReason = null,
        public ?string $paymentReference = null,
        public array $raw = [],
    ) {
    }
}
