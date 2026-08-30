<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Checkout;

use Monad\Clarity\Services\Checkout\SubscriptionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionStatusTest extends TestCase
{
    public function testOnlyACancelledSubscriptionHasEndedForGood(): void
    {
        self::assertTrue(SubscriptionStatus::Cancelled->isTerminal());
    }

    #[DataProvider('statusesThatCanStillChange')]
    public function testEveryOtherStatusCanStillChange(SubscriptionStatus $status): void
    {
        self::assertFalse($status->isTerminal(), $status->value . ' can still change.');
    }

    /**
     * @return list<array{SubscriptionStatus}>
     */
    public static function statusesThatCanStillChange(): array
    {
        return [
            [SubscriptionStatus::Active],
            [SubscriptionStatus::Trialing],
            [SubscriptionStatus::PastDue],
            [SubscriptionStatus::Paused],
        ];
    }

    /**
     * Clarity spells it with two Ls wherever it is Clarity's own word, matching
     * TransactionStatus. Paddle's one-l `canceled` is mapped across by the adapter, and this
     * pins the backing value so a fixture written against the gateway's spelling fails loudly
     * rather than silently constructing nothing.
     */
    public function testTheBackingValuesAreClaritysOwnVocabulary(): void
    {
        self::assertSame('cancelled', SubscriptionStatus::Cancelled->value);
        self::assertNull(SubscriptionStatus::tryFrom('canceled'));
    }
}
