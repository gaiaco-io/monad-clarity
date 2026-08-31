# Monad Clarity 1.5.0 Release Notes

**Status:** FROZEN — canonical specification for the 1.5.0 release.
This document is the source of truth for WHAT ships in 1.5.0. It does not restate 1.0.0, 1.2.0,
1.3.0 or 1.4.0; those remain frozen for what they specified.

> **Scope note.** Every release from 1.2.0 to 1.4.0 built inside an abstraction
> `ReleaseNotes_1.0.0.md` §9 had already specified. 1.5.0 is the first to add a service the
> frozen 1.0.0 spec never contemplated at all — see §2.1, which records that decision rather
> than assuming it. Nothing existing changes: no signature moved, no table definition moved,
> and an application that ignores the Scheduler is byte-for-byte unaffected.

## 1. What ships in 1.5.0

### 1.1 `Services\Scheduler`

The application's schedule, held in code rather than in a crontab. The system cron gets exactly
one line, for the life of the application:

```
* * * * * cd /path/to/app && php mitosis schedule:run
```

Everything else lives in `app/routes/cli.php`, which the console kernel already loads before
every dispatch — so jobs are registered beside an application's custom commands, travel with a
deploy, and are visible to code review:

```php
Scheduler::job('sessions:prune', '15 3 * * *', fn () => Session::purgeExpired());
Scheduler::job('invoices:chase', '*/10 * * * *', $billing->chaseOverdue(...));
Scheduler::job('reports:build', '0 4 * * MON', $report(...), staleAfterMinutes: 240);
```

The facade holds the registry and nothing else: `job()`, `jobs()`, `due()`, `reset()`. Deciding
what is due is a pure function of the registry and the clock, which is why it is testable
without a database.

Static, like `Route` and `Console`, for the reason those are: registrations arrive from a
routes file at boot, and are read by a command the kernel instantiates with no constructor
arguments.

### 1.2 `Scheduler\CronExpression`

A five-field cron parser, written in-house — **no new Composer dependency**, as 1.4.0 added
recurring billing without one. Five integer fields against a package that would have to be
tracked, audited and kept compatible for the life of a major version.

Supported in every field: a star, a number, a range `a-b`, a step (a slash and an interval,
applied to a star or a range), and a comma-separated list of any of those. Months take
`JAN`–`DEC`, days-of-week `SUN`–`SAT`, case-insensitively; day-of-week accepts both `0` and `7`
for Sunday. The macros `@yearly` (`@annually`), `@monthly`, `@weekly`, `@daily` (`@midnight`)
and `@hourly` expand to their conventional equivalents.

A step applied to a single value — `5/15` — is refused rather than guessed at. It reads as
`5-59/15` in Vixie cron and as minute 5 alone under a literal reading, and either guess is a
silently wrong schedule of exactly the kind §2.3 is about. The message names the range to
write instead.

There is no `nextRunAt()`. The runner only ever asks whether the minute it is standing in
matches; a next-occurrence search is a materially harder thing to prove correct, and nothing
needs it.

### 1.3 `Scheduler\JobLedger` and the `scheduled_runs` table

One row per attempted run, and — through the unique index that row is inserted against — the
mutex deciding which node runs it. `claim()`, `hasRunInFlight()`, `reapStale()`, `complete()`,
`fail()`, `lastRun()`, `prune()`.

Rows carry a UUIDv7 primary key for the reason the append-only checkout tables do: built-in
tables store `DATETIME` at second precision, so `created_at` alone cannot order rows written
within one second.

`prune()` is deliberately not a command. Retention is itself recurring work, so the scheduler
sweeps its own history the way it sweeps anything else, and the mechanism proves itself in use:

```php
Scheduler::job('scheduler:prune', '@daily', fn () => (new JobLedger())->prune(new DateTimeImmutable('-30 days')));
```

### 1.4 Two commands that run the schedule: `schedule:install` and `schedule:run`

