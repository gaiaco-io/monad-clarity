<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use Closure;
use DateTimeInterface;
use Monad\Clarity\Services\Scheduler\CronExpression;
use Monad\Clarity\Services\Scheduler\ScheduledJob;
use Monad\Clarity\Services\Scheduler\SchedulerException;

/**
 * The application's schedule, held in code rather than in a crontab.
 *
 * The system cron gets exactly one line, forever:
 *
 *     * * * * * cd /path/to/app && php mitosis schedule:run
 *
 * Everything else lives in `app/routes/cli.php`, which the console kernel loads on every
 * run — so jobs are registered beside the application's custom commands, travel with a
 * deploy, and are visible to code review:
 *
 *     Scheduler::job('sessions:prune', '15 3 * * *', fn () => Session::purgeExpired());
 *     Scheduler::job('invoices:chase', '*' . '/10 * * * *', $billing->chaseOverdue(...));
 *
 * Static, like Route and Console, for the same reason those are: registrations arrive from
 * a routes file at boot and are read by a command the kernel instantiates with no
 * constructor arguments. There is nothing here for an application to construct.
 *
 * This class holds only the schedule. Deciding what is due, claiming it across a cluster,
 * and recording what happened belong to `Console\ScheduleRun` and
 * `Services\Scheduler\JobLedger` — which keeps `due()` a pure function of the registry and
 * the clock, and therefore trivially testable without a database.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class Scheduler
{
    /** @var array<string, ScheduledJob> */
    private static array $jobs = [];

    /**
     * Register work to run on a schedule.
     *
     * Both failure modes throw here, at registration, rather than at the tick that would
     * have run the job. `app/routes/cli.php` is loaded before every command dispatches, so
     * a mistyped expression breaks the next `mitosis` invocation in plain sight instead of
     * producing a job that quietly never fires.
     *
     * @param string $name Identifies this job's run records. Conventionally `group:verb`,
     *        like a console command.
     * @param string $expression Five cron fields, or a macro — see CronExpression.
     * @param int $staleAfterMinutes How long a run of this job may take before a later tick
     *        concludes its process died. Set it comfortably above the job's worst honest
     *        runtime: too low and a slow run is reaped mid-flight, then duplicated.
     * @throws SchedulerException On a malformed expression, or a name already registered.
     */
    public static function job(
        string $name,
        string $expression,
        callable $work,
        int $staleAfterMinutes = 60
    ): void {
        if (isset(self::$jobs[$name])) {
            throw new SchedulerException(sprintf(
                'Two scheduled jobs are both registered as "%s". Names identify run records, so the '
                . 'second would claim the first\'s slot and neither would run reliably.',
                $name
            ));
        }

        self::$jobs[$name] = new ScheduledJob(
            $name,
            CronExpression::parse($expression),
            $work instanceof Closure ? $work : Closure::fromCallable($work),
            $staleAfterMinutes
        );
    }

    /**
     * Every registered job, keyed by name.
     *
     * @return array<string, ScheduledJob>
     */
    public static function jobs(): array
    {
        return self::$jobs;
    }

    /**
     * The jobs whose expression matches this minute. Seconds are ignored — the caller is
     * responsible for zeroing them on the moment it stores as the slot.
     *
     * @return list<ScheduledJob>
     */
    public static function due(DateTimeInterface $moment): array
    {
        return array_values(array_filter(
            self::$jobs,
            static fn (ScheduledJob $job): bool => $job->expression->isDue($moment)
        ));
    }

    public static function reset(): void
    {
        self::$jobs = [];
    }
}
