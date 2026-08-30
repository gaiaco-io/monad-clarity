<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * The unit a recurring charge repeats in. Paired with a frequency by BillingCycle — "every
 * month" is this enum plus the number 1.
 *
 * Four cases because four is what billing actually uses. There is no `quarter` or
 * `fortnight`: those are a Month of frequency 3 and a Week of frequency 2, and inventing
 * synonyms for numbers a caller already passes would give two ways to say one thing.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum BillingInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