`schedule:install` creates `scheduled_runs`. Opt-in and re-runnable, guarded on `hasTable`
rather than the DDL's own `IF NOT EXISTS` — that clause covers the table but not the indexes,
which `createTable()` emits as separate statements MySQL cannot make idempotent. The blueprint
is a public static method on the command, so the tests exercise the same closure the command
runs rather than a hand-maintained copy that could drift from it.

`schedule:run` is the heartbeat. It works out which registered jobs are due, claims each one
for the cluster, runs them, and records what happened. Every job runs inside its own
`try`/`catch`: the kernel catches `Throwable`, prints `getMessage()` with no trace and abandons
the process, so an escaping exception would kill every later job in the tick and reduce the
operator's diagnostic to one line. `--verbose` narrates the whole tick for a human running it
by hand; `--context` selects a connection, as everywhere else.

### 1.5 One command that reads it: `schedule:list`

An earlier draft of this document listed `schedule:list` under §3, deferred to 1.6.0 on the
argument that adding a stable command name later is semver-minor while removing one is
semver-major. That reasoning was sound and the conclusion was still wrong: it is cheaper to defer
a command than to freeze one, but the schedule was the one thing this release added that an
operator could not see without opening a code file — and §2.5 already calls stopping silently the
worst way for a scheduler to fail. A schedule nobody can read is that same blindness one step
earlier. It ships in 1.5.0, and the reversal is recorded here rather than quietly absorbed, as
§2.1 records the scope expansion.

One line per registered job: its name, its expression, and how its last run went — completed
with its duration, failed with its reason, still running since when, or never run at all.
Columns are padded to the widest entry so the eye can run down them, and a failure reason has
its whitespace collapsed and is cut at 100 characters with an ellipsis, because a reason is an
exception message and one embedded newline turns a row into two.

Two behaviours are worth stating because each is the opposite of what `schedule:run` does:

**It always prints.** §2.8's silence is a property of a command sitting on a crontab, where
every line becomes an email. `schedule:list` is only ever typed by a person who has just asked a
question, and an answer of nothing at all is no answer.

**It degrades rather than refusing when `scheduled_runs` is missing.** Expressions come from the
registry and need no database, so the operator loses the last column and nothing else. One line
says the history is unavailable and names `schedule:install`, and the exit code stays 0 — the
command answered what it was asked. `schedule:run` fails loudly in the same situation because a
heartbeat that cannot claim a slot has nothing left it can do. `schedule:list` exits 0 in every
case for the same reason: a job that failed at 03:15 is a fact this report exists to state, not
a failure of the reporting.

There is no "next due" column. §1.2 keeps `CronExpression` a predicate on purpose, and a column
that would have to guess is worse than one that is absent.

These are the seventeenth, eighteenth and nineteenth built-in commands. `CrossRepoContracts.md`
§3 carries the updated list.

## 2. Resolved specification decisions

### 2.1 A service the frozen 1.0.0 spec never named

`ReleaseNotes_1.0.0.md` §1–§31 does not mention cron, queues, jobs, workers or background work
in any sense, and neither does `PRD.md`'s out-of-scope list nor `Architecture.md` §11. A
Scheduler is not deferred and not reserved — it is unspecified.

§9's "the roster is illustrative" hatch from `ReleaseNotes_1.3.0.md` §2.1 does not reach this:
that hatch operates *within* an abstraction §9 already specified, and permits a gateway the
spec did not name. There is no equivalent for a service the spec never contemplated. So the
scope expansion is recorded here, deliberately, rather than assumed.

The demand was already in the codebase. `Session::purgeExpired()` has carried the docblock *"A
maintenance operation for a scheduled task, not the request path"* since 1.0.0, and its only
caller in the entire repository is its own test. `ReleaseNotes_1.0.0.md` §14.2.7 specifies log
rotation with no runner named anywhere. `caches.expires_at` rows are never swept. The framework
had real recurring work and no way to say *when*.

### 2.2 Monad owns the schedule; cron owns only the heartbeat

