<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use InvalidArgumentException;

/**
 * One line on a gateway-hosted checkout page: what the customer is buying, at what unit
 * price, in what quantity.
 *
 * Line items are optional on a CheckoutRequest. Supply them when the hosted page should
 * itemise the purchase; omit them and the adapter renders a single line from the request's
 * total, described by its merchant reference.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class LineItem
{
    public function __construct(
        public string $description,
        public Money $unitPrice,
        public int $quantity = 1,
    ) {
        if (trim($description) === '') {
            throw new InvalidArgumentException('A line item needs a description — it is shown to the customer on the checkout page.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException(sprintf('A line item quantity must be at least 1, got %d.', $quantity));
        }
    }

    public function subtotal(): Money
    {
        return $this->unitPrice->multipliedBy($this->quantity);
    }
}
