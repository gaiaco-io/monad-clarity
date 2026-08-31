<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Scheduler;

use Closure;

/**
 * One registered job: what to call, when, and how long it may run before a tick concludes
 * the process running it has died.
 *
 * `$staleAfterMinutes` is per job rather than a single scheduler-wide setting because a
 * four-hour report and a ten-second sweep cannot share one threshold. Set it globally to
 * suit the sweep and the report gets reaped while it is still working — and then a second
 * copy of it starts, which is the exact failure the in-flight check exists to prevent.
 *
 * @package Monad\Clarity\Services\Scheduler
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class ScheduledJob
{
    /**
     * Names are lowercase identifiers, grouped like the command names they sit beside.
     *
     * The rule is not tidiness. `scheduled_runs.job` carries whatever collation its server
     * gives the column, and MySQL's default is case- and accent-insensitive — so
     * `reports:build` and `Reports:Build` register as two jobs here, where PHP array keys are
     * case-sensitive, and then collide on one slot in the database, where they are the same
     * string. One of the two silently stops running, on MySQL only. Refusing the ambiguity at
     * registration is cheaper and more honest than carrying a collation caveat an application
     * cannot see.
     */
    private const NAME_PATTERN = '/^[a-z0-9]+(?:[:._-][a-z0-9]+)*$/';

    public function __construct(
        public string $name,
        public CronExpression $expression,
        public Closure $work,
        public int $staleAfterMinutes = 60,
    ) {
        if ($this->name === '') {
            throw new SchedulerException('A scheduled job needs a name — it is how its run records are identified.');
        }

        if (preg_match(self::NAME_PATTERN, $this->name) !== 1) {
            throw new SchedulerException(sprintf(
                '"%s" is not a usable job name. Names must be lowercase letters and digits, grouped with '
                . '":", "-", "." or "_", like the command names they sit beside — "sessions:prune", '
                . '"reports:build". Two names differing only in case or accent would be two jobs here and '
                . 'one row in the database, and one of them would silently never run.',
                $this->name
            ));
        }

        if ($this->staleAfterMinutes < 1) {
            throw new SchedulerException(sprintf(
                'Job "%s" was given a staleness window of %d minutes. It must be at least 1: a window of '
                . 'zero would reap the run the moment it started and then let a second copy of it begin.',
                $this->name,
                $this->staleAfterMinutes
            ));
        }
    }
}