The alternative — one crontab entry per job, with Clarity supplying only "run this one job
safely" — needs no cron parser at all, and was rejected anyway. It puts the schedule on the
server rather than in the codebase, where it is invisible to code review, absent from a deploy,
and has to be edited by hand on every node of a cluster. A single heartbeat entry means adding
or retiming a job is a code change, which is what makes it deployable.

The cost is honest: a parser to write and to keep correct. §1.2 is that parser, and its tests
are the evidence.

### 2.3 Day-of-month and day-of-week are OR'd when both are restricted

The Vixie cron rule, and genuinely surprising: `0 0 13 * FRI` means "the 13th of the month, *and
also* every Friday" — not "Friday the 13th". When either field is a star the two are AND'd,
which is why the everyday `15 3 * * *` behaves as anyone would expect.

It is called out here because getting it wrong is silent. The expression parses either way and
simply fires on the wrong days, which is the kind of defect that survives a release.

### 2.4 The claim is an INSERT, and it guarantees at-most-once — not at-least-once

`DeploymentTopology.md` §2 obliges any new stateful feature to have a shared-backend story
before it is production-ready. A lock file under `storage/` is per-node, so on a three-node
cluster every due job would fire three times. The lock therefore has to be in the database.

An atomic `INSERT` against a unique index on `(job, due_at)` was chosen over a conditional
`UPDATE` claim. It needs no transaction — there is no transactional write path anywhere in
`src/`, and `DB::beginTransaction()` had no call sites before this release or after it — and it
sidesteps MySQL counting *changed* rather than *matched* rows in `rowCount()`, since
`PDO::MYSQL_ATTR_FOUND_ROWS` is not among `DB`'s base options. `SELECT ... FOR UPDATE` is
doubly out: no precedent in the codebase, and SQLite, which the whole suite runs on, does not
support it. The shape is the one `Checkout\SubscriptionLedger` already uses to settle two
simultaneous first-deliveries.

**What that guarantees, precisely: at most one run per job per minute, cluster-wide.** Not
at-least-once. A minute in which every node is down is a minute in which the job does not run.
Stated plainly here because the weaker half of the guarantee is the half an operator will
eventually depend on — the same honesty `ReleaseNotes_1.4.0.md` §2.5 applied to
`checkout_subscriptions`.

A consequence worth naming: a non-integrity `PDOException` from `claim()` propagates rather
than being read as a lost claim. Treating a missing table as "another node got there first"
would turn a forgotten `schedule:install` into a scheduler that silently never runs anything —
so `schedule:run` checks for the table up front and names the install command instead.

### 2.5 Overlap is prevented, and a dead run is reaped

The unique index stops two nodes running the same slot. It does nothing about a job whose 03:00
run is still going when 03:01 arrives — that is a different `due_at`, so the insert succeeds and
the two run concurrently. `sessions:prune` overlapping itself corrupts its own counts, so the
in-flight check is a separate, deliberate mechanism: a job with a `running` row stands down.

Which introduces the failure that mechanism creates. A run killed mid-flight — a deploy, an OOM
kill, a machine going away — leaves a `running` row behind forever, and the job would stand down
on every future tick: stopping silently, the worst way for a scheduler to fail. `reapStale()`
closes runs older than the job's own window and says so on stdout, and the tick then exits
non-zero, because a run that died is a failure and the exit code is the only signal some
operators watch.

`staleAfterMinutes` is per job rather than one scheduler-wide setting. A four-hour report and a
ten-second sweep cannot share a threshold: set it to suit the sweep and the report is reaped
while it is still working, after which a second copy of it starts — the exact failure the
in-flight check exists to prevent.

### 2.6 Only the current minute is evaluated

If every node was down from 03:10 to 03:20, the 03:15 job does not run at 03:21. There is no
catch-up window, and adding one would make this a queue: the missed work is usually stale by the
time anyone notices, and "run all of it at once, now" is rarely what the operator wanted.

