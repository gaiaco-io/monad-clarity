<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * A refund as the gateway accepted it (ReleaseNotes §9.6.6).
 *
 * $status is the gateway's own refund state, kept verbatim rather than mapped onto
 * TransactionStatus: a refund's lifecycle is not a transaction's, and forcing it through
 * the four-case enum would claim a correspondence that does not exist. The transaction
 * itself stays `success` — refunds accumulate against it as their own records.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class RefundResult
{
    /**
     * @param string $gatewayRefundId The gateway's id for this refund, unique per refund
     *        and therefore the ledger's guard against recording one twice.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $gateway,
        public string $gatewayRefundId,
        public string $reference,
        public Money $amount,
        public string $status,
        public ?string $reason = null,
        public array $raw = [],
    ) {
    }
}
