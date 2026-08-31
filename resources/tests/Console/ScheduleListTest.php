<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use DateTimeImmutable;
use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\ScheduleInstall;
use Monad\Clarity\Console\ScheduleList;
use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Scheduler;
use Monad\Clarity\Services\Scheduler\JobLedger;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class ScheduleListTest extends TestCase
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
    private function list(array $tokens = []): array
    {
        $exitCode = null;

        $output = $this->capture(function () use ($tokens, &$exitCode) {
            $exitCode = (new ScheduleList())(Arguments::parse($tokens));
        });

        return [$exitCode, $output];
    }

    /**
     * The rendered rows with their colour codes removed — the alignment assertions are about
     * what the reader sees, and an escape sequence occupies no column.
     *
     * @return list<string>
     */
    private function lines(string $output): array
    {
        $stripped = (string) preg_replace('/\033\[[0-9;]*m/', '', $output);

        return array_values(array_filter(explode(PHP_EOL, $stripped), static fn (string $line) => $line !== ''));
    }

    private function minute(): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        return $now->setTime((int) $now->format('G'), (int) $now->format('i'));
    }

    /** A run of $job that finished cleanly, taking $durationMs. */
    private function completedRun(string $job, int $durationMs): void
    {
        $ledger = new JobLedger();
        $ledger->complete((string) $ledger->claim($job, $this->minute()), $durationMs);
    }

    private function failedRun(string $job, string $reason): void
    {
        $ledger = new JobLedger();
        $ledger->fail((string) $ledger->claim($job, $this->minute()), 51, $reason);
    }

    /** A run that was claimed and never settled: still going, as far as anyone can tell. */
    private function runningRun(string $job): void
    {
        (new JobLedger())->claim($job, $this->minute());
    }

    public function testAJobThatHasNeverRunSaysSoRatherThanShowingNothing(): void
    {
        $this->install();

        Scheduler::job('reports:build', '0 4 * * MON', static fn () => null);

        [$exitCode, $output] = $this->list();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('reports:build', $output);
        self::assertStringContainsString('0 4 * * MON', $output);
        self::assertStringContainsString('never run', $output);
    }

    public function testACompletedRunShowsWhenItRanAndHowLongItTook(): void
    {
        $this->install();

        Scheduler::job('sessions:prune', '15 3 * * *', static fn () => null);
        $this->completedRun('sessions:prune', 412);

        [$exitCode, $output] = $this->list();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('ran at ' . $this->minute()->format('Y-m-d H:i'), $output);
        self::assertStringContainsString('in 412ms', $output);
    }

    public function testAFailedRunShowsTheReason(): void
    {
        $this->install();

        Scheduler::job('reports:build', '@daily', static fn () => null);
        $this->failedRun('reports:build', 'The report server said no.');

        [$exitCode, $output] = $this->list();

        self::assertSame(
            0,
            $exitCode,
            'A job that failed is a fact this report exists to state, not a failure of the reporting.'
        );
        self::assertStringContainsString('failed at ' . $this->minute()->format('Y-m-d H:i'), $output);
        self::assertStringContainsString('The report server said no.', $output);
    }

    public function testARunStillInFlightIsShownAsRunning(): void
    {
        $this->install();

        Scheduler::job('reports:build', '@hourly', static fn () => null);
        $this->runningRun('reports:build');

        [$exitCode, $output] = $this->list();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('running since ' . $this->minute()->format('Y-m-d H:i'), $output);
    }

    /**
     * The whole point of the padding: names and expressions of wildly different lengths still
     * put the last column in one place, so the eye can run down it.
     */
    public function testColumnsAlignAcrossJobsOfVeryDifferentLengths(): void
    {
        $this->install();

        Scheduler::job('a', '@daily', static fn () => null);
        Scheduler::job('reports:build-the-quarterly-summary', '*/10 * * * MON-FRI', static fn () => null);
        Scheduler::job('caches:sweep', '0 * * * *', static fn () => null);

        [$exitCode, $output] = $this->list();
        $lines = $this->lines($output);

        self::assertSame(0, $exitCode);
        self::assertCount(3, $lines);

        $offsets = array_map(static function (string $line): int {
            $offset = mb_strpos($line, 'never run');
            self::assertNotFalse($offset, 'Every row must carry a last column for its offset to mean anything.');

            return $offset;
        }, $lines);

        self::assertSame([$offsets[0]], array_values(array_unique($offsets)), 'Every row must start its last column in the same place.');
    }

    public function testJobsAreListedInRegistrationOrder(): void
    {
        $this->install();

        Scheduler::job('zebra', '@daily', static fn () => null);
        Scheduler::job('antelope', '@daily', static fn () => null);

        $lines = $this->lines($this->list()[1]);

        self::assertStringContainsString('zebra', $lines[0]);
        self::assertStringContainsString('antelope', $lines[1]);
    }

    /**
     * The expressions need no database, so a missing table costs the operator one column and
     * not the answer. It is `schedule:run` that fails loudly here, because a heartbeat that
     * cannot claim a slot has nothing left it can do.
     */
    public function testListsTheScheduleAndFlagsMissingHistoryWhenTheTableIsAbsent(): void
    {
        Scheduler::job('sessions:prune', '15 3 * * *', static fn () => null);

        [$exitCode, $output] = $this->list();

        self::assertSame(0, $exitCode, 'The command answered the question it was asked.');
        self::assertStringContainsString('Run history is unavailable', $output);
        self::assertStringContainsString('schedule:install', $output);
        self::assertStringContainsString('sessions:prune', $output);
        self::assertStringContainsString('15 3 * * *', $output);
        self::assertStringNotContainsString('never run', $output, 'With no table there is nothing to say about history, one way or the other.');
    }

    public function testTheMissingTableIsMentionedOnceRatherThanOnEveryRow(): void
    {
        Scheduler::job('sessions:prune', '15 3 * * *', static fn () => null);
        Scheduler::job('caches:sweep', '@hourly', static fn () => null);
        Scheduler::job('reports:build', '@daily', static fn () => null);

        $output = $this->list()[1];

        self::assertSame(1, substr_count($output, 'Run history is unavailable'));
        self::assertCount(4, $this->lines($output), 'One notice, then one line per job.');
    }

    public function testSaysWhereToRegisterJobsWhenNoneAre(): void
    {
        [$exitCode, $output] = $this->list();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No scheduled jobs are registered', $output);
        self::assertStringContainsString('app/routes/cli.php', $output);
        self::assertStringContainsString('Scheduler::job()', $output);
    }

    /**
     * A reason is an exception message and can be a paragraph. One of those would wrap and
     * take the table's alignment with it — so it is cut, and cut visibly.
     */
    public function testAVeryLongFailureReasonIsTruncatedVisibly(): void
    {
        $this->install();

        Scheduler::job('reports:build', '@daily', static fn () => null);
        $this->failedRun('reports:build', 'The report server said no, at length: ' . str_repeat('and again ', 40));

        $output = $this->list()[1];

        self::assertStringContainsString('The report server said no, at length:', $output);
        self::assertStringContainsString('…', $output, 'Cutting must be visible, never silent.');
        self::assertLessThan(200, mb_strlen($this->lines($output)[0]));
    }

    /**
     * A newline in a reason does more damage than any length: it makes one job two rows.
     */
    public function testANewlineInAFailureReasonIsCollapsedRatherThanBreakingTheTable(): void
    {
        $this->install();

        Scheduler::job('reports:build', '@daily', static fn () => null);
        $this->failedRun('reports:build', "The report server said no.\n  It did not elaborate.");

        $output = $this->list()[1];

        self::assertCount(1, $this->lines($output));
        self::assertStringContainsString('The report server said no. It did not elaborate.', $output);
    }

    /**
     * The reaped-run message the framework writes itself is the reason an operator will read
     * most often, so it is the one the width is chosen around.
     */
    public function testTheFrameworksOwnReapedRunMessageSurvivesWhole(): void
    {
        $this->install();

        Scheduler::job('reports:build', '@daily', static fn () => null);
        $this->failedRun('reports:build', JobLedger::ABANDONED);

        self::assertStringContainsString(JobLedger::ABANDONED, $this->list()[1]);
    }

    /**
     * A hand-edited row must not turn a read-only report into an UnhandledMatchError.
     */
    public function testAnUnrecognisedStateIsReportedRatherThanCrashing(): void
    {
        $this->install();

        Scheduler::job('reports:build', '@daily', static fn () => null);
        $this->runningRun('reports:build');
        DB::update(JobLedger::RUNS_TABLE, ['state' => 'inconceivable'], ['job' => 'reports:build']);

        [$exitCode, $output] = $this->list();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('unrecognised state, "inconceivable"', $output);
    }

    public function testTheCommandIsRegisteredUnderItsStableName(): void
    {
        $this->install();

        Scheduler::job('sessions:prune', '15 3 * * *', static fn () => null);

        $output = $this->capture(static fn () => Console::run(['mitosis', 'schedule:list']));

        self::assertStringContainsString('sessions:prune', $output);
        self::assertStringContainsString('15 3 * * *', $output);
    }
}
