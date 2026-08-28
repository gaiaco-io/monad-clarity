<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Checkout;

use Monad\Clarity\Services\Checkout\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAnAmountIsHeldExactlyAsGiven(): void
    {
        $money = new Money(2500, 'USD');

        self::assertSame(2500, $money->minorUnits);
        self::assertSame('USD', $money->currency);
    }

    public function testCurrencyIsNormalisedToUpperCase(): void
    {
        self::assertSame('USD', (new Money(100, 'usd'))->currency);
    }

    /**
     * Zero-decimal currencies are the reason minor units are the only representation:
     * ¥2500 is 2500 minor units, not 250000. Nothing here multiplies by 100.
     */
    public function testZeroDecimalCurrenciesAreStoredWithoutScaling(): void
    {
        self::assertSame(2500, (new Money(2500, 'JPY'))->minorUnits);
    }

    public function testZeroIsAValidAmount(): void
    {
        self::assertSame(0, (new Money(0, 'USD'))->minorUnits);
    }

    public function testANegativeAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be negative/');

        new Money(-1, 'USD');
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidCurrencies(): array
    {
        return [['US'], ['USDD'], [''], ['US1'], ['dollars']];
    }

    #[DataProvider('invalidCurrencies')]
    public function testCurrencyMustBeAThreeLetterCode(string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ISO 4217/');

        new Money(100, $currency);
    }

    public function testAmountsAddAndSubtract(): void
    {
        $a = new Money(1000, 'USD');
        $b = new Money(250, 'USD');

        self::assertSame(1250, $a->plus($b)->minorUnits);
        self::assertSame(750, $a->minus($b)->minorUnits);
    }

    public function testSubtractingMoreThanIsThereIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negative amount/');

        (new Money(100, 'USD'))->minus(new Money(101, 'USD'));
    }

    public function testMixingCurrenciesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/different currencies: USD and EUR/');

        (new Money(100, 'USD'))->plus(new Money(100, 'EUR'));
    }

    public function testMultiplicationScalesTheAmount(): void
    {
        self::assertSame(3000, (new Money(1000, 'USD'))->multipliedBy(3)->minorUnits);
        self::assertSame(0, (new Money(1000, 'USD'))->multipliedBy(0)->minorUnits);
    }

    public function testANegativeFactorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negative factor/');

        (new Money(1000, 'USD'))->multipliedBy(-1);
    }

    public function testEqualityComparesBothAmountAndCurrency(): void
    {
        self::assertTrue((new Money(100, 'USD'))->equals(new Money(100, 'USD')));
        self::assertFalse((new Money(100, 'USD'))->equals(new Money(101, 'USD')));
        self::assertFalse((new Money(100, 'USD'))->equals(new Money(100, 'EUR')));
    }

    public function testComparisonOrdersAmountsWithinACurrency(): void
    {
        self::assertTrue((new Money(101, 'USD'))->isGreaterThan(new Money(100, 'USD')));
        self::assertFalse((new Money(100, 'USD'))->isGreaterThan(new Money(100, 'USD')));
    }

    public function testDescribeStatesTheUnitItIsIn(): void
    {
        self::assertSame('2500 USD (minor units)', (new Money(2500, 'USD'))->describe());
    }
}
