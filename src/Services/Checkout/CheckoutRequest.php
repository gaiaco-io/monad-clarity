<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use InvalidArgumentException;

/**
 * A gateway-agnostic request to begin a checkout (ReleaseNotes §9.6.1). Every adapter
 * translates this into its own gateway's create-payment call.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class CheckoutRequest
{
    /**
     * @param string $reference The merchant's own order reference, echoed back on every
     *        callback so a payment can be reconciled against the originating order.
     * @param list<LineItem> $lineItems Optional itemisation for the hosted page. When
     *        given, the sum of their subtotals must equal $amount — see below.
     * @param array<string, scalar> $metadata Sent to the gateway and returned on callbacks.
     * @param string|null $idempotencyKey Replays of the same key return the original
     *        checkout instead of creating a second one. Defaults to $reference, which is
     *        already unique per order — the common case needs no thought here.
     */
    public function __construct(
        public string $reference,
        public Money $amount,
        public string $successUrl,
        public string $cancelUrl,
        public array $lineItems = [],
        public ?string $customerEmail = null,
        public array $metadata = [],
        public ?string $idempotencyKey = null,
        public int $timeoutSeconds = 30,
    ) {
        if (trim($reference) === '') {
            throw new InvalidArgumentException('A checkout needs a merchant reference to reconcile the payment against.');
        }

        self::assertHttpUrl($successUrl, 'successUrl');
        self::assertHttpUrl($cancelUrl, 'cancelUrl');

        if ($lineItems !== []) {
            self::assertLineItemsTotal($lineItems, $amount);
        }

        if ($customerEmail !== null && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf('customerEmail "%s" is not a valid email address.', $customerEmail));
        }

        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException(sprintf('timeoutSeconds must be at least 1, got %d.', $timeoutSeconds));
        }
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey ?? $this->reference;
    }

    private static function assertHttpUrl(string $url, string $field): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException(sprintf('%s must be an absolute http(s) URL, got "%s".', $field, $url));
        }
    }

    /**
     * Gateways that accept line items charge the sum of those items and ignore any total
     * sent alongside them. If the two disagree, the customer is charged one amount while
     * the merchant's ledger records another — a silent reconciliation failure that
     * surfaces days later in a settlement report. Cheaper to refuse it here.
     *
     * @param list<LineItem> $lineItems
     */
    private static function assertLineItemsTotal(array $lineItems, Money $amount): void
    {
        $total = new Money(0, $amount->currency);

        foreach ($lineItems as $item) {
            $total = $total->plus($item->subtotal());
        }

        if (!$total->equals($amount)) {
            throw new InvalidArgumentException(sprintf(
                'Line items total %s but the checkout amount is %s. The gateway charges the line items, '
                . 'so these must agree or the customer would be charged an amount the merchant never recorded.',
                $total->describe(),
                $amount->describe()
            ));
        }
    }
}