The honest cost is that a tick starting more than 60 seconds late skips a minute entirely.

### 2.7 Expressions are read on PHP's configured timezone

`date_default_timezone_get()` — which is what `DateTimeImmutable` uses, and which a skeleton app
sets from `.env`'s `TIMEZONE` in `config/bootstrap.php`. This is deliberately *not* UTC by
decree: pinning the clock to UTC would break the `(job, due_at)` key across a daylight-saving
boundary, and a schedule an operator cannot read in their own local time is a schedule they will
eventually misread. It matches the own-clock columns on `sessions` and `caches`.
`ReleaseNotes_1.4.0.md`'s two-clocks rule does not reach here, because that rule is about
gateway-supplied moments and there is no gateway.

**It is also not automatically the machine's clock, and that is the trap.** PHP's timezone comes
from `php.ini`'s `date.timezone` or an explicit `date_default_timezone_set()`; the system cron
fires on the OS zone. Nothing reconciles the two. A host running `+08` with `date.timezone = UTC`
will have cron wake the process at local 11:49 while the scheduler decides what is due at 03:49 —
verified during this release's MySQL run, where two nodes differing in nothing but
`date.timezone` both claimed the same logical minute and wrote two rows. Align the two settings
deliberately; do not assume a fresh host has done it for you.

Daylight saving then falls out of the unique key, correctly. On the day the clocks go back,
local 02:30 happens twice and both occurrences share one `due_at` — so the second collides with
the first's claim and stands down. On the day they go forward, 02:30 never happens and a job
scheduled for it is skipped that day.

The operational corollary is in `DeploymentTopology.md` §7: nodes in a cluster must agree on
their timezone, or they are running two different schedules.

### 2.8 `schedule:run` prints nothing when nothing happened

Every line this command writes becomes a cron email, and a heartbeat that greets the operator
sixty times an hour teaches them to filter it — after which the one line that mattered is
filtered too. A tick where nothing was due, or where every due job was already claimed by
another node, exits 0 in silence. Ran, failed, stood down, or reaped: one line each.

This departs from the always-print-success idiom of `cache:clear` and the rest, on purpose.

It is also why the crontab entry is documented **without** `> /dev/null 2>&1`. Silence is the
signal, and the kernel writes errors to stdout like everything else, so the reflexive redirect
throws the failures away along with the quiet.

### 2.9 `scheduled_runs` is not setup-owned, and not in `DDL.sql`

`mitosis setup` still creates exactly `sessions` and `caches`. The scheduler table comes from
`schedule:install`, keeping it opt-in for applications that schedule nothing — the decision
`CrossRepoContracts.md` §8 records for the checkout tables, taken a third time for the same
reason. It is therefore not a compatibility surface, and altering it in a future release is not
semver-major.

### 2.10 Job names are lowercase identifiers, and the reason is the database

`Scheduler::job()` refuses a name outside `[a-z0-9]` grouped with `:`, `-`, `.` or `_`. That
looks like house style enforced for its own sake. It is not.

The duplicate-name guard in the registry is a PHP array key, and PHP array keys are
case-sensitive. The `scheduled_runs.job` column carries whatever collation its server gives it,
and MySQL's default — `utf8mb4_0900_ai_ci` — is case- *and* accent-insensitive. So
`reports:build` and `Reports:Build` register cleanly as two separate jobs, then arrive at one
row in the database, where the unique index correctly treats them as the same slot. One of the
two silently stops running, on MySQL and not on SQLite.

This was found by running the release against a live MySQL server (§5.1), not by reading the
code. The alternatives were a `COLLATE` clause on the column — which `Services\Schema` has no
vocabulary for, and which would put the correctness of a job registry inside a DDL detail no
application can see — or a case-insensitive duplicate check, which closes the case collision and
leaves the accent one open. Refusing the ambiguity outright closes all of it, costs nothing an
application wanted, and puts the error where mistakes are cheapest: at registration, on the next
`mitosis` invocation.

