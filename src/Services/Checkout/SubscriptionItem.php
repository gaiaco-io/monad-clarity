<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use InvalidArgumentException;

/**
 * One line of what a subscription bills for, in a plan change.
 *
 * There are two honest ways to name a thing a subscription charges for, and a merchant should
 * not have to pick a class based on which:
 *
 * - **A price from the gateway's catalogue** — `SubscriptionItem::catalogPrice('pri_…')`. What
 *   a merchant who maintains tiers in the gateway dashboard already has.
 * - **A price described inline** — `SubscriptionItem::inline($lineItem, $cycle)`. No catalogue
 *   needed, matching how the rest of Checkout describes a sale, so an application can move a
 *   customer between plans it defines in its own code.
 *
 * The two named constructors are the only way in; the constructor itself is private, so an
 * item that is somehow both or neither cannot be built.
 *
 * A plan change replaces a subscription's whole item set rather than adding to it, so a list
 * of these is the complete answer to "what should this subscription bill from now on" — see
 * the adapter's changePlan().
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class SubscriptionItem
{
    private function __construct(
        public int $quantity,
        public ?string $priceId = null,
        public ?LineItem $lineItem = null,
        public ?BillingCycle $billingCycle = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidArgumentException(
                sprintf('A subscription item quantity must be at least 1, got %d.', $quantity)
            );
        }
    }

    /**
     * A price the merchant already keeps in the gateway's catalogue.
     */
    public static function catalogPrice(string $priceId, int $quantity = 1): self
    {
        if (trim($priceId) === '') {
            throw new InvalidArgumentException('A catalogue subscription item needs a price id.');
        }

        return new self(quantity: $quantity, priceId: $priceId);
    }

    /**
     * A price described here and now, needing nothing in the gateway's catalogue. The billing
     * cycle is required rather than inherited from the adapter: a plan change is exactly the
     * operation where the new terms may differ from the old.
     */
    public static function inline(LineItem $lineItem, BillingCycle $billingCycle): self
    {
        return new self(
            quantity: $lineItem->quantity,
            lineItem: $lineItem,
            billingCycle: $billingCycle,
        );
    }

    public function isCatalogPrice(): bool
    {
        return $this->priceId !== null;
    }
}
