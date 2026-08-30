<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * When a change to a subscription takes effect.
 *
 * `NextBillingPeriod` lets the customer keep what they have already paid for and is the right
 * answer for an ordinary "cancel my plan" button. `Immediately` ends service now, and for a
 * cancellation the gateway prorates a refund for the unused part — a different product
 * decision, not a faster version of the same one.
 *
 * Adapters take this as a required argument with no default, even where the gateway has one.
 * Gateways do not agree on the default: Paddle defaults cancel and pause to the next billing
 * period but resume to immediately, which is precisely why an adapter should not quietly pick
 * for the merchant.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum SubscriptionEffectiveFrom: string
{
    case Immediately = 'immediately';
    case NextBillingPeriod = 'next_billing_period';
}
