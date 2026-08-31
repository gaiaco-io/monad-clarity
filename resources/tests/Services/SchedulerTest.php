<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services;

use DateTimeImmutable;
use Monad\Clarity\Services\Scheduler;
use Monad\Clarity\Services\Scheduler\SchedulerException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    #[After]
    public function resetState(): void
    {
        Scheduler::reset();
    }

    public function testRegistersAJobUnderItsName(): void
    {
        Scheduler::job('sessions:prune', '15 3 * * *', static fn () => null);

        $jobs = Scheduler::jobs();

        self::assertArrayHasKey('sessions:prune', $jobs);
        self::assertSame('sessions:prune', $jobs['sessions:prune']->name);
        self::assertSame('15 3 * * *', $jobs['sessions:prune']->expression->expression);
        self::assertSame(60, $jobs['sessions:prune']->staleAfterMinutes);
    }

    public function testStalenessWindowIsPerJob(): void
    {
        Scheduler::job('reports:build', '0 4 * * MON', static fn () => null, 240);

        self::assertSame(240, Scheduler::jobs()['reports:build']->staleAfterMinutes);
    }

    public function testAcceptsAnyCallableAndNormalisesItToAClosure(): void
    {
        Scheduler::job('trims', '@daily', 'trim');

        self::assertSame(' x ', (Scheduler::jobs()['trims']->work)(' x ', ''));
    }

    public function testDueReturnsOnlyTheJobsMatchingThatMinute(): void
    {
        Scheduler::job('sessions:prune', '15 3 * * *', static fn () => null);
        Scheduler::job('caches:sweep', '*/10 * * * *', static fn () => null);
        Scheduler::job('reports:build', '0 4 * * MON', static fn () => null);

        $due = Scheduler::due(new DateTimeImmutable('2026-03-04 03:15:00'));

        self::assertCount(1, $due);
        self::assertSame('sessions:prune', $due[0]->name);
    }

    public function testDueReturnsEveryJobMatchingTheSameMinute(): void
    {
        Scheduler::job('a', '0 * * * *', static fn () => null);
        Scheduler::job('b', '@hourly', static fn () => null);

        self::assertCount(2, Scheduler::due(new DateTimeImmutable('2026-03-04 13:00:00')));
    }

    public function testDueIsEmptyWhenNothingMatches(): void
    {
        Scheduler::job('sessions:prune', '15 3 * * *', static fn () => null);

        self::assertSame([], Scheduler::due(new DateTimeImmutable('2026-03-04 09:00:00')));
    }

    /**
     * The expression is parsed at registration, so a typo breaks the very next `mitosis`
     * invocation rather than producing a job that quietly never fires.
     */
    public function testRejectsAMalformedExpressionAtRegistration(): void
    {
        $this->expectException(SchedulerException::class);

        Scheduler::job('sessions:prune', 'every night please', static fn () => null);
    }

    public function testRejectsASecondJobUnderAnAlreadyRegisteredName(): void
    {
        Scheduler::job('sessions:prune', '15 3 * * *', static fn () => null);

        $this->expectException(SchedulerException::class);

        Scheduler::job('sessions:prune', '0 4 * * *', static fn () => null);
    }

    public function testRejectsAStalenessWindowThatWouldReapARunAsItStarts(): void
    {
        $this->expectException(SchedulerException::class);

        Scheduler::job('reports:build', '@daily', static fn () => null, 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function conventionalNames(): array
    {
        return [
            'colon group' => ['sessions:prune'],
            'hyphens' => ['reports:build-the-quarterly-summary'],
            'dots' => ['app.cache.sweep'],
            'underscores' => ['legacy_import'],
            'digits' => ['s3:sync2'],
            'single word' => ['prune'],
        ];
    }

    #[DataProvider('conventionalNames')]
    public function testAcceptsTheConventionalNameForms(string $name): void
    {
        Scheduler::job($name, '@daily', static fn () => null);

        self::assertArrayHasKey($name, Scheduler::jobs());
    }

    /**
     * `scheduled_runs.job` inherits its server's collation, and MySQL's default is case- and
     * accent-insensitive — so `reports:build` and `Reports:Build` would register as two jobs
     * (PHP array keys are case-sensitive) and then collide on one row, where one of the two
     * silently stops running. Verified against a live MySQL server. The ambiguity is refused
     * at registration rather than left to depend on which database an application deployed on.
     *
     * @return array<string, array{string}>
     */
    public static function ambiguousNames(): array
    {
        return [
            'uppercase' => ['Reports:Build'],
            'mixed case' => ['sessions:Prune'],
            'accented' => ['réports:build'],
            'trailing space' => ['sessions:prune '],
            'inner space' => ['reports build'],
            'trailing separator' => ['reports:'],
            'leading separator' => [':reports'],
            'doubled separator' => ['reports::build'],
        ];
    }

    #[DataProvider('ambiguousNames')]
    public function testRejectsANameThatCouldCollideInTheDatabase(string $name): void
    {
        $this->expectException(SchedulerException::class);

        Scheduler::job($name, '@daily', static fn () => null);
    }

    public function testTheRejectionShowsTheNameAndTheFormItWanted(): void
    {
        try {
            Scheduler::job('Reports:Build', '@daily', static fn () => null);
            self::fail('Expected a SchedulerException.');
        } catch (SchedulerException $e) {
            self::assertStringContainsString('Reports:Build', $e->getMessage());
            self::assertStringContainsString('sessions:prune', $e->getMessage());
        }
    }

    public function testRejectsAnUnnamedJob(): void
    {
        $this->expectException(SchedulerException::class);

        Scheduler::job('', '@daily', static fn () => null);
    }

    public function testResetEmptiesTheRegistry(): void
    {
        Scheduler::job('sessions:prune', '15 3 * * *', static fn () => null);
        Scheduler::reset();

        self::assertSame([], Scheduler::jobs());
    }
}
