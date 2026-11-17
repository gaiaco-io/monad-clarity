<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use RuntimeException;

/**
 * Every failure originating from a payment gateway adapter: a non-2xx gateway response, an
 * unparseable body, or a callback whose signature does not verify.
 *
 * Not final since 1.12.0, so that IdempotencyKeyReusedException can refine it. Catching this
 * class still catches every adapter failure. A custom adapter may subclass it the same way.
 * Removing `final` is one-way: restoring it would break those subclasses, so it is never
 * restored outside a major release.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
class CheckoutException extends RuntimeException
{
}
