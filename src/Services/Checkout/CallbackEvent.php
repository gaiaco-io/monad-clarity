<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * A verified, normalised gateway callback (ReleaseNotes §9.6.4).
 *
 * An instance of this class only ever exists for a callback whose signature has already
 * been verified — adapters throw rather than return an unverified event, so there is no
 * such thing as a CallbackEvent you still need to check. Construction is the proof.
 *
 * $eventId is the gateway's own id for the delivery, and it is what makes callback
 * handling safe to retry: gateways redeliver aggressively, and every gateway in §9.3
 * will send the same event more than once. The ledger stores it under a unique index, so a
 * redelivery is recognised and ignored rather than appending a duplicate status row.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class CallbackEvent
{
    /**
     * @param string $eventType The gateway's own event name, kept verbatim for auditing.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $gateway,
        public string $eventId,
        public string $eventType,
        public string $gatewayReference,
        public TransactionStatus $status,
        public ?string $failureReason = null,
        public ?string $paymentReference = null,
        public array $raw = [],
    ) {
    }
}
