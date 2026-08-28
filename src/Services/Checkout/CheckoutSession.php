<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * What a gateway returns when a checkout is created (ReleaseNotes §9.6.1): the handle to
 * re-query it by, and — for a gateway-hosted page (§9.5) — the URL to send the customer to.
 *
 * $redirectUrl is null for a custom checkout page, where the merchant collects payment
 * details on their own site and no redirect exists.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class CheckoutSession
{
    /**
     * @param string $gatewayReference The gateway's own id for this checkout, and the
     *        handle Checkout::retrieveStatus() re-queries by.
     * @param string|null $paymentReference The gateway's id for the underlying payment,
     *        where it differs from the checkout's own (Stripe: a PaymentIntent). Refunds
     *        act on this. Null until a payment actually exists.
     * @param array<string, mixed> $raw The gateway's undigested response, for anything
     *        this contract deliberately does not model.
     */
    public function __construct(
        public string $gateway,
        public string $gatewayReference,
        public ?string $redirectUrl,
        public TransactionStatus $status,
        public Money $amount,
        public ?string $paymentReference = null,
        public array $raw = [],
    ) {
    }
}
