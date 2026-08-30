<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use DateTimeImmutable;

/**
 * A verified, normalised subscription callback — the recurring counterpart to CallbackEvent,
 * and bound by the same doctrine: an instance only ever exists for a callback whose signature
 * has already been verified. Adapters throw rather than return an unverified event, so there
 * is no such thing as a SubscriptionEvent you still need to check. Construction is the proof.
 *
 * It **composes** a SubscriptionSnapshot rather than restating its dozen fields. A gateway's
 * webhook payload and its subscription-retrieval response describe the same entity, so one
 * parser produces both and the two can never drift into disagreeing about what `paused`
 * means.
 *
 * $occurredAt is the gateway's own timestamp for when this happened, and it is load-bearing
 * rather than decorative. A subscription is one mutable row, so unlike a transaction's
 * insert-only status history it has no unique index to make redelivery harmless. Ordering is
 * what protects it instead: an event older than the state already stored is discarded rather
 * than applied. See Checkout\SubscriptionLedger.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class SubscriptionEvent
{
    /**
     * @param string $eventId The gateway's own id for this delivery.
     * @param string $eventType The gateway's own event name, kept verbatim for auditing.
     * @param DateTimeImmutable $occurredAt When the gateway says this happened — always UTC.
     * @param array<string, mixed> $raw The whole delivery, envelope included.
     */
    public function __construct(
        public string $gateway,
        public string $eventId,
        public string $eventType,
        public DateTimeImmutable $occurredAt,
        public SubscriptionSnapshot $subscription,
        public array $raw = [],
    ) {
    }
}
