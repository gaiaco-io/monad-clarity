<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * What happens to the billing period when a paused subscription starts again.
 *
 * `ContinueExistingBillingPeriod` picks up where the pause interrupted, so the customer keeps
 * the days they had already paid for. `StartNewBillingPeriod` bills afresh from the resume
 * date, which is what a gateway does by default and what a merchant usually wants after a
 * long pause.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum ResumeBilling: string
{
    case ContinueExistingBillingPeriod = 'continue_existing_billing_period';
    case StartNewBillingPeriod = 'start_new_billing_period';
}
