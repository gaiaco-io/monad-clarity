<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Scheduler;

use DateTimeInterface;

/**
 * A five-field cron expression, reduced at parse time to the set of values each field
 * accepts, and asked exactly one question afterwards: is this minute a match?
 *
 * Written here rather than pulled in as a dependency. Clarity added recurring billing in
 * 1.4.0 with no new Composer package and this is a smaller problem than that one — five
 * integer fields and a well-documented grammar, against a package that would have to be
 * tracked, audited, and kept compatible for the life of a major version.
 *
 * Supported in every field: a star, a number, a range `a-b`, a step (a slash and an
 * interval, applied to a star or to a range), and a comma-separated list of any of those. A
 * step on a single value — `5/15` — is refused rather than guessed at, since it reads as
 * `5-59/15` in Vixie cron and as minute 5 alone under a literal reading.
 * Months accept `JAN`–`DEC` and days-of-week `SUN`–`SAT`, case-insensitively. Day-of-week
 * takes both `0` and `7` for Sunday. The macros `@yearly` (`@annually`), `@monthly`,
 * `@weekly`, `@daily` (`@midnight`) and `@hourly` expand to their conventional equivalents.
 *
 * **Day-of-month and day-of-week are OR'd when both are restricted.** This is the Vixie cron
 * rule and it is genuinely surprising, so it is worth stating: `0 0 13 * FRI` means "the
 * 13th of the month, *and also* every Friday" — not "Friday the 13th". When either field is
 * `*` the two are AND'd, which is why the everyday `15 3 * * *` behaves as anyone would
 * expect. Getting this wrong is silent: the expression parses, and simply fires on the wrong
 * days.
 *
 * There is no `nextRunAt()`. The scheduler only ever asks whether the minute it is standing
 * in matches, and a next-occurrence search is a materially harder thing to prove correct
 * than a predicate. It is not needed, so it is not here.
 *
 * @package Monad\Clarity\Services\Scheduler
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class CronExpression
{
    private const MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    private const MONTH_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    private const DAY_NAMES = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    /** Position, label, bounds and name aliases of each field, in the order cron writes them. */
    private const FIELDS = [
        ['minute', 0, 59, []],
        ['hour', 0, 23, []],
        ['day-of-month', 1, 31, []],
        ['month', 1, 12, self::MONTH_NAMES],
        ['day-of-week', 0, 7, self::DAY_NAMES],
    ];

    /**
     * Each field is stored as a lookup set — the accepted value as the key — so isDue() is
     * five array probes rather than five searches.
     *
     * @param array<int, true> $minutes
     * @param array<int, true> $hours
     * @param array<int, true> $daysOfMonth
     * @param array<int, true> $months
     * @param array<int, true> $daysOfWeek
     */
    private function __construct(
        public string $expression,
        private array $minutes,
        private array $hours,
        private array $daysOfMonth,
        private array $months,
        private array $daysOfWeek,
        private bool $dayOfMonthRestricted,
        private bool $dayOfWeekRestricted,
    ) {
    }

    /**
     * @throws SchedulerException When the expression is not five fields, or a field holds
     *         something this grammar does not accept.
     */
    public static function parse(string $expression): self
    {
        $normalised = trim($expression);
        $macro = self::MACROS[strtolower($normalised)] ?? null;
        $fields = preg_split('/\s+/', $macro ?? $normalised, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($fields) !== 5) {
            throw new SchedulerException(sprintf(
                'A cron expression has five fields — minute, hour, day-of-month, month, day-of-week. '
                . '"%s" has %d. Try "15 3 * * *" for 03:15 every day, or a macro like "@daily".',
                $expression,
                count($fields)
            ));
        }

        $sets = [];

        foreach (self::FIELDS as $position => [$label, $min, $max, $names]) {
            $sets[] = self::parseField($fields[$position], $min, $max, $names, $label, $expression);
        }

        return new self(
            $normalised,
            $sets[0],
            $sets[1],
            $sets[2],
            $sets[3],
            self::foldSundaySeven($sets[4]),
            $fields[2] !== '*',
            $fields[4] !== '*',
        );
    }

    public function isDue(DateTimeInterface $moment): bool
    {
        if (
            !isset($this->minutes[(int) $moment->format('i')])
            || !isset($this->hours[(int) $moment->format('G')])
            || !isset($this->months[(int) $moment->format('n')])
        ) {
            return false;
        }

        $dayOfMonthMatches = isset($this->daysOfMonth[(int) $moment->format('j')]);
        $dayOfWeekMatches = isset($this->daysOfWeek[(int) $moment->format('w')]);

        // The Vixie rule — see this class's docblock. Two restricted day fields are
        // alternatives, not conditions to satisfy together.
        if ($this->dayOfMonthRestricted && $this->dayOfWeekRestricted) {
            return $dayOfMonthMatches || $dayOfWeekMatches;
        }

        return $dayOfMonthMatches && $dayOfWeekMatches;
    }

    /**
     * @param array<string, int> $names
     * @return array<int, true>
     */
    private static function parseField(
        string $field,
        int $min,
        int $max,
        array $names,
        string $label,
        string $expression
    ): array {
        $accepted = [];

        foreach (explode(',', $field) as $term) {
            foreach (self::parseTerm($term, $min, $max, $names, $label, $expression) as $value) {
                $accepted[$value] = true;
            }
        }

        return $accepted;
    }

    /**
     * @param array<string, int> $names
     * @return list<int>
     */
    private static function parseTerm(
        string $term,
        int $min,
        int $max,
        array $names,
        string $label,
        string $expression
    ): array {
        $step = 1;
        $range = $term;

        if (str_contains($term, '/')) {
            [$range, $rawStep] = explode('/', $term, 2);

            if (!self::isUnsignedInteger($rawStep) || (int) $rawStep < 1) {
                throw self::malformed($term, $label, $min, $max, $expression);
            }

            $step = (int) $rawStep;

            if ($range !== '*' && !str_contains($range, '-')) {
                // Vixie reads "5/15" as "5-59/15". Reading it as "just minute 5" — which is
                // what a naive range of 5 to 5 produces — is a silently narrowed schedule,
                // and nobody would notice. Rejected rather than guessed at.
                throw new SchedulerException(sprintf(
                    '"%s" in "%s" applies a step to a single value, which is ambiguous: it could mean '
                    . 'that value alone, or every %d from it to the end of the field. Write the range '
                    . 'you mean — "%s-%d/%d" — or drop the step.',
                    $term,
                    $expression,
                    $step,
                    $range,
                    $max,
                    $step
                ));
            }
        }

        if ($range === '*') {
            $from = $min;
            $to = $max;
        } elseif (str_contains($range, '-')) {
            [$rawFrom, $rawTo] = explode('-', $range, 2);
            $from = self::value($rawFrom, $names);
            $to = self::value($rawTo, $names);

            if ($from === null || $to === null || $from > $to) {
                throw self::malformed($term, $label, $min, $max, $expression);
            }
        } else {
            $from = self::value($range, $names);
            $to = $from;
        }

        if ($from === null || $to === null || $from < $min || $to > $max) {
            throw self::malformed($term, $label, $min, $max, $expression);
        }

        $values = [];

        for ($value = $from; $value <= $to; $value += $step) {
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, int> $names
     */
    private static function value(string $token, array $names): ?int
    {
        $named = $names[strtoupper($token)] ?? null;

        if ($named !== null) {
            return $named;
        }

        return self::isUnsignedInteger($token) ? (int) $token : null;
    }

    private static function isUnsignedInteger(string $token): bool
    {
        return $token !== '' && ctype_digit($token);
    }

    /**
     * Cron accepts both 0 and 7 for Sunday. Folding 7 down here means isDue() can compare
     * against PHP's `w`, which only ever produces 0.
     *
     * @param array<int, true> $daysOfWeek
     * @return array<int, true>
     */
    private static function foldSundaySeven(array $daysOfWeek): array
    {
        if (isset($daysOfWeek[7])) {
            unset($daysOfWeek[7]);
            $daysOfWeek[0] = true;
        }

        return $daysOfWeek;
    }

    private static function malformed(
        string $term,
        string $label,
        int $min,
        int $max,
        string $expression
    ): SchedulerException {
        return new SchedulerException(sprintf(
            '"%s" is not something the %s field of "%s" can accept. That field takes %d-%d, '
            . 'as a number, a range like %d-%d, a step like */2, or a comma-separated list of those.',
            $term,
            $label,
            $expression,
            $min,
            $max,
            $min,
            $max
        ));
    }
}
