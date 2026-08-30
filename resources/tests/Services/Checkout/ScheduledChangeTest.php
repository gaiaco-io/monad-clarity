<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Checkout;

use DateTimeImmutable;
use InvalidArgumentException;
use Monad\Clarity\Services\Checkout\ScheduledChange;
use Monad\Clarity\Services\Checkout\ScheduledChangeAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduledChangeTest extends TestCase
{
    public function testAScheduledPauseMayCarryAResumeDate(): void
    {
        $resumeAt = new DateTimeImmutable('2026-10-01T00:00:00+00:00');

        $change = new ScheduledChange(
            ScheduledChangeAction::Pause,
            new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            $resumeAt
        );

        self::assertSame($resumeAt, $change->resumeAt);
    }

    public function testAPauseWithNoResumeDateRunsUntilItIsResumedByHand(): void
    {
        $change = new ScheduledChange(
            ScheduledChangeAction::Pause,
            new DateTimeImmutable('2026-09-01T00:00:00+00:00')
        );

        self::assertNull($change->resumeAt);
    }

    #[DataProvider('actionsThatCannotResume')]
    public function testOnlyAPauseCarriesAResumeDate(ScheduledChangeAction $action): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Only a scheduled pause carries a resume date/');

        new ScheduledChange(
            $action,
            new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-10-01T00:00:00+00:00')
        );
    }

    /**
     * @return list<array{ScheduledChangeAction}>
     */
    public static function actionsThatCannotResume(): array
    {
        return [[ScheduledChangeAction::Cancel], [ScheduledChangeAction::Resume]];
    }
}
