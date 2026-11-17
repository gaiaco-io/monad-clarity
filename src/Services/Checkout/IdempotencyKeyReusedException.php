<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use Throwable;

/**
 * The gateway refused a request because its idempotency key had already been used for a
 * different request: other parameters, or another endpoint. The refused request was not
 * carried out. Nothing was created, charged or refunded by it.
 *
 * What to do next depends on the call, and the message says which. A new key is safe for a
 * checkout session, which charges nothing. For a refund or a plan change, a new key is a new
 * request and can refund or charge a second time, so find out what the earlier request did
 * first.
 *
 * A `CheckoutException`, so a `catch (CheckoutException)` written before 1.12.0 still catches
 * it. Only the Stripe adapters throw it; Paddle accepts no idempotency keys.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class IdempotencyKeyReusedException extends CheckoutException
{
    /**
     * @param string $idempotencyKey the key as the caller knows it. That is the one passed in,
     *        or, when none was, the one the request derived. A CheckoutRequest's key is its order
     *        reference, and a RefundRequest's is `refund:{reference}:{amount or "full"}`. A key
     *        changePlan() derives for a product it creates is reported as the key changePlan()
     *        was given.
     */
    public function __construct(
        string $message,
        public readonly string $idempotencyKey,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
