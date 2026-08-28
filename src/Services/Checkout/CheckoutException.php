<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use RuntimeException;

/**
 * Every failure originating from a payment gateway adapter: a non-2xx gateway response, an
 * unparseable body, or a callback whose signature does not verify.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class CheckoutException extends RuntimeException
{
}
