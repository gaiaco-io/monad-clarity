<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use DateTimeImmutable;

/**
 * A subscription's state as the gateway reports it right now — the recurring counterpart to
 * TransactionSnapshot.
 *
 * Read $status and $scheduledChange together, never $status alone. A customer who cancelled
 * yesterday is `Active` with a cancellation scheduled for the end of the period they have
 * paid for; a customer whose cancellation has taken effect is `Cancelled`. Those are
 * different facts, and the second is the one that ends access.
 *
 * This class deliberately offers **no** `grantsAccess()`. Whether a `PastDue` customer should
 * keep the product while the gateway retries their card is a product decision — most
 * merchants allow a few days and show a banner, some cut off at once, and both are defensible.
 * A framework method would settle it for everyone. What is offered instead is the factual
 * reading: the status, the pending change, and the date access would end on. The judgement
 * stays with the application:
 *
 *     $active = $snapshot->status === SubscriptionStatus::Active
 *         || $snapshot->status === SubscriptionStatus::Trialing;
 *
 * Almost everything here is nullable because gateways omit what does not apply — a paused
 * subscription has no current billing period, an imported one has no creating transaction,
 * and a subscription created outside this application has no merchant reference.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class SubscriptionSnapshot
{
    /**
     * @param string $gatewayReference The gateway's own id for this subscription, and the
     *        handle every subscription operation acts on.
     * @param string|null $reference The merchant's own reference, carried through from the
     *        transaction that created the subscription. Paddle does inherit `custom_data` this
     *        way — confirmed live, and undocumented on their side — so in practice this is
     *        populated. Still nullable, because a subscription created outside this application
     *        (an import, or one made in the gateway's dashboard) carries no reference of yours.
     * @param string|null $transactionReference The transaction this subscription was born
     *        from. A subscription is created by a payment, not by an API call, so this is the
     *        link back to the checkout the application already recorded. **Only ever populated
     *        from a callback**: confirmed live, Paddle publishes it on the
     *        `subscription.created` delivery but not on the subscription entity, so a snapshot
     *        read by retrieveSubscription() always carries null here.
     * @param Money|null $recurringAmount What the subscription charges each cycle — not a
     *        total, and not what any one transaction took.
     * @param array<string, mixed> $raw The gateway's undigested payload.
     */
    public function __construct(
        public string $gateway,
        public string $gatewayReference,
        public SubscriptionStatus $status,
        public ?ScheduledChange $scheduledChange = null,
        public ?string $reference = null,
        public ?string $transactionReference = null,
        public ?string $customerReference = null,
        public ?Money $recurringAmount = null,
        public ?BillingCycle $billingCycle = null,
        public ?DateTimeImmutable $nextBilledAt = null,
        public ?DateTimeImmutable $currentPeriodStartsAt = null,
        public ?DateTimeImmutable $currentPeriodEndsAt = null,
        public array $raw = [],
    ) {
    }

    /**
     * Whether this subscription is on its way out — the customer has cancelled but has not
     * run out of what they paid for yet.
     *
     * This is what a billing page shows ("your plan ends on the 12th"). It is explicitly not
     * an access question: a subscription that is cancelling is still active, and treating
     * this as a reason to revoke takes away something already paid for.
     */
    public function isCancelling(): bool
    {
        return $this->scheduledChange?->action === ScheduledChangeAction::Cancel;
    }

    /**
     * Whether a pause is scheduled but has not taken effect yet.
     */
    public function isPausing(): bool
    {
        return $this->scheduledChange?->action === ScheduledChangeAction::Pause;
    }

    /**
     * The date this subscription stops entitling the customer to anything — the scheduled
     * change's effective date when service is ending, otherwise the end of the period they
     * have paid for.
     *
     * Null when the gateway reported neither, which is ordinary for an already-cancelled or
     * indefinitely paused subscription: there is no future date because there is no future.
     */
    public function accessEndsAt(): ?DateTimeImmutable
    {
        if ($this->isCancelling() || $this->isPausing()) {
            return $this->scheduledChange?->effectiveAt;
        }

        return $this->currentPeriodEndsAt;
    }
}
