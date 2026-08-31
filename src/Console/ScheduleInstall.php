<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Closure;
use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Scheduler\JobLedger;
use Monad\Clarity\Services\Schema\Blueprint;

/**
 * `php mitosis schedule:install` — creates the one table `Services\Scheduler` needs, the
 * run record that doubles as its cluster-wide lock.
 *
 * Separate from `setup` for the reason `checkout:install` is: the setup-owned compatibility
 * surface is exactly `sessions` and `caches` (CrossRepoContracts.md §8), and an application
 * that schedules nothing has no business carrying a table it never reads. A command rather
 * than a shipped migration because `resources/` is export-ignored from the Packagist dist,
 * so a migration file there would never reach anyone who installs Clarity through Composer.
 *
 * Re-runnable, guarded on `hasTable` rather than on the DDL's own `IF NOT EXISTS` — that
 * clause covers the table but not the indexes createTable() then emits as separate
 * statements, and MySQL has no `CREATE INDEX IF NOT EXISTS` to make those idempotent.
 *
 * The blueprint below is the single canonical definition of the scheduler schema; the tests
 * exercise this same closure rather than a hand-maintained copy that could drift from it.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class ScheduleInstall implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $context = $arguments->option('context');
        $context = is_string($context) ? $context : null;

        if (Schema::hasTable(JobLedger::RUNS_TABLE, $context)) {
            Console::success(sprintf('Scheduler is already installed: `%s` was already present.', JobLedger::RUNS_TABLE));

            return 0;
        }

        Schema::createTable(JobLedger::RUNS_TABLE, self::runsBlueprint(), $context);

        Console::success(sprintf(
            'Scheduler install complete: `%s` created. Add one line to the system crontab and the '
            . 'schedule in app/routes/cli.php takes it from there:'."\n"
            . '    * * * * * cd %s && php mitosis schedule:run',
            JobLedger::RUNS_TABLE,
            getcwd()
        ));

        return 0;
    }

    /**
     * One row per attempted run of one job.
     *
     * The unique index is the whole design. It is not there to dedupe a redelivery, as the
     * checkout indexes are — it is the mutex: two nodes whose crontabs fire in the same
     * minute both try to insert `(job, due_at)`, and exactly one succeeds. Losing that index
     * does not degrade the scheduler, it silently doubles every job on every node.
     *
     * `job` is 128 rather than the default 255 so `(job, due_at)` stays comfortably inside
     * MySQL's index-length limit under utf8mb4.
     *
     * @return Closure(Blueprint): void
     */
    public static function runsBlueprint(): Closure
    {
        return static function (Blueprint $table) {
            $table->id();
            $table->string('job', 128);
            $table->datetime('due_at');
            $table->string('state', 16);
            $table->datetime('started_at');
            $table->datetime('finished_at', nullable: true);
            $table->integer('duration_ms', nullable: true);
            $table->text('failure_reason', nullable: true);
            $table->datetime('created_at');
            $table->datetime('updated_at');

            // The mutex. One node per job per minute, cluster-wide.
            $table->unique(['job', 'due_at'], 'uq_scheduled_runs_job_due_at');
            // The in-flight probe and the reaper, both of which ask for one job's running rows.
            $table->index(['job', 'state'], 'idx_scheduled_runs_job_state');
            // prune().
            $table->index('due_at', 'idx_scheduled_runs_due_at');
        };
    }
}