## 3. Explicitly NOT in 1.5.0

- **Retries and backoff.** A failed run is recorded and the next due slot tries again. Anything
  more is queue behaviour.
- **A queue, worker pool, or async dispatch.** Jobs run in the tick's own process, one after
  another. A scheduler is not a queue.
- **Sub-minute schedules.** Forbidden twice over: `DATETIME` is second-precision, and the
  heartbeat is one minute.
- **A daemon or supervisor.** `DeploymentTopology.md` §6 keeps process supervision out of this
  repo. Clarity supplies the command; installing the crontab entry is the deploying team's job.
- **`Event` dispatch on job outcome.** The non-zero exit code and the cron email are the
  notification. Adding event names is semver-minor if a real need appears.
- **A sixth `health` check.** `DeploymentTopology.md` §5 enumerates five, and "has the scheduler
  run recently" is a monitoring question, not a deployment gate.

## 4. Compatibility

Additive throughout, and semver-minor. No existing signature changed, no value object gained or
lost a property, no shipped table definition moved. `Services\Console::run()` is untouched, and
the three frozen skeleton entry points are unaffected.

The nineteen built-in command names are the only contract surface that grew. Adding commands is
semver-minor per `CrossRepoContracts.md` §3; `schedule:install`, `schedule:list` and
`schedule:run` join the list there.

`schedule:run`'s name carries a weight the others' do not: it is written into a server's crontab
and outlives every deploy, so renaming it would break applications silently rather than at the
next `mitosis` invocation. §3 of `CrossRepoContracts.md` now says so.

### 4.1 Upgrading from 1.4.0

Nothing is required. An application that registers no jobs is unaffected, and `schedule:run`
with an empty registry does not so much as open a database connection.

To adopt the Scheduler:

1. `php mitosis schedule:install` — once, per database context.
2. Register jobs in `app/routes/cli.php` with `Scheduler::job()`.
3. Add the one crontab line, on every node that should be eligible to run jobs. It is safe on
   all of them: three nodes give three chances a due job runs, and no chance it runs three
   times.

Order matters only in that the crontab entry before the install produces a tick that exits 1
every minute, naming `schedule:install` — loudly, rather than silently doing nothing.
`php mitosis schedule:list` at any point shows the registry as the runner reads it, which is the
cheapest way to confirm step 2 landed.

## 5. Verification

- Full suite green: 899 tests, 1803 assertions, `composer lint` clean.
- `CronExpressionTest` — covers every field form, both name vocabularies, every macro, Sunday
  as both `0` and `7`, fourteen malformed expressions, and the §2.3 OR rule proved on all four
  of its combinations (the 13th that is a Friday, a 13th that is not, a Friday that is not the
  13th, and neither). The rejections are tested as thoroughly as the matches, because a field
  that parses but matches the wrong days fails silently.
- `JobLedgerTest` — the claim raced across **two connections to one temp file database**, not
  two `sqlite::memory:` handles, which are two separate databases and would have let both
  claims succeed while proving nothing. A dropped table asserted to propagate rather than read
  as a lost claim. Reaping asserted to close an abandoned run, to leave a young one alone, and
  to leave a settled one alone.
- `ScheduleRunTest` — a failing job recorded and the tick carrying on to the job registered
  after it; a second tick in the same minute not running the job twice; standing down while a
  previous run is in flight; reaping then running; silence on a tick with nothing to do; and
  the missing-table path naming `schedule:install`.
- `ScheduleInstallTest` — idempotent on a second run with pre-existing run records intact, and
  the unique index proved present by claiming the same slot twice.
- `ScheduleListTest` — each of the four last-run renderings; columns proved aligned by asserting
  the last column starts at the same character offset on rows whose names and expressions differ
  wildly in length; registration order preserved; the missing-table path listing the schedule,
  saying so exactly once, and exiting 0; the empty registry naming `app/routes/cli.php`; a long
  reason cut with a visible ellipsis, and — the one that matters more — a reason containing a
  newline collapsed onto one row rather than silently becoming two.
