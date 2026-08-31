<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Scheduler;

use DateTimeImmutable;
use Monad\Clarity\Services\Scheduler\CronExpression;
use Monad\Clarity\Services\Scheduler\SchedulerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronExpressionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function dueMoments(): array
    {
        return [
            'every minute' => ['* * * * *', '2026-03-04 13:37', true],

            'exact minute and hour' => ['15 3 * * *', '2026-03-04 03:15', true],
            'wrong minute' => ['15 3 * * *', '2026-03-04 03:16', false],
            'wrong hour' => ['15 3 * * *', '2026-03-04 04:15', false],

            'step from zero' => ['*/10 * * * *', '2026-03-04 13:30', true],
            'step misses between' => ['*/10 * * * *', '2026-03-04 13:35', false],
            'step within a range' => ['0-30/15 * * * *', '2026-03-04 13:15', true],
            'step past the end of its range' => ['0-30/15 * * * *', '2026-03-04 13:45', false],

            'list' => ['0 9,17 * * *', '2026-03-04 17:00', true],
            'list misses' => ['0 9,17 * * *', '2026-03-04 13:00', false],
            'range' => ['0 9-17 * * *', '2026-03-04 12:00', true],
            'range excludes its outside' => ['0 9-17 * * *', '2026-03-04 18:00', false],

            'named month' => ['0 0 1 MAR *', '2026-03-01 00:00', true],
            'named month misses' => ['0 0 1 MAR *', '2026-04-01 00:00', false],
            'named day lowercase' => ['0 4 * * mon', '2026-03-02 04:00', true],
            'named day misses' => ['0 4 * * mon', '2026-03-03 04:00', false],

            'sunday as zero' => ['0 0 * * 0', '2026-03-08 00:00', true],
            'sunday as seven' => ['0 0 * * 7', '2026-03-08 00:00', true],

            'macro daily' => ['@daily', '2026-03-04 00:00', true],
            'macro daily off the hour' => ['@daily', '2026-03-04 00:01', false],
            'macro hourly' => ['@hourly', '2026-03-04 13:00', true],
            'macro weekly is sunday' => ['@weekly', '2026-03-08 00:00', true],
            'macro monthly is the first' => ['@monthly', '2026-03-01 00:00', true],
            'macro yearly is new year' => ['@yearly', '2026-01-01 00:00', true],
            'macro uppercase' => ['@DAILY', '2026-03-04 00:00', true],

            'surrounding whitespace' => ['  15   3 * * *  ', '2026-03-04 03:15', true],
        ];
    }

    #[DataProvider('dueMoments')]
    public function testEvaluatesAnExpressionAgainstAMoment(string $expression, string $moment, bool $expected): void
    {
        self::assertSame(
            $expected,
            CronExpression::parse($expression)->isDue(new DateTimeImmutable($moment))
        );
    }

    /**
     * The Vixie rule, and the single likeliest thing to get silently wrong: two restricted
     * day fields are alternatives, not conditions to satisfy together. `0 0 13 * FRI` fires
     * on the 13th whatever day that is, and on every Friday.
     */
    public function testTwoRestrictedDayFieldsAreAlternatives(): void
    {
        $expression = CronExpression::parse('0 0 13 * FRI');

        // The 13th, a Friday — both fields match.
        self::assertTrue($expression->isDue(new DateTimeImmutable('2026-02-13 00:00')));
        // The 13th, a Wednesday — day-of-month alone.
        self::assertTrue($expression->isDue(new DateTimeImmutable('2026-05-13 00:00')));
        // A Friday that is not the 13th — day-of-week alone.
        self::assertTrue($expression->isDue(new DateTimeImmutable('2026-03-06 00:00')));
        // Neither.
        self::assertFalse($expression->isDue(new DateTimeImmutable('2026-03-05 00:00')));
    }

    public function testOneRestrictedDayFieldStillNarrows(): void
    {
        // Day-of-week is `*`, so the day-of-month restriction is the only one that applies.
        $expression = CronExpression::parse('0 0 13 * *');

        self::assertTrue($expression->isDue(new DateTimeImmutable('2026-05-13 00:00')));
        self::assertFalse($expression->isDue(new DateTimeImmutable('2026-05-14 00:00')));
    }

    public function testSecondsAreIgnored(): void
    {
        $expression = CronExpression::parse('15 3 * * *');

        self::assertTrue($expression->isDue(new DateTimeImmutable('2026-03-04 03:15:59')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedExpressions(): array
    {
        return [
            'too few fields' => ['15 3 * *'],
            'too many fields' => ['15 3 * * * *'],
            'empty' => [''],
            'minute out of range' => ['60 * * * *'],
            'hour out of range' => ['* 24 * * *'],
            'day-of-month below its floor' => ['0 0 0 * *'],
            'month out of range' => ['0 0 1 13 *'],
            'day-of-week out of range' => ['0 0 * * 8'],
            'inverted range' => ['30-10 * * * *'],
            'zero step' => ['*/0 * * * *'],
            'step applied to a single value' => ['5/15 * * * *'],
            'non-numeric' => ['abc * * * *'],
            'unknown name' => ['0 0 * * FUN'],
            'unknown macro' => ['@fortnightly'],
        ];
    }

    #[DataProvider('malformedExpressions')]
    public function testRejectsAMalformedExpression(string $expression): void
    {
        $this->expectException(SchedulerException::class);

        CronExpression::parse($expression);
    }

    /**
     * `5/15` reads as `5-59/15` in Vixie cron and as "minute 5 alone" under a naive range of
     * 5 to 5. Guessing either way silently narrows or widens a schedule and nobody notices, so
     * the ambiguity is rejected and the message spells out the range to write instead.
     */
    public function testAStepOnASingleValueIsRejectedRatherThanGuessedAt(): void
    {
        try {
            CronExpression::parse('5/15 * * * *');
            self::fail('Expected a SchedulerException.');
        } catch (SchedulerException $e) {
            self::assertStringContainsString('5-59/15', $e->getMessage());
        }

        self::assertTrue(CronExpression::parse('5-59/15 * * * *')->isDue(new DateTimeImmutable('2026-03-04 03:20')));
    }

    public function testTheRejectionNamesTheFieldAndWhatItAccepts(): void
    {
        try {
            CronExpression::parse('0 0 * * 8');
            self::fail('Expected a SchedulerException.');
        } catch (SchedulerException $e) {
            self::assertStringContainsString('day-of-week', $e->getMessage());
            self::assertStringContainsString('0-7', $e->getMessage());
        }
    }

    public function testKeepsTheExpressionItWasGivenForDisplay(): void
    {
        self::assertSame('15 3 * * *', CronExpression::parse('  15 3 * * *  ')->expression);
    }
}
