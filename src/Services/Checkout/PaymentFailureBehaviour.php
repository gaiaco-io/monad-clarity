<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * What a plan change should do when the charge it triggers is declined.
 *
 * `PreventChange` leaves the subscription on its old plan and reports the failure, so the
 * customer sees "payment failed, update your card" and every step stays explicit. That is the
 * right answer for self-serve, and it is the default.
 *
 * `ApplyChange` moves the customer to the new plan anyway and leaves the charge outstanding
 * for collection later. It suits an administrator upgrading someone whose card is
 * temporarily failing — and it is extending credit, so a merchant choosing it should have a
 * collections process in mind.
 *
 * An enum rather than a boolean: `changePlan(..., applyOnFailure: true)` reads backwards half
 * the time, and this is a decision about someone's money.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum PaymentFailureBehaviour: string
{
    case PreventChange = 'prevent_change';
    case ApplyChange = 'apply_change';
}
