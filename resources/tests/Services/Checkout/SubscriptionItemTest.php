<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Checkout;

use InvalidArgumentException;
use Monad\Clarity\Services\Checkout\BillingCycle;
use Monad\Clarity\Services\Checkout\BillingInterval;
use Monad\Clarity\Services\Checkout\LineItem;
use Monad\Clarity\Services\Checkout\Money;
use Monad\Clarity\Services\Checkout\SubscriptionItem;
use PHPUnit\Framework\TestCase;

final class SubscriptionItemTest extends TestCase
{
    public function testACatalogPriceItemCarriesOnlyItsPriceId(): void
    {
        $item = SubscriptionItem::catalogPrice('pri_01h8xce4x86pq', 3);

        self::assertTrue($item->isCatalogPrice());
        self::assertSame('pri_01h8xce4x86pq', $item->priceId);
        self::assertSame(3, $item->quantity);
        self::assertNull($item->lineItem);
        self::assertNull($item->billingCycle);
    }

    public function testACatalogPriceItemDefaultsToOne(): void
    {
        self::assertSame(1, SubscriptionItem::catalogPrice('pri_01h8xce4x86pq')->quantity);
    }

    public function testACatalogPriceItemRefusesAnEmptyPriceId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a price id/');

        SubscriptionItem::catalogPrice('   ');
    }

    public function testACatalogPriceItemRefusesAQuantityBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 1/');

        SubscriptionItem::catalogPrice('pri_01h8xce4x86pq', 0);
    }

    public function testAnInlineItemCarriesItsLineItemAndBillingCycle(): void
    {
        $lineItem = new LineItem('Pro plan', new Money(2500, 'USD'), 2);
        $cycle = new BillingCycle(BillingInterval::Month);

        $item = SubscriptionItem::inline($lineItem, $cycle);

        self::assertFalse($item->isCatalogPrice());
        self::assertNull($item->priceId);
        self::assertSame($lineItem, $item->lineItem);
        self::assertSame($cycle, $item->billingCycle);
    }

    /**
     * The quantity is the line item's own — carrying it twice would let the two disagree.
     */
    public function testAnInlineItemTakesItsQuantityFromTheLineItem(): void
    {
        $item = SubscriptionItem::inline(
            new LineItem('Pro plan', new Money(2500, 'USD'), 4),
            new BillingCycle(BillingInterval::Month)
        );

        self::assertSame(4, $item->quantity);
    }

    /**
     * The constructor is private and both named constructors fill exactly one side, so an
     * item that is both a catalogue price and an inline one cannot be built at all.
     */
    public function testAnItemIsNeverBothCatalogAndInline(): void
    {
        $catalog = SubscriptionItem::catalogPrice('pri_01h8xce4x86pq');
        $inline = SubscriptionItem::inline(
            new LineItem('Pro plan', new Money(2500, 'USD')),
            new BillingCycle(BillingInterval::Month)
        );

        self::assertNotSame($catalog->isCatalogPrice(), $inline->isCatalogPrice());
        self::assertNull($catalog->lineItem);
        self::assertNull($inline->priceId);
    }
}
