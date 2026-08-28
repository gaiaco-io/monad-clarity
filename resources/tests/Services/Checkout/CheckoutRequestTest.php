<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Checkout;

use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\LineItem;
use Monad\Clarity\Services\Checkout\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckoutRequestTest extends TestCase
{
    public function testAValidRequestKeepsWhatItWasGiven(): void
    {
        $request = self::request();

        self::assertSame('ORDER-1001', $request->reference);
        self::assertSame(2500, $request->amount->minorUnits);
        self::assertSame([], $request->lineItems);
    }

    public function testTheIdempotencyKeyDefaultsToTheMerchantReference(): void
    {
        self::assertSame('ORDER-1001', self::request()->idempotencyKey());
        self::assertSame('custom-key', self::request(['idempotencyKey' => 'custom-key'])->idempotencyKey());
    }

    public function testAnEmptyReferenceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/merchant reference/');

        self::request(['reference' => '   ']);
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidUrls(): array
    {
        return [['not-a-url'], ['/relative/path'], ['ftp://shop.test/done'], ['javascript:alert(1)'], ['']];
    }

    #[DataProvider('invalidUrls')]
    public function testReturnUrlsMustBeAbsoluteHttpUrls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute http\(s\) URL/');

        self::request(['successUrl' => $url]);
    }

    public function testAnInvalidCustomerEmailIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid email address/');

        self::request(['customerEmail' => 'not-an-email']);
    }

    public function testLineItemsThatSumToTheAmountAreAccepted(): void
    {
        $request = self::request(['lineItems' => [
            new LineItem('Widget', new Money(1000, 'USD'), 2),
            new LineItem('Shipping', new Money(500, 'USD')),
        ]]);

        self::assertCount(2, $request->lineItems);
    }

    /**
     * The gateway charges the line items and ignores the total sent alongside them, so a
     * mismatch would charge the customer one amount while the merchant recorded another.
     */
    public function testLineItemsThatDisagreeWithTheAmountAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Line items total 1000 USD .* but the checkout amount is 2500 USD/');

        self::request(['lineItems' => [new LineItem('Widget', new Money(1000, 'USD'))]]);
    }

    public function testLineItemsInAnotherCurrencyAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/different currencies/');

        self::request(['lineItems' => [new LineItem('Widget', new Money(2500, 'EUR'))]]);
    }

    public function testATimeoutBelowOneSecondIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/timeoutSeconds must be at least 1/');

        self::request(['timeoutSeconds' => 0]);
    }

    public function testALineItemNeedsADescriptionAndAPositiveQuantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LineItem('', new Money(100, 'USD'));
    }

    public function testALineItemQuantityBelowOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 1/');

        new LineItem('Widget', new Money(100, 'USD'), 0);
    }

    public function testALineItemSubtotalMultipliesByQuantity(): void
    {
        self::assertSame(3000, (new LineItem('Widget', new Money(1000, 'USD'), 3))->subtotal()->minorUnits);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function request(array $overrides = []): CheckoutRequest
    {
        return new CheckoutRequest(
            reference: $overrides['reference'] ?? 'ORDER-1001',
            amount: $overrides['amount'] ?? new Money(2500, 'USD'),
            successUrl: $overrides['successUrl'] ?? 'https://shop.test/success',
            cancelUrl: $overrides['cancelUrl'] ?? 'https://shop.test/cancel',
            lineItems: $overrides['lineItems'] ?? [],
            customerEmail: $overrides['customerEmail'] ?? null,
            metadata: $overrides['metadata'] ?? [],
            idempotencyKey: $overrides['idempotencyKey'] ?? null,
            timeoutSeconds: $overrides['timeoutSeconds'] ?? 30,
        );
    }
}
