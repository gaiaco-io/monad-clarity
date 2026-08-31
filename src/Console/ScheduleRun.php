<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use DateTimeImmutable;
use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Scheduler;
use Monad\Clarity\Services\Scheduler\JobLedger;
use Monad\Clarity\Services\Scheduler\ScheduledJob;
use Throwable;

/**
 * `php mitosis schedule:run` — the heartbeat. One crontab line calls this every minute; it
 * works out which registered jobs are due, claims each one for the cluster, and runs them.
 *
 * **It prints nothing when nothing happened.** Every line this command writes becomes a cron
 * email, and a heartbeat that greets the operator sixty times an hour teaches them to filter
 * it — after which the one line that mattered is filtered too. A tick where nothing was due,
 * or where every due job was already claimed by another node, exits 0 in silence. Ran,
 * failed, stood down, or reaped: one line each. `--verbose` narrates the whole tick, for a
 * human running it by hand.
 *
 * Which is why the crontab line is documented **without** `> /dev/null 2>&1`. Silence is the
 * signal here, and the kernel writes errors to stdout like everything else, so the reflexive
 * redirect throws away the failures along with the quiet.
 *
 * Three timing decisions, each chosen rather than fallen into:
 *
 *   **Only the current minute is evaluated.** If every node was down from 03:10 to 03:20,
 *   the 03:15 job does not run at 03:21. A catch-up window would make this a queue, and a
 *   scheduler is not a queue: the missed work is usually stale by the time anyone notices,
 *   and "run all of it at once, now" is rarely what the operator wanted. The honest cost is
 *   that a tick starting more than 60 seconds late skips a minute entirely.
 *
 *   **Expressions are read on PHP's configured timezone** — `date_default_timezone_get()`,
 *   which a skeleton app sets from its `.env`. Not UTC by decree, and not the OS zone either:
 *   whatever PHP is configured with is what `due_at` is written in, so it must be aligned with
 *   the zone the system cron fires in. They are not the same setting and nothing reconciles
 *   them. Fixing the clock to UTC instead was rejected because it would break the
 *   `(job, due_at)` key across a daylight-saving boundary, and because a schedule an operator
 *   cannot read in local time is a schedule they will misread.
 *
 *   **Daylight saving falls out of that key, correctly.** On the day the clocks go back,
 *   local 02:30 happens twice and both occurrences share one `due_at` — so the second
 *   collides with the first's claim and stands down. On the day they go forward, 02:30 never
 *   happens and a job scheduled for it is skipped that day.
 *
 * Every job is run inside its own try/catch. The kernel catches Throwable, prints
 * getMessage() with no trace, and abandons the process — so an escaping exception would kill
 * every later job in the tick and reduce the operator's diagnostic to a single line. A
 * failure here is recorded against its own run row and the tick carries on.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class ScheduleRun implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $context = $arguments->option('context');
        $context = is_string($context) ? $context : null;
        $verbose = $arguments->hasOption('verbose');

        $jobs = Scheduler::jobs();

        if ($jobs === []) {
            if ($verbose) {
                Console::info('No scheduled jobs are registered. Register them in app/routes/cli.php with Scheduler::job().');
            }

            return 0;
        }

        // Checked rather than discovered through a PDOException, because that exception
        // would otherwise be raised — correctly, since a missing table is not a lost claim —
        // once every minute for as long as the crontab entry outlives the install step.
        if (!Schema::hasTable(JobLedger::RUNS_TABLE, $context)) {
            Console::error(sprintf(
                'Scheduler is not installed: the `%s` table is missing, so no job can be claimed or '
                . 'recorded. Run `php mitosis schedule:install` once.',
                JobLedger::RUNS_TABLE
            ));

            return 1;
        }

        return self::tick($jobs, self::currentMinute(), new JobLedger($context), $verbose);
    }

    /**
     * @param array<string, ScheduledJob> $jobs
     */
    private static function tick(array $jobs, DateTimeImmutable $dueAt, JobLedger $ledger, bool $verbose): int
    {
        $failed = false;

        foreach ($jobs as $job) {
            if (!$job->expression->isDue($dueAt)) {
                if ($verbose) {
                    Console::info(sprintf('%s: not due at %s (%s).', $job->name, $dueAt->format('H:i'), $job->expression->expression));
                }

                continue;
            }

            $staleBefore = $dueAt->modify(sprintf('-%d minutes', $job->staleAfterMinutes));

            // Before the in-flight check, never after: a run whose process died would
            // otherwise look alive forever and this job would never start again.
            $reaped = $ledger->reapStale($job->name, $staleBefore);

            if ($reaped > 0) {
                Console::error(sprintf(
                    '%s: %d abandoned run%s reaped — started over %d minutes ago and never reported an outcome.',
                    $job->name,
                    $reaped,
                    $reaped === 1 ? '' : 's',
                    $job->staleAfterMinutes
                ));

                $failed = true;
            }

            if ($ledger->hasRunInFlight($job->name, $staleBefore)) {
                Console::info(sprintf('%s: skipped, the previous run is still going.', $job->name));

                continue;
            }

            $runId = $ledger->claim($job->name, $dueAt);

            if ($runId === null) {
                if ($verbose) {
                    Console::info(sprintf('%s: another node claimed %s.', $job->name, $dueAt->format('H:i')));
                }

                continue;
            }

            $failed = !self::execute($job, $runId, $ledger) || $failed;
        }

        return $failed ? 1 : 0;
    }

    /**
     * @return bool Whether the job completed without throwing.
     */
    private static function execute(ScheduledJob $job, string $runId, JobLedger $ledger): bool
    {
        $startedAt = hrtime(true);

        try {
            ($job->work)();
        } catch (Throwable $e) {
            $elapsed = self::elapsedMs($startedAt);
            $ledger->fail($runId, $elapsed, $e->getMessage());
            Console::error(sprintf('%s: failed after %dms — %s', $job->name, $elapsed, $e->getMessage()));

            return false;
        }

        $elapsed = self::elapsedMs($startedAt);
        $ledger->complete($runId, $elapsed);
        Console::success(sprintf('%s: ran in %dms.', $job->name, $elapsed));

        return true;
    }

    /**
     * This minute, with its seconds zeroed — the slot every node must agree on. Two
     * crontabs firing at :07 and :09 past the same minute would otherwise write two
     * different keys, and the unique index that makes this cluster-safe would never collide.
     */
    private static function currentMinute(): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        return $now->setTime((int) $now->format('G'), (int) $now->format('i'));
    }

    private static function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
