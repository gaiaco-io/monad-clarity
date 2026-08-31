<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Scheduler;

use DateTimeImmutable;
use Monad\Clarity\Console\ScheduleInstall;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Scheduler\JobLedger;
use Monad\Clarity\Services\Scheduler\RunState;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class JobLedgerTest extends TestCase
{
    private string $databaseFile;

    /**
     * A temp **file** database, not `sqlite::memory:`. The claim is a race between two
     * connections, and two in-memory handles are two separate databases — a lock test
     * against those would pass while proving nothing at all.
     */
    #[Before]
    public function setUpSharedDatabase(): void
    {
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'clarity-scheduler-') . '.sqlite';

        DB::useConnection(new PDO('sqlite:' . $this->databaseFile));
        Schema::createTable(JobLedger::RUNS_TABLE, ScheduleInstall::runsBlueprint());
    }

    #[After]
    public function resetState(): void
    {
        DB::reset();

        if (is_file($this->databaseFile)) {
            unlink($this->databaseFile);
        }
    }

    private function moment(string $moment = '2026-03-04 03:15:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($moment);
    }

    public function testClaimWritesARunningRowAndReturnsItsId(): void
    {
        $runId = (new JobLedger())->claim('sessions:prune', $this->moment());

        self::assertNotNull($runId);

        $row = (new JobLedger())->lastRun('sessions:prune');

        self::assertNotNull($row);
        self::assertSame($runId, $row['id']);
        self::assertSame('sessions:prune', $row['job']);
        self::assertSame('2026-03-04 03:15:00', $row['due_at']);
        self::assertSame(RunState::Running->value, $row['state']);
        self::assertNull($row['finished_at']);
        self::assertNull($row['duration_ms']);
        self::assertNull($row['failure_reason']);
    }

    /**
     * The guarantee the whole service rests on, exercised the only way it can honestly be:
     * two connections to one database, racing for one slot.
     */
    public function testExactlyOneOfTwoNodesClaimsTheSameSlot(): void
    {
        DB::useConnection(new PDO('sqlite:' . $this->databaseFile), 'nodeA');
        DB::useConnection(new PDO('sqlite:' . $this->databaseFile), 'nodeB');

        $dueAt = $this->moment();

        $claimedByA = (new JobLedger('nodeA'))->claim('reports:build', $dueAt);
        $claimedByB = (new JobLedger('nodeB'))->claim('reports:build', $dueAt);

        self::assertNotNull($claimedByA);
        self::assertNull($claimedByB, 'The second node must lose the slot, not run the job a second time.');

        DB::run(sprintf('SELECT id FROM %s WHERE job = ?', JobLedger::RUNS_TABLE), ['reports:build']);

        self::assertCount(1, DB::fetchAll());
    }

    public function testADifferentMinuteIsADifferentSlot(): void
    {
        $ledger = new JobLedger();

        self::assertNotNull($ledger->claim('caches:sweep', $this->moment('2026-03-04 03:15:00')));
        self::assertNotNull($ledger->claim('caches:sweep', $this->moment('2026-03-04 03:16:00')));
    }

    /**
     * A missing table is not a lost claim. Reading it as one would turn a forgotten
     * `schedule:install` into a scheduler that silently never runs anything.
     */
    public function testANonIntegrityFailurePropagatesRatherThanReadingAsALostClaim(): void
    {
        Schema::dropTable(JobLedger::RUNS_TABLE);

        $this->expectException(PDOException::class);

        (new JobLedger())->claim('sessions:prune', $this->moment());
    }

    public function testCompleteSettlesTheRunWithItsDuration(): void
    {
        $ledger = new JobLedger();
        $runId = (string) $ledger->claim('sessions:prune', $this->moment());

        $ledger->complete($runId, 42);

        $row = $ledger->lastRun('sessions:prune');

        self::assertSame(RunState::Completed->value, $row['state']);
        self::assertSame(42, (int) $row['duration_ms']);
        self::assertNotNull($row['finished_at']);
        self::assertNull($row['failure_reason']);
    }

    public function testFailRecordsTheReason(): void
    {
        $ledger = new JobLedger();
        $runId = (string) $ledger->claim('sessions:prune', $this->moment());

        $ledger->fail($runId, 7, 'Connection refused.');

        $row = $ledger->lastRun('sessions:prune');

        self::assertSame(RunState::Failed->value, $row['state']);
        self::assertSame('Connection refused.', $row['failure_reason']);
    }

    public function testARunningRowIsInFlightUntilItGoesStale(): void
    {
        $ledger = new JobLedger();
        $ledger->claim('reports:build', $this->moment());

        self::assertTrue($ledger->hasRunInFlight('reports:build', new DateTimeImmutable('-60 minutes')));
        self::assertFalse($ledger->hasRunInFlight('reports:build', new DateTimeImmutable('+1 minute')));
    }

    public function testASettledRunIsNotInFlight(): void
    {
        $ledger = new JobLedger();
        $runId = (string) $ledger->claim('reports:build', $this->moment());
        $ledger->complete($runId, 1);

        self::assertFalse($ledger->hasRunInFlight('reports:build', new DateTimeImmutable('-60 minutes')));
    }

    public function testAnotherJobsRunIsNotThisJobsInFlightRun(): void
    {
        (new JobLedger())->claim('reports:build', $this->moment());

        self::assertFalse((new JobLedger())->hasRunInFlight('caches:sweep', new DateTimeImmutable('-60 minutes')));
    }

    /**
     * Without reaping, one SIGKILL would leave a `running` row behind forever and the job
     * would stand down on every future tick — stopping silently, which is the worst way for
     * a scheduler to fail.
     */
    public function testReapStaleClosesAnAbandonedRunSoTheJobCanStartAgain(): void
    {
        $ledger = new JobLedger();
        $ledger->claim('reports:build', $this->moment());

        $reaped = $ledger->reapStale('reports:build', new DateTimeImmutable('+1 minute'));

        self::assertSame(1, $reaped);

        $row = $ledger->lastRun('reports:build');

        self::assertSame(RunState::Failed->value, $row['state']);
        self::assertSame(JobLedger::ABANDONED, $row['failure_reason']);
        self::assertFalse($ledger->hasRunInFlight('reports:build', new DateTimeImmutable('+1 minute')));
    }

    public function testReapStaleLeavesARunThatIsStillYoungEnoughAlone(): void
    {
        $ledger = new JobLedger();
        $ledger->claim('reports:build', $this->moment());

        self::assertSame(0, $ledger->reapStale('reports:build', new DateTimeImmutable('-60 minutes')));
        self::assertSame(RunState::Running->value, $ledger->lastRun('reports:build')['state']);
    }

    public function testReapStaleLeavesSettledRunsAlone(): void
    {
        $ledger = new JobLedger();
        $runId = (string) $ledger->claim('reports:build', $this->moment());
        $ledger->fail($runId, 1, 'Its own honest failure.');

        self::assertSame(0, $ledger->reapStale('reports:build', new DateTimeImmutable('+1 minute')));
        self::assertSame('Its own honest failure.', $ledger->lastRun('reports:build')['failure_reason']);
    }

    public function testLastRunIsNullBeforeAJobHasEverRun(): void
    {
        self::assertNull((new JobLedger())->lastRun('never:run'));
    }

    public function testPruneRemovesOldSlotsAndKeepsRecentOnes(): void
    {
        $ledger = new JobLedger();
        $ledger->claim('caches:sweep', $this->moment('2026-01-01 00:00:00'));
        $ledger->claim('caches:sweep', $this->moment('2026-03-04 03:15:00'));

        self::assertSame(1, $ledger->prune($this->moment('2026-02-01 00:00:00')));
        self::assertSame('2026-03-04 03:15:00', $ledger->lastRun('caches:sweep')['due_at']);
    }
}
