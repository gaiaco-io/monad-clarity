<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * The lifecycle of a subscription — deliberately separate from TransactionStatus, which has
 * four cases describing whether one payment moved. A subscription is not a payment; it is the
 * standing arrangement that produces payments, and it outlives every one of them.
 *
 * Exactly one case is terminal. The rest can still change, including `Paused`, which is why
 * "has this ended?" is a question only `isTerminal()` answers honestly.
 *
 * **The trap this enum exists to make visible:** a customer who clicks cancel does NOT land
 * here as `Cancelled`. Their subscription stays `Active` with a pending ScheduledChange until
 * the period they have already paid for runs out. Reading a bare status to decide whether to
 * revoke access is therefore right by accident at best — see SubscriptionSnapshot, which
 * carries the scheduled change beside the status precisely so the two are read together.
 *
 * Whether `PastDue` should keep a customer's access is deliberately NOT decided here. Paddle
 * is still retrying the charge and the customer has not been told they are cut off, so most
 * merchants grant a few days' grace and show a banner — but that is a product decision, and
 * a framework that encoded it would be dictating rather than enabling.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Paused = 'paused';
    // Paddle spells it with one l, as it does for transactions; Clarity spells it with two
    // wherever it is Clarity's own word. The adapter maps across, exactly as it already does
    // for TransactionStatus::Cancelled.
    case Cancelled = 'cancelled';

    /**
     * Whether this subscription has ended for good. Only a cancellation that has actually
     * taken effect has — a paused subscription resumes, and a past-due one is still being
     * collected.
     */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }
}
