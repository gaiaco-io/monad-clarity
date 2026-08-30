<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Checkout;

use InvalidArgumentException;
use Monad\Clarity\Services\Checkout\BillingCycle;
use Monad\Clarity\Services\Checkout\BillingInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingCycleTest extends TestCase
{
    public function testACycleDefaultsToOncePerInterval(): void
    {
        self::assertSame(1, (new BillingCycle(BillingInterval::Month))->frequency);
    }

    #[DataProvider('invalidFrequencies')]
    public function testACycleRefusesAFrequencyBelowOne(int $frequency): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 1/');

        new BillingCycle(BillingInterval::Month, $frequency);
    }

    /**
     * @return list<array{int}>
     */
    public static function invalidFrequencies(): array
    {
        return [[0], [-1], [-12]];
    }

    public function testTwoCyclesAreEqualOnlyWhenBothIntervalAndFrequencyMatch(): void
    {
        $monthly = new BillingCycle(BillingInterval::Month);

        self::assertTrue($monthly->equals(new BillingCycle(BillingInterval::Month, 1)));
        self::assertFalse($monthly->equals(new BillingCycle(BillingInterval::Month, 3)));
        self::assertFalse($monthly->equals(new BillingCycle(BillingInterval::Year, 1)));
    }

    public function testASingleIntervalReadsInTheSingular(): void
    {
        self::assertSame('every month', (new BillingCycle(BillingInterval::Month))->describe());
    }

    public function testSeveralIntervalsReadInThePlural(): void
    {
        self::assertSame('every 3 months', (new BillingCycle(BillingInterval::Month, 3))->describe());
        self::assertSame('every 14 days', (new BillingCycle(BillingInterval::Day, 14))->describe());
    }

    /**
     * A trial period is the same shape as a billing cycle, which is why one class serves both.
     */
    public function testTheSameClassDescribesATrialPeriod(): void
    {
        self::assertSame('every 14 days', (new BillingCycle(BillingInterval::Day, 14))->describe());
    }
}
