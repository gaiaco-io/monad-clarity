<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Scheduler;

use DateTimeInterface;
use Monad\Clarity\Services\DB;
use DateTimeImmutable;
use PDOException;
use Ramsey\Uuid\Uuid;

/**
 * The stateful half of the Scheduler: one row per attempted run of one job, and — through
 * the unique index that row is inserted against — the mutex that decides which node runs it.
 *
 * **The claim is the insert.** `claim()` writes a `running` row keyed on
 * `(job, due_at)`; the unique index means the second node to try for the same minute
 * collides and is told, honestly, that it lost. No transaction, no `SELECT ... FOR UPDATE`,
 * no advisory lock — the same shape `Checkout\SubscriptionLedger` already uses to settle two
 * simultaneous first-deliveries, and the only shape that works identically on MySQL,
 * PostgreSQL and SQLite. A lock file could not do this at all: `DeploymentTopology.md` §2
 * promises an application can run across stateless nodes, and local disk is not shared.
 *
 * **What this guarantees, precisely: at most one run per job per minute, cluster-wide.**
 * Not at-least-once. A minute in which every node is down is a minute in which the job does
 * not run, and no later tick makes it up — see `Console\ScheduleRun` for why catching up was
 * rejected.
 *
 * `due_at` is the minute slot with its seconds zeroed, never the wall-clock instant the tick
 * began. Nodes whose crontabs fire a second or two apart must agree on the key, or both
 * inserts succeed and both run the job.
 *
 * Rows carry a UUIDv7 primary key for the same reason the append-only checkout tables do:
 * built-in tables store DATETIME at second precision, so `created_at` alone cannot order
 * rows written within one second, and v7 sorts lexically in generation order.
 *
 * @package Monad\Clarity\Services\Scheduler
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class JobLedger
{
    public const RUNS_TABLE = 'scheduled_runs';

    /** SQLSTATE class for an integrity constraint violation — MySQL, PostgreSQL, SQLite alike. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23';

    /** Recorded against a run whose process vanished without ever reporting an outcome. */
    public const ABANDONED = 'The process running this job stopped without reporting an outcome, and a later tick reaped it.';

    /**
     * @param string|null $context DB connection context, for applications running the
     *        scheduler on a connection other than the default.
     */
    public function __construct(private readonly ?string $context = null)
    {
    }

    /**
     * Take ownership of one job's slot for one minute.
     *
     * @return string|null The run id, or null when another node already holds this slot.
     * @throws PDOException Anything that is not a unique-index collision propagates. A
     *         missing table must never be mistaken for "someone else got there first" —
     *         that would turn a broken install into a scheduler that silently never runs.
     */
    public function claim(string $job, DateTimeInterface $dueAt): ?string
    {
        $now = self::now();
        $runId = Uuid::uuid7()->toString();

        try {
            DB::insert(self::RUNS_TABLE, [
                'id' => $runId,
                'job' => $job,
                'due_at' => self::format($dueAt),
                'state' => RunState::Running->value,
                'started_at' => $now,
                'finished_at' => null,
                'duration_ms' => null,
                'failure_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], DB::ID_TYPE_UUID, $this->context);
        } catch (PDOException $e) {
            if (self::isDuplicate($e)) {
                return null;
            }

            throw $e;
        }

        return $runId;
    }

    /**
     * Whether a run of this job started recently enough to still be plausibly alive.
     *
     * `$staleBefore` splits the `running` rows in two: newer than it means in flight, older
     * means abandoned. reapStale() takes the other half, so the two must always be called
     * with the same threshold or a wedged row would be neither reaped nor respected.
     */
    public function hasRunInFlight(string $job, DateTimeInterface $staleBefore): bool
    {
        DB::run(
            sprintf('SELECT id FROM %s WHERE job = ? AND state = ? AND started_at > ?', self::RUNS_TABLE),
            [$job, RunState::Running->value, self::format($staleBefore)],
            $this->context
        );

        return DB::fetchAll() !== [];
    }

    public function complete(string $runId, int $durationMs): void
    {
        $this->settle($runId, RunState::Completed, $durationMs, null);
    }

    public function fail(string $runId, int $durationMs, string $reason): void
    {
        $this->settle($runId, RunState::Failed, $durationMs, $reason);
    }

    /**
     * Close out runs that started before `$staleBefore` and never reported an outcome.
     *
     * Without this a single SIGKILL — a deploy, an OOM kill, a machine going away — would
     * leave one `running` row behind forever, and the in-flight check would stand down on
     * every future tick. The job would stop running and nothing would say why.
     *
     * @return int How many abandoned runs were closed.
     */
    public function reapStale(string $job, DateTimeInterface $staleBefore): int
    {
        $now = self::now();

        return DB::update(self::RUNS_TABLE, [
            'state' => RunState::Failed->value,
            'finished_at' => $now,
            'failure_reason' => self::ABANDONED,
            'updated_at' => $now,
        ], [
            'job' => $job,
            'state' => RunState::Running->value,
            'started_at' => ['<=', self::format($staleBefore)],
        ], $this->context);
    }

    /**
     * The most recent run of a job, or null if it has never run.
     *
     * @return array<string, mixed>|null
     */
    public function lastRun(string $job): ?array
    {
        DB::run(
            sprintf('SELECT * FROM %s WHERE job = ? ORDER BY due_at DESC, id DESC', self::RUNS_TABLE),
            [$job],
            $this->context
        );

        return DB::fetchAll()[0] ?? null;
    }

    /**
     * Delete run records for slots older than `$before`.
     *
     * Deliberately not a console command. Retention is itself recurring work, so the
     * scheduler sweeps its own history the same way it sweeps anything else — register it
     * as a job and the mechanism proves itself in use.
     *
     * @return int How many records were removed.
     */
    public function prune(DateTimeInterface $before): int
    {
        return DB::delete(self::RUNS_TABLE, ['due_at' => ['<', self::format($before)]], $this->context);
    }

    private function settle(string $runId, RunState $state, int $durationMs, ?string $reason): void
    {
        $now = self::now();

        DB::update(self::RUNS_TABLE, [
            'state' => $state->value,
            'finished_at' => $now,
            'duration_ms' => $durationMs,
            'failure_reason' => $reason,
            'updated_at' => $now,
        ], ['id' => $runId], $this->context);
    }

    private static function isDuplicate(PDOException $e): bool
    {
        return str_starts_with((string) $e->getCode(), self::SQLSTATE_INTEGRITY_VIOLATION);
    }

    /**
     * Server-local time, matching `sessions` and `caches` and — critically — matching the
     * clock the system cron itself reads. See Console\ScheduleRun for what that implies
     * across a daylight-saving boundary.
     */
    private static function format(DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }

    private static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
