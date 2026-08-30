<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * How a mid-period plan change is billed. Five choices that differ by real money charged to a
 * real customer, which is why adapters take this as a **required** argument and invent no
 * default: there is no mode that is quietly correct for every flow, and an adapter that
 * picked one would be making a pricing decision on the merchant's behalf.
 *
 * The usual answers:
 *
 * - **Upgrade** (Starter to Pro) — `ProratedImmediately`. The customer expects the better
 *   plan now and expects to pay the difference now.
 * - **Downgrade** (Pro to Starter) — `ProratedNextBillingPeriod`. `ProratedImmediately` would
 *   issue a prorated refund mid-period, which is rarely what a downgrade button means.
 * - **Switching to a longer term** (monthly to annual) — `ProratedImmediately`.
 * - **Switching to a shorter term** (annual to monthly) — `ProratedNextBillingPeriod`. Let
 *   the year run out rather than unpicking it.
 * - **A comped or internal change** — `DoNotBill`. Never for self-serve: it moves a customer
 *   to a plan they are not charged for, which is a hole in the books rather than a discount.
 *
 * `FullImmediately` charges the whole new-plan price for what is left of a period the
 * customer has already paid for, so it overcharges unless a merchant genuinely means it.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum ProrationBillingMode: string
{
    case ProratedImmediately = 'prorated_immediately';
    case ProratedNextBillingPeriod = 'prorated_next_billing_period';
    case FullImmediately = 'full_immediately';
    case FullNextBillingPeriod = 'full_next_billing_period';
    case DoNotBill = 'do_not_bill';
}