- `SchedulerTest` — the conventional name forms accepted, and eight ambiguous ones refused
  (§2.10), including the two that a case- and accent-insensitive column would silently merge.
- `ConsoleTest` — the built-in command count moved from sixteen to nineteen.

### 5.1 Verified against a live MySQL server

The one claim automated tests could not settle on their own: the suite runs on SQLite, and the
mutex has to hold on the database production uses. Run against **MySQL 9.1.0** — 8.0 was not
available on the verifying machine, and only the index-length question is version-sensitive, so
that one is answered empirically below rather than by arithmetic.

- `Schema::createTable()` from `ScheduleInstall::runsBlueprint()` accepted as-is, producing
  `UNIQUE KEY uq_scheduled_runs_job_due_at (job, due_at)` on `ENGINE=InnoDB`,
  `CHARSET=utf8mb4`. `SHOW INDEX` reports `Sub_part = NULL` on every index row — **nothing was
  silently prefixed**, which is the fact that matters rather than the 517-byte estimate.
- A duplicate key returns `getCode() => '23000'` (errno 1062), so `JobLedger`'s
  `str_starts_with($code, '23')` recognises it. Native prepares, no emulation.
- A missing table returns `42S02` and propagates. It is not mistaken for a lost claim.
- The race across two distinct server connections: one claim returns a run id, the other
  `null`, one row. Reversing the order reverses the winner — it is not context-order dependent.
- **Two genuinely simultaneous OS processes**, released within 3µs of each other after warming
  connections, autoloading and UUID generation so the race is decided by the INSERT rather than
  by TCP setup. Six rounds of twenty jobs: 120 slots, each run exactly once — 92 decided by the
  unique index, 28 by the in-flight guard — and zero jobs with more than one run row.
- A second `schedule:run` in the same minute: zero bytes on stdout, zero on stderr, exit 0.

### 5.2 Completed at tagging

Everything below was exercised against the **published** release resolved from Packagist —
`composer create-project monad/skeleton`, with `monad/clarity ^1.0` resolving to `v1.5.0` and
its dist taken from the release commit — never against a path repository or this working copy.

- **`php mitosis health` green**, all five checks, on a fresh `create-project` against MySQL:
  configuration, database connectivity, writable storage, migration status, PHP extensions.
  `setup` and `migrate` ran clean first.
- **The export-ignore list holds in the dist anyone actually installs.** `resources/`,
  `CLAUDE.md`, `.gitattributes` and `.gitignore` are all absent from
  `vendor/monad/clarity/`, and `src/` is present with the Scheduler in it. Checked in the
  installed tree rather than inferred from `.gitattributes`, because the file being correct
  and the archive being correct are two different claims.
- **The Scheduler driven end to end from that installed copy**, on MySQL: `schedule:run`
  before `schedule:install` exits 1 naming the fix; `schedule:install` creates the table;
  `schedule:list` reports both jobs as never run; `schedule:run` runs the due one and records
  it; **a second `schedule:run` in the same minute prints nothing and exits 0** — the cluster
  case, from the shipped artefact. The stored `due_at` is `04:40:00` for a run that happened
  at `04:40:12`, so the minute normalisation that makes the mutex work survived packaging.
- **A GitHub Release was published, not merely a tag.** The website's `/changelog` keys on
  `release/published`, which is why 1.2.0 never appeared there; the delivery is confirmed
  `200 OK`. The Packagist webhook returned `202` on the tag push.
- **`monad-www` merged before the tag** (`ReleasePolicy.md` item 7), and the skeleton's
  mirrors synced after it (item 8), verified byte-identical against the canonical copies.

One thing deliberately not claimed: the MySQL evidence in §5.1 is from **9.1.0**, not 8.0. No
8.0 host was available. Only the index-length question depends on the version, and it was
answered empirically — `Sub_part = NULL` on every index row — rather than by arithmetic.
