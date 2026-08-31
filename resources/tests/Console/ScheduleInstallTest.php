<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\ScheduleInstall;
use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Scheduler\JobLedger;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class ScheduleInstallTest extends TestCase
{
    #[Before]
    public function setUpInMemoryDatabase(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));
    }

    #[After]
    public function resetState(): void
    {
        DB::reset();
        Console::reset();
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    public function testCreatesTheRunsTable(): void
    {
        $output = $this->capture(function () {
            self::assertSame(0, (new ScheduleInstall())(Arguments::parse([])));
        });

        self::assertStringContainsString('Scheduler install complete', $output);
        self::assertTrue(Schema::hasTable(JobLedger::RUNS_TABLE));
    }

    public function testTellsTheOperatorTheOneCrontabLineTheyNowNeed(): void
    {
        $output = $this->capture(function () {
            (new ScheduleInstall())(Arguments::parse([]));
        });

        self::assertStringContainsString('* * * * *', $output);
        self::assertStringContainsString('schedule:run', $output);
    }

    /**
     * Re-runnable, and the guard is `hasTable` rather than the DDL's `IF NOT EXISTS`: that
     * clause covers the table but not the indexes, which are separate statements MySQL has
     * no way to make idempotent.
     */
    public function testASecondRunDoesNothingAndSaysSo(): void
    {
        $this->capture(function () {
            (new ScheduleInstall())(Arguments::parse([]));
        });

        $output = $this->capture(function () {
            self::assertSame(0, (new ScheduleInstall())(Arguments::parse([])));
        });

        self::assertStringContainsString('already installed', $output);
    }

    public function testASecondRunLeavesExistingRunRecordsIntact(): void
    {
        $this->capture(function () {
            (new ScheduleInstall())(Arguments::parse([]));
        });

        $ledger = new JobLedger();
        $runId = (string) $ledger->claim('sessions:prune', new \DateTimeImmutable('2026-03-04 03:15:00'));
        $ledger->complete($runId, 12);

        $this->capture(function () {
            (new ScheduleInstall())(Arguments::parse([]));
        });

        self::assertSame($runId, $ledger->lastRun('sessions:prune')['id']);
    }

    /**
     * The scheduler table is opt-in, exactly like the checkout tables. An application that
     * schedules nothing never carries it.
     */
    public function testTheTableDoesNotExistUntilTheCommandIsRun(): void
    {
        self::assertFalse(Schema::hasTable(JobLedger::RUNS_TABLE));
    }

    public function testTheUniqueIndexIsCreatedBecauseItIsTheMutex(): void
    {
        $this->capture(function () {
            (new ScheduleInstall())(Arguments::parse([]));
        });

        $ledger = new JobLedger();
        $dueAt = new \DateTimeImmutable('2026-03-04 03:15:00');

        self::assertNotNull($ledger->claim('sessions:prune', $dueAt));
        self::assertNull($ledger->claim('sessions:prune', $dueAt));
    }
}
