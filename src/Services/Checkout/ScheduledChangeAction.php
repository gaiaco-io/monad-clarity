<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * What a pending change to a subscription will do when its effective date arrives.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum ScheduledChangeAction: string
{
    case Cancel = 'cancel';
    case Pause = 'pause';
    case Resume = 'resume';
}
