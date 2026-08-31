<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Scheduler;
use Monad\Clarity\Services\Scheduler\JobLedger;
use Monad\Clarity\Services\Scheduler\RunState;
use Monad\Clarity\Services\Scheduler\ScheduledJob;

/**
 * `php mitosis schedule:list` — the schedule, made inspectable without reading
 * `app/routes/cli.php`. One line per registered job: its name, its expression, and how its
 * last run went.
 *
 * **It always prints.** That is the opposite of `schedule:run`, deliberately and for the
 * reason that command is silent: `schedule:run` sits on a crontab where every line becomes an
 * email, while this one is only ever typed by a person who has just asked a question. An
 * answer of nothing at all is no answer.
 *
 * **It degrades honestly rather than refusing.** Expressions come from the registry and need
 * no database, so a missing `scheduled_runs` table costs the operator the third column and
 * nothing else — the schedule is still real, and still worth showing. One line says the
 * history is unavailable and names the install command, and the exit code stays 0: the
 * command answered what it was asked. `schedule:run` is the one that fails loudly on a
 * missing table, because a heartbeat that cannot claim a slot has nothing left to do.
 *
 * It exits 0 in every case for the same reason. A job that failed at 03:15 is a fact this
 * report exists to state clearly, not a failure of the reporting.
 *
 * There is no "next due" column. `Scheduler\CronExpression` has no `nextRunAt()` on purpose —
 * a next-occurrence search is a materially harder thing to prove correct than a predicate —
 * and a column that would have to guess is worse than one that is absent.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class ScheduleList implements Command
{
    /**
     * How much of a failure reason one row carries. Reasons are exception messages: they can
     * be paragraphs, and a wrapped line takes the whole table's alignment with it. 100 is
     * chosen so `JobLedger::ABANDONED` — 94 characters, and the most common reason any
     * operator will read here, since the framework writes it itself — survives whole. Cutting
     * is always visible: the row ends in an ellipsis, never in a quietly shortened sentence.
     */
    private const REASON_WIDTH = 100;

    public function __invoke(Arguments $arguments): int
    {
        $context = $arguments->option('context');
        $context = is_string($context) ? $context : null;

        $jobs = Scheduler::jobs();

        if ($jobs === []) {
            Console::info('No scheduled jobs are registered. Register them in app/routes/cli.php with Scheduler::job().');

            return 0;
        }

        $ledger = Schema::hasTable(JobLedger::RUNS_TABLE, $context) ? new JobLedger($context) : null;

        if ($ledger === null) {
            Console::info(sprintf(
                'Run history is unavailable: the `%s` table does not exist yet, so the last column is '
                . 'blank. The schedule itself is real — run `php mitosis schedule:install` once to '
                . 'start recording what becomes of it.',
                JobLedger::RUNS_TABLE
            ));
        }

        $nameWidth = self::widest(array_map(static fn (ScheduledJob $job): string => $job->name, $jobs));
        $expressionWidth = self::widest(array_map(static fn (ScheduledJob $job): string => $job->expression->expression, $jobs));

        foreach ($jobs as $job) {
            // Without the table there is nothing to say about this job's history — neither
            // that it ran nor that it never has — so the column is left empty rather than
            // filled with a claim the database was never asked to support.
            [$state, $lastRun] = $ledger === null ? [null, ''] : self::describe($ledger->lastRun($job->name));

            $line = rtrim(sprintf(
                '%s  %s  %s',
                self::pad($job->name, $nameWidth),
                self::pad($job->expression->expression, $expressionWidth),
                $lastRun
            ));

            match ($state) {
                RunState::Completed => Console::success($line),
                RunState::Failed => Console::error($line),
                default => Console::info($line),
            };
        }

        return 0;
    }

    /**
     * What became of a job's most recent run, as a state to colour the row by and a phrase to
     * end it with.
     *
     * `started_at` is the moment shown for all three outcomes: `finished_at` is null while a
     * run is in flight, so it cannot be the one column every row shares.
     *
     * @param array<string, mixed>|null $run
     * @return array{0: RunState|null, 1: string}
     */
    private static function describe(?array $run): array
    {
        if ($run === null) {
            return [null, 'never run'];
        }

        $state = RunState::tryFrom((string) $run['state']);
        $startedAt = (string) $run['started_at'];

        return match ($state) {
            RunState::Completed => [$state, sprintf('ran at %s in %dms', $startedAt, (int) $run['duration_ms'])],
            RunState::Failed => [$state, sprintf('failed at %s — %s', $startedAt, self::reason((string) ($run['failure_reason'] ?? '')))],
            RunState::Running => [$state, sprintf('running since %s', $startedAt)],
            default => [null, sprintf('recorded at %s in an unrecognised state, "%s"', $startedAt, (string) $run['state'])],
        };
    }

    /**
     * Exception messages arrive with newlines in them, and one of those does more damage to a
     * table than any length ever could — so whitespace is collapsed first, and only then is
     * the reason cut to a width a row can carry.
     */
    private static function reason(string $reason): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $reason));

        return mb_strlen($collapsed) > self::REASON_WIDTH
            ? mb_substr($collapsed, 0, self::REASON_WIDTH - 1) . '…'
            : $collapsed;
    }

    /**
     * @param array<string> $values
     */
    private static function widest(array $values): int
    {
        return max(array_map(mb_strlen(...), $values));
    }

    /**
     * `str_pad()` counts bytes, and a job name with an accent in it would take the whole
     * table out of alignment. Columns are measured in characters, as the reader sees them.
     */
    private static function pad(string $value, int $width): string
    {
        return $value . str_repeat(' ', max(0, $width - mb_strlen($value)));
    }
}
