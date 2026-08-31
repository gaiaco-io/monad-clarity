<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use DateTimeImmutable;
use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\ScheduleRun;
use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Scheduler;
use Monad\Clarity\Services\Scheduler\JobLedger;
use Monad\Clarity\Services\Scheduler\RunState;
use Monad\Clarity\Console\ScheduleInstall;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScheduleRunTest extends TestCase
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
        Scheduler::reset();
    }

    private function install(): void
    {
        Schema::createTable(JobLedger::RUNS_TABLE, ScheduleInstall::runsBlueprint());
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    /** @param list<string> $tokens */
    private function tick(array $tokens = []): array
    {
        $exitCode = null;

        $output = $this->capture(function () use ($tokens, &$exitCode) {
            $exitCode = (new ScheduleRun())(Arguments::parse($tokens));
        });

        return [$exitCode, $output];
    }

    /** The minute this tick will land in — the slot every assertion here is about. */
    private function currentMinute(): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        return $now->setTime((int) $now->format('G'), (int) $now->format('i'));
    }

    /** An expression that cannot match this minute, whenever the suite happens to run. */
    private function notThisMinute(): string
    {
        return sprintf('%d * * * *', ((int) (new DateTimeImmutable())->format('i') + 30) % 60);
    }

    public function testRunsADueJobAndRecordsIt(): void
    {
        $this->install();
        $ran = false;

        Scheduler::job('sessions:prune', '* * * * *', function () use (&$ran) {
            $ran = true;
        });

        [$exitCode, $output] = $this->tick();

        self::assertSame(0, $exitCode);
        self::assertTrue($ran);
        self::assertStringContainsString('sessions:prune: ran in', $output);

        $row = (new JobLedger())->lastRun('sessions:prune');

        self::assertSame(RunState::Completed->value, $row['state']);
        self::assertSame($this->currentMinute()->format('Y-m-d H:i:s'), $row['due_at']);
    }

    public function testLeavesAJobThatIsNotDueAlone(): void
    {
        $this->install();

        Scheduler::job('reports:build', $this->notThisMinute(), static fn () => null);

        [$exitCode, $output] = $this->tick();

        self::assertSame(0, $exitCode);
        self::assertSame('', $output, 'A tick with nothing to do must be silent, or every cron email becomes noise.');
        self::assertNull((new JobLedger())->lastRun('reports:build'));
    }

    /**
     * The kernel catches Throwable, prints one line and abandons the process — so a job that
     * throws must be caught here, or it takes every later job in the tick down with it.
     */
    public function testAFailingJobIsRecordedAndTheTickCarriesOn(): void
    {
        $this->install();
        $secondRan = false;

        Scheduler::job('first', '* * * * *', static function () {
            throw new RuntimeException('The report server said no.');
        });
        Scheduler::job('second', '* * * * *', function () use (&$secondRan) {
            $secondRan = true;
        });

        [$exitCode, $output] = $this->tick();

        self::assertSame(1, $exitCode);
        self::assertTrue($secondRan, 'A failing job must not stop the ones registered after it.');
        self::assertStringContainsString('first: failed after', $output);
        self::assertStringContainsString('The report server said no.', $output);
        self::assertStringContainsString('second: ran in', $output);

        $ledger = new JobLedger();

        self::assertSame(RunState::Failed->value, $ledger->lastRun('first')['state']);
        self::assertSame('The report server said no.', $ledger->lastRun('first')['failure_reason']);
        self::assertSame(RunState::Completed->value, $ledger->lastRun('second')['state']);
    }

    /**
     * The multi-node guarantee as the command sees it: a second tick for the same minute —
     * another node's crontab, or an operator running it by hand — must not run the job twice.
     */
    public function testASecondTickInTheSameMinuteDoesNotRunTheJobAgain(): void
    {
        $this->install();
        $runs = 0;

        Scheduler::job('sessions:prune', '* * * * *', function () use (&$runs) {
            $runs++;
        });

        $this->tick();
        [$exitCode, $output] = $this->tick();

        self::assertSame(1, $runs);
        self::assertSame(0, $exitCode);
        self::assertSame('', $output, 'Losing a claim is the normal steady state on a cluster, and says nothing.');
    }

    public function testStandsDownWhileThePreviousRunIsStillGoing(): void
    {
        $this->install();
        $ran = false;

        Scheduler::job('reports:build', '* * * * *', function () use (&$ran) {
            $ran = true;
        });

        // A run of the previous minute that is still going: claimed, never settled.
        (new JobLedger())->claim('reports:build', $this->currentMinute()->modify('-1 minute'));

        [$exitCode, $output] = $this->tick();

        self::assertSame(0, $exitCode);
        self::assertFalse($ran);
        self::assertStringContainsString('reports:build: skipped, the previous run is still going.', $output);
    }

    /**
     * One SIGKILL would otherwise leave a `running` row behind forever and the job would
     * stand down on every future tick — stopping silently, which is the worst way for a
     * scheduler to fail.
     */
    public function testReapsAnAbandonedRunSaysSoAndThenRunsTheJob(): void
    {
        $this->install();
        $ran = false;

        Scheduler::job('reports:build', '* * * * *', function () use (&$ran) {
            $ran = true;
        }, staleAfterMinutes: 5);

        $ledger = new JobLedger();
        $abandoned = (string) $ledger->claim('reports:build', $this->currentMinute()->modify('-1 hour'));
        DB::update(
            JobLedger::RUNS_TABLE,
            ['started_at' => $this->currentMinute()->modify('-1 hour')->format('Y-m-d H:i:s')],
            ['id' => $abandoned]
        );

        [$exitCode, $output] = $this->tick();

        self::assertSame(1, $exitCode, 'A run that died is a failure, and the exit code is the only signal some operators watch.');
        self::assertStringContainsString('reports:build: 1 abandoned run reaped', $output);
        self::assertTrue($ran, 'Reaping exists so the job can start again.');
    }

    public function testDoesNothingAtAllWhenNoJobsAreRegistered(): void
    {
        // Deliberately not installed: an application that schedules nothing needs no table.
        [$exitCode, $output] = $this->tick();

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
    }

    public function testNamesTheInstallCommandWhenTheTableIsMissing(): void
    {
        Scheduler::job('sessions:prune', '* * * * *', static fn () => null);

        [$exitCode, $output] = $this->tick();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('schedule:install', $output);
        self::assertStringContainsString('not installed', $output);
    }

    public function testVerboseNarratesTheJobsItPassedOver(): void
    {
        $this->install();

        Scheduler::job('reports:build', $this->notThisMinute(), static fn () => null);

        [$exitCode, $output] = $this->tick(['--verbose']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('reports:build: not due at', $output);
    }

    public function testVerboseReportsAClaimLostToAnotherNode(): void
    {
        $this->install();

        Scheduler::job('sessions:prune', '* * * * *', static fn () => null);

        // Another node took this minute's slot and has already finished — so the slot is
        // gone but nothing is in flight, which is the path the claim itself has to catch.
        $ledger = new JobLedger();
        $ledger->complete((string) $ledger->claim('sessions:prune', $this->currentMinute()), 3);

        [$exitCode, $output] = $this->tick(['--verbose']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('another node claimed', $output);
    }
}
