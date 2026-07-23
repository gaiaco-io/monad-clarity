# Changelog

All notable changes to `gaia/monad-clarity` are documented in this file. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Phase 5, part 3: `Middlewares\Authentication` (ReleaseNotes §15 — 16 requirements, the
  single largest and highest-exposure item in the release). Clarity owns the
  authentication *mechanism*; the app owns the user *store*. Every requirement composes
  primitives already built rather than introducing new ones: password hashing/
  verification/rehash-detection is `Utils\Hash` directly (no separate "password service"
  class — Hash already covers this in full); login session regeneration and remember-me
  are both `Services\Session` (a remember-me token is a long-lived Session row, not a new
  table); login throttling is `Middlewares\RateLimiter`, required rather than optional
  per §28.2; authentication events go through `Services\Event`; email verification and
  password reset are stateless, purpose-tagged HMAC tokens (`Utils\HMAC`), the same
  pattern as Csrf's session-less path — no new schema for either. Google SSO uses the
  authorization-code flow over `Services\HttpClient`, verified via Google's own
  `tokeninfo` endpoint (Google performs the signature verification; this class still
  checks audience/issuer/expiry itself) rather than hand-rolling JWT/JWKS or adding a new
  dependency.
  **The pluggable user resolver (§15.2.15) is the one genuinely new, frozen surface** —
  duck-typed callables (`findByCredential`/`findById`, each returning
  `array{id, passwordHash, locked, emailVerifiedAt}|null`), not a formal interface,
  matching how Migration/Files/Cache already handle app-provided shapes in this codebase.
  Confirmed with Marshal via AskUserQuestion before implementation, since CrossRepoContracts
  §5 makes this extension surface semver-frozen once shipped. Both callables are
  read-only: Authentication never writes to the app's user table. `Event::USER_REGISTERED`
  is consequently app-fired, not Authentication-fired — user creation is app policy, same
  as the resolver design implies; noting this explicitly since the Build Plan's phrasing
  ("wire user registered here") could otherwise read as a dropped requirement rather than
  the deliberate consequence of the approved resolver design.
  Not `final`, extended by `app/middlewares/` per the same §5 contract as Csrf/RateLimiter.
  Three real security defects found and fixed before this was considered done, each
  driven by a specific adversarial test rather than inspection alone:
  - **User-enumeration timing oracle.** `attempt()`'s original `$user === null ||
    !Hash::verify(...)` short-circuited before ever calling `Hash::verify()` for an
    unknown identifier, while a known identifier with a wrong password paid the full
    Argon2id cost — a measurable, textbook timing side-channel for enumerating which
    accounts exist. Fixed by always calling `Hash::verify()` exactly once, against the
    real hash if found or a fixed lazily-computed dummy hash otherwise, so both paths pay
    identical KDF cost.
  - **Account lock bypassable via remember-me.** `attempt()` correctly rejected a locked
    account; `resumeFromRememberToken()` re-established a session from a valid
    remember-me token without ever re-checking lock state — an account locked after the
    token was issued could still resume a full session. Fixed by re-resolving the user
    via `findById()` and rejecting a locked user before rotating/consuming the token.
    Then generalized: `login()`, the shared primitive every session-granting path
    (credential login, remember-me, and Google SSO / anything else built on top) routes
    through, now enforces the same lock check itself rather than trusting each caller to
    have checked first — the same invariant-enforced-at-one-entry-point-violated-at-
    another shape the remember-me bug was a specific instance of.
  - **A test named for TTL expiry that didn't test it.** `EMAIL_VERIFICATION_TTL_SECONDS`/
    `PASSWORD_RESET_TTL_SECONDS` were hardcoded, so the original test substituted
    cross-purpose-token rejection instead — leaving the actual expiry branch on a
    security-sensitive control completely untested behind a passing, misleadingly-named
    test. Made both TTLs constructor-injectable (matching Csrf's own TTL, injectable for
    exactly this reason) and added real `-1`-TTL expiry tests for both token types.
  Also added to `Services\Session` in support of this: `assignUser()` (promotes a guest
  session to authenticated, or the reverse, without losing its id/payload — the write
  Authentication needed that Session didn't yet expose) and `renew()` (pushes a
  session's expiry back to a fresh full lifetime — a promoted guest session that started
  seconds from expiring no longer leaves the newly-authenticated session seconds from
  expiring too). `start()` also gained an optional per-call lifetime override, needed for
  remember-me sessions (30 days) to coexist with the regular globally-configured session
  lifetime without reconfiguring Session around each call.
  35 tests, including a fixture Google OAuth server (`resources/tests/fixtures/
  google-oauth-server.php`, PHP built-in server, same pattern as HttpClientTest's own
  fixture) driving every verification-failure scenario — wrong audience, wrong issuer,
  expired token, failed exchange, missing id_token — by the authorization `code` sent, so
  Google's real infrastructure is never touched.
- Phase 5, part 2: `Middlewares\RateLimiter` (ReleaseNotes §28). Fixed-window counter
  backed by `Services\Cache` — works with any of its three drivers, so the limit holds
  across a multi-node deployment whenever Cache is DB- or Redis-backed. Required call
  sites aren't all full-request middleware: login/password-reset rate-limit a specific
  identifier (an email), not a whole route, so `attempt(string $key): bool` is public and
  directly callable from Authentication's login flow, independent of `__invoke()`'s
  per-request pipeline use for public API/LLM/webhook routes. `resolveKey()` (defaults to
  the caller's IP) and `rejectionResponse()` (429 + `Retry-After`) are `protected`
  extension points per the same non-`final` contract as Csrf.
  Cache keys are SHA-256-hashed before use rather than concatenated raw — a raw IPv6
  address contains `:`, one of PSR-16's reserved key characters, which would otherwise
  throw on the exact input type this middleware exists to rate-limit.
  Documented rather than silently accepted: `hit()` is read-then-write (PSR-16 has no
  atomic increment), so concurrent requests against the same key can each read the same
  count and all pass — a deterrent, not a hard guarantee, worth calling out explicitly
  since its highest-stakes consumer is login throttling. Fixed-window boundary bursts
  (up to 2x the limit straddling a window edge) are the same kind of accepted, documented
  gap; closing either needs a different algorithm §28 doesn't ask for. 12 tests, including
  one that would fail loudly (a thrown `CacheInvalidArgumentException`) if the hashing
  fix above were reverted.
- Phase 5, part 1: `Services\Session` and `Middlewares\Csrf`.
  `Services\Session` is a full rewrite of the DB-backed session store (`sessions` table,
  DDL.sql) as a static facade matching `DB`/`Route`/`View`'s convention. Fixed a real bug
  in the code it replaces: the legacy `start()` never populated `expire_at`, which is
  `NOT NULL` in the frozen DDL — every insert would have violated that constraint against
  a real MySQL server (SQLite is more permissive, which is exactly why the bug went
  unnoticed). `resolve()` treats an expired session, a revoked one, and a token that
  never existed identically (null) — the caller cannot distinguish why, by design.
  `regenerate()` rotates a session's token in place (anti-fixation, e.g. on login) while
  preserving its id/user_id/payload. `revoke()` marks a row invalid without deleting it
  (audit trail survives); `destroy()` hard-deletes; `purgeExpired()` is a maintenance
  operation for a scheduled task, not the request path. Deliberately does not touch
  superglobals, cookies, or the HTTP layer — `start()`/`regenerate()` return a plaintext
  token; delivering it as the `mid` cookie (`Session::COOKIE_NAME`) on the outgoing
  Response is the caller's job (Authentication, Csrf) — this keeps Session a pure,
  independently testable data layer. 12 tests, adversarial rather than happy-path:
  expired rejection, revoked rejection, guest (null `user_id`) sessions, digest lookup,
  regeneration invalidating the prior token, purge counting exactly the rows it should.
  `Middlewares\Csrf` (ReleaseNotes §13) is the first of six new pipeline middlewares this
  phase — `__invoke(Request, callable $next): Response`, matching Route's existing
  pipeline contract. Session-backed requests store the token in the session payload
  (rotated via `Session::write()`); session-less requests (a public/anonymous form) get
  an HMAC-signed `{random}.{timestamp}.{hmac}` token (`Utils\HMAC`, `Utils\ConstantTime`).
  Not `final` — per `CrossRepoContracts.md` §5, Clarity middlewares are designed for
  `app/middlewares/` subclasses with a zero-argument constructor (Route resolves a string
  middleware via `new $class()`); `requiresValidation()`/`isExcluded()`/
  `originIsTrusted()`/`reject()` are `protected` extension points, deliberately chosen to
  expose configuration behavior without touching the token-comparison internals.
  **Honest scoping, not a shortcut**: the session-less HMAC token proves the token was
  minted by this app and hasn't exceeded its TTL, but is NOT bound to any browser/cookie
  — a token harvested from the attacker's own visit is validly signed and would replay
  successfully if Origin/Referer were silently permissive. A true signed-double-submit-
  cookie scheme would close this independent of Origin/Referer, but requires
  request-scoped coordination between this middleware and whatever calls `tokenFor()`
  from a view (a `Response::withCookie()` API doesn't exist yet, deliberately deferred
  rather than half-built alongside this). So for the session-less path specifically, the
  Origin/Referer check is the actual line of defense, and — unlike the session-backed
  path, which has a server-stored secret to fall back on — at least one of Origin/Referer
  is now *required* to be present for a session-less request; absent both, the request
  is rejected rather than allowed through on silence. Also fixed a concrete bug caught by
  testing against `mitosis serve`'s own default port: comparing `Origin` via
  `parse_url(..., PHP_URL_HOST)` against the raw `Host` header silently drops the port,
  so `http://127.0.0.1:8000` was being compared against `127.0.0.1` and rejected as
  cross-origin on the single most common local-dev scenario. Fixed by comparing
  host:port authority on both sides. 18 tests, including both of the above.
  Legacy `Services\CsrfService` (wrong namespace, called APIs that no longer exist on the
  rewritten `Session`/`Mediator`/`Request`) removed — fully superseded.
- Phase 4 complete: the `mitosis` CLI. `Services\Console::run(array $argv): int` is the
  frozen kernel contract (`CrossRepoContracts.md` §2–3) — argv parsing (via the new
  `Console\Arguments` value object), built-in command registry, `Console::register(string
  $name, callable|string $handler)` for application-defined commands wired through
  `app/routes/cli.php` (loaded, if configured via `Console::configure()`, on every
  `run()` before dispatch — `config/bootstrap.php`'s job, since the frozen `mitosis` stub
  calls `run($argv)` with no room for extra arguments), `help`/unknown-command output,
  and styled `success()`/`error()`/`info()` output helpers. `Console\Arguments`'s public
  methods (`argument()`/`allArguments()`/`option()`/`hasOption()`/`rawTokens()`) are
  themselves part of that frozen contract — every command, built-in or app-registered,
  receives one — even though the 15 command classes under `Console\*` are free to
  reorganise (`RepoMap.md`'s "Key structural notes").
  All 15 stable command names (`CrossRepoContracts.md` §3) are implemented: four
  `make:*` generators (`make:controller`/`make:model`/`make:service` write
  `app/{controllers,models,services}/{Name}.php` from a template; `make:migration` writes
  a timestamped `database/migrations/{YmdHis}_{slug}.php` returning an anonymous
  up()/down() object matching what `Services\Migration::migrate()` expects); five
  data-layer wrappers (`migrate`/`migrate:status`/`migrate:rollback`/`db:seed`/
  `db:execute`, thin pass-throughs to Phase 3's `Services\Migration`); `setup` (creates
  the two setup-owned tables from `DDL.sql` — see below); `health` (the deployment
  acceptance gate, `DeploymentTopology.md` §5: configuration, DB connectivity, writable
  storage, migration status, PHP extensions — exits non-zero if any check fails); `serve`
  (PHP's built-in server on `127.0.0.1:8000` by default, rooted at `public/` with
  `public/router.php` as front controller if present — a development convenience only,
  never production); `test` (delegates to the project's own `vendor/bin/phpunit`, every
  argument passed through raw via `rawTokens()` rather than `Console`'s own
  positional/option parsing — `--filter=Foo` must reach phpunit unchanged, not be
  swallowed as a Console option); `cache:clear` (empties the file cache driver's
  directory only — a database/Redis-backed `Cache` is intentionally left untouched,
  since the command has no way to know which driver an application is configured for,
  and says so in its own output rather than silently no-op'ing while claiming success);
  `logs:clear` (truncates every `*.log*` file under `storage/logs` to empty, without
  deleting the files). `migrate:status` always exits 0, even with pending migrations —
  it's a purely informational report; `health` is the one documented deployment gate
  (`DeploymentTopology.md` §5), and having `migrate:status` double as a second gate would
  make `migrate:status && …` fail unpredictably in scripts for a command whose job is to
  report, not judge.
  `setup`'s `sessionsBlueprint()`/`cachesBlueprint()` are the single canonical definition
  of the two frozen tables — `SchemaTest`'s cross-dialect DDL-shape assertions now
  exercise these same closures instead of a second, hand-maintained copy that could
  silently drift from what `setup` actually creates. The `sessions.id` column's
  `DEFAULT (uuid())` is MySQL-only syntax with no SQLite equivalent and no
  version-independent PostgreSQL one; since application code always goes through
  `DB::insert()` (which generates the UUID PHP-side regardless, per `Architecture.md`
  §9), `setup` applies that default only on the MySQL dialect and omits it elsewhere
  rather than papering over the gap.
  `serve` and `test` both split command assembly (`commandLine(): string`, pure and
  fully tested) from execution (`__invoke()`, a thin `passthru()` wrapper) — `passthru()`
  blocks until the spawned process exits, so a test that invoked either command for real
  would either hang forever (`serve`, until its dev server is killed) or recursively
  re-run this very suite from inside itself (`test`, which wraps the exact `phpunit`
  binary this suite runs under). Tests exercise `commandLine()` only; `__invoke()` is
  covered only for its early-exit failure path (missing `vendor/bin/phpunit`).
  A broken `app/routes/cli.php` (a syntax/runtime error in an app-registered command
  file) is caught by `run()` and reported through the same `error()` output every other
  command failure uses, rather than an uncaught exception with a raw stack trace — CLI
  output is user-facing product surface (CLAUDE.md).
  56 new tests (kernel dispatch, `Arguments`, all 15 commands) using temp
  filesystem/in-memory-SQLite fixtures rather than a real project, per
  `TestingStrategy.md` Tier 5.
- `Services\Files` catch-up (Phase 2's own text never listed it, though Phase 5's text
  references "retrofit Phase 2's Files" — a gap in the Build Plan document itself; done
  now before Phase 4). Rewritten as a pure storage service: `store()`/`storeMultiple()`
  take a `Psr\Http\Message\UploadedFileInterface` (matching `Request::file()`'s return
  type from Phase 2) and return `['path','mimeType','size','public']`. MIME type is
  always detected from file content (`ext-fileinfo`), never the client-supplied
  `Content-Type` — an uploaded file must pass both the extension allowlist and a
  content-sniffed MIME type consistent with that extension. Filesystem adapter (default,
  `moveTo()` — upload-aware, atomic) and S3 adapter (duck-typed against
  `Aws\S3\S3Client`'s real `putObject`/`deleteObject`/`doesObjectExist` shapes, no SDK
  dependency). 17 tests.
  **Dropped the database coupling entirely**: the legacy class had its own
  `files`/`unlinkFileByParentId()`/`getFileListByParentId()` tied to a specific
  `parent_id`/`is_primary`/`sequence` schema — that table isn't in `DDL.sql` or owned by
  any Clarity document; it was leftover business modelling from a specific prior
  application, same shape of issue as `DB`'s hardcoded connection-context names. Per
  `API_Contracts.md`'s own one-line description ("storage adapters... deletion,
  public/private visibility" — no database mention at all), Files is storage-only; an
  application's own metadata table, with whatever columns its domain needs, is its own
  concern. Also fixed a real correctness bug in the dropped code: safe filenames were
  generated via `sha1($originalFilename)`, meaning two uploads of a same-named file
  collided and silently overwrote each other; replaced with `random_bytes`-based names.
- `ext-fileinfo` added to `composer.json` `require` and CI's extension list;
  `DeploymentTopology.md` §1 updated to document it (was missing even though
  content-based MIME detection has always been a §19 requirement).
- Phase 3 (data layer, part 3 — Phase 3 complete): `Services\Cache` — PSR-16
  (`Psr\SimpleCache\CacheInterface`), three drivers in one class bound at construction
  (`Cache::DRIVER_FILE`/`DRIVER_DATABASE`/`DRIVER_REDIS`), per `ReleaseNotes_26.07.md`
  §26. Full get/set/delete/clear/has/getMultiple/setMultiple/deleteMultiple surface.
  TTL accepts `null` (never expires, per `DDL.sql`'s own comment: "expires_at NULL =
  never expires"), `int` seconds, or `DateInterval`. Cache keys containing a PSR-16
  reserved character (`{}()/\@:`) or empty throw `CacheInvalidArgumentException`
  (implements `Psr\SimpleCache\InvalidArgumentException`, per spec). The database
  driver enforces Architecture.md §9's rule directly: `key_hash` is only an index
  shortcut, and every read compares the row's stored `cache_key` against the requested
  key, treating any mismatch as a miss. The Redis driver accepts any object exposing
  get/set/setex/del/keys rather than being hard-typed to ext-redis's `Redis` class —
  DeploymentTopology.md §1 keeps that extension optional, and this keeps Cache
  loadable and the Redis driver *testable* (via a plain fake) without it. 40 tests
  across all three drivers via a shared data provider, including a constructed
  cache_key/key_hash-mismatch row (a real SHA-256 collision can't be produced for a
  test) proving the "never trust the hash alone" rule actually governs reads rather
  than passing vacuously.
- `psr/simple-cache` added to `composer.json` `require` (PSR-16 compliance).
- Phase 3 (data layer, part 2): `Services\Migration` — orchestrates migration files
  (`GapAnalysis_BuildPlan_26.07.md` §12; create/drop database/table/index are Schema's
  job, used directly from a migration's `up()`/`down()`). A migration file returns an
  object with `up()`/`down()`; applied migrations are tracked in `clarity_migrations`
  (not one of the two setup-owned tables in `CrossRepoContracts.md` §8 — internal
  bookkeeping, free to change). `migrate()`/`rollback(steps)`/`status()`/`runSqlScript()`/
  `runSeed()`/`exportDdl()`. Export reproduces the live schema as idempotent
  `CREATE TABLE IF NOT EXISTS` statements on SQLite and MySQL (both expose the original
  DDL text natively); PostgreSQL has no such facility, so its export reconstructs columns
  from `information_schema` only — primary keys/unique constraints/indexes are NOT
  captured, documented as a known limitation rather than silently incomplete. 11 tests,
  including exporting then re-running the export against the same already-migrated
  database to prove idempotency (§12.8's actual requirement).
  Two real bugs caught before landing: `runSqlScript()` called a `splitStatements()`
  helper that was never written (straightforward miss, caught immediately by the test);
  more substantively, SQLite's `sqlite_master.sql` does **not** preserve the `IF NOT
  EXISTS` clause from the original `CREATE TABLE` statement (it stores canonical
  structure, not literal submitted DDL) — re-running the "idempotent" SQLite export
  against its own source database threw "table already exists" until `IF NOT EXISTS`
  was explicitly re-injected, the same fix already needed for MySQL's `SHOW CREATE TABLE`.
- Phase 3 (data layer, part 1): `Services\Schema` — PDO dialect abstraction over MySQL
  (default), PostgreSQL, and SQLite (`GapAnalysis_BuildPlan_26.07.md` §10). `Blueprint`
  (`Services\Schema\Blueprint`) builds a dialect-agnostic column/key description;
  `id()`/`autoIncrementId()` give UUID-default primary keys with the configurable integer
  option per `Architecture.md` §9. `createTable`/`alterTable`/`dropTable`/`dropColumn`/
  `createIndex`/`dropIndex`/`createDatabase`/`dropDatabase`/`hasTable`/`hasColumn`.
  Documented, deliberate dialect gaps rather than silent degradation: `datetime(...,
  autoUpdate: true)` (MySQL `ON UPDATE CURRENT_TIMESTAMP`) has no PostgreSQL/SQLite
  equivalent without a trigger, which Schema does not generate; SQLite has no
  CREATE/DROP DATABASE; PostgreSQL's `CREATE DATABASE` has no `IF NOT EXISTS`. 14 tests,
  including reproducing the frozen `sessions`/`caches` DDL (`DDL.sql`, `CrossRepoContracts.md`
  §8) from the same `Blueprint` definition on both MySQL (compiled-SQL assertions, via
  reflection on the pure compiler — no MySQL server in CI) and SQLite (executed for real).
  A real bug this caught: `hasTable()`/`hasColumn()` left their probe `PDOStatement`'s
  cursor open, which then made SQLite report "table is locked" on a subsequent DDL
  statement against the same table — fixed with explicit `closeCursor()`.
- `ext-pdo`, `ext-pdo_mysql` added to `composer.json` `require` (DeploymentTopology.md §1:
  pdo_mysql is the required default driver); `ext-pdo_sqlite` added to `require-dev` (used
  only by Clarity's own test suite, never required to consume the library); `ext-pdo_pgsql`,
  `ext-redis` documented under `suggest`. CI's PHP extension list updated to match.
- Phase 1 (foundations): the seven `Gaia\Clarity\Utils` security helpers per
  `GapAnalysis_BuildPlan_26.07.md` §29 —
  `ConstantTime` (timing-safe string comparison via `hash_equals`),
  `HMAC` (sign/verify, `hash_hmac_algos()`-validated algorithm),
  `CryptographicToken` (`random_bytes`-backed hex/base64url token generation),
  `Hash` (password hashing — Argon2id where the runtime supports it, bcrypt fallback),
  `Encryption` (AES-256-GCM at-rest encryption; IV+tag+ciphertext bundled, authenticated,
  tamper-evident),
  `SignedURL` (HMAC-signed, expiring URLs — expiry is part of the signed payload, params
  canonicalized before signing so query-string order can't be forged or matter),
  `Redactor` (recursive, case/separator-insensitive secret masking for log lines).
- 49 PHPUnit tests across all seven classes (`resources/tests/Utils/`), including
  adversarial cases per `TestingStrategy.md` Tier 1: tampered ciphertext/tag/IV, expired
  and forged signed URLs, wrong-key/tampered HMAC verification, rehash-on-cost-change for
  `Hash`, and a regression guard for `ConstantTime` against a naive early-exit comparator.
- `ext-openssl` added to `composer.json` `require` (used by `Utils\Encryption`).
- Phase 1 (foundations, continued): `Services\Event` — tiny synchronous dispatcher with six
  stable built-in event names (`login.success`, `login.failed`, `payment.completed` (reserved
  until Checkout ships), `user.registered`, `file.uploaded`, `migration.completed`) and
  `listen()`/`dispatch()`/`hasListeners()`/`forget()`; not restricted to the built-ins,
  per `API_Contracts.md`. 7 tests.
- `Middlewares\Logger` — PSR-3 compliant (`Psr\Log\AbstractLogger`), one instance per
  destination file. Covers all 11 §14 requirements: log levels, context arrays with `{placeholder}`
  interpolation, `request_id`/`user_id` promoted to top-level fields, channel label,
  timezone-aware ISO-8601 timestamps, size-based rotation, `Utils\Redactor` redaction of
  context values, optional JSON-line output, and structured exception description when
  `context['exception']` is a `Throwable`. 10 tests.
- `Services\HttpClient` — cURL-backed, PSR-18 compliant (`Psr\Http\Client\ClientInterface`)
  directly (not bridged — see `Architecture.md` §6 Decision #2), using `nyholm/psr7` for
  concrete PSR-7 Request/Response objects. Ergonomic `get`/`post`/`put`/`patch`/`delete`/
  `postJson` helpers on top of `sendRequest()`. Throws `HttpClientException` (implements
  `Psr\Http\Client\NetworkExceptionInterface`) on transport-level failure. 9 tests against a
  local PHP built-in server fixture (`resources/tests/fixtures/http-echo-server.php`) — no
  dependency on the real internet or a third-party service.
- `psr/log`, `psr/http-client`, `psr/http-message`, `psr/http-factory`, `nyholm/psr7` added
  to `composer.json` `require` (PSR-3/PSR-18 compliance, per `Architecture.md` §6).
  `ext-curl` added (used by `Services\HttpClient`). `CrossRepoContracts.md` §1 updated to
  match.
- Phase 2 (HTTP core): `Services\Request` — instance-based, full accessor API
  (`method`/`path`/`query`/`input`/`json` with dot-notation/`header`/`cookie`/`file`/`ip`/
  `userAgent`/`all`), PSR-7 interop via `toPsr7()`/`fromPsr7()` (bridged, not adopted, per
  `Architecture.md` §6). `json()` lazy-parses the raw body per `CrossRepoContracts.md` §6's
  "Jsonify did not run" branch; `withJsonBag()` lets a future Jsonify middleware supply the
  pre-parsed bag instead. Input is stored raw — no blanket escaping at parse time. 18 tests.
- `Services\Response` — static constructors (`json`/`htm`/`text`/`download`/`redirect`/
  `noContent`/`stream`) each returning a value object; nothing is echoed or exits until
  `send()`. `json()` encodes data as-is (no HTML-escaping — the legacy code's escape-then-
  encode was double/wrong-context escaping). PSR-7 interop via `toPsr7()`. 16 tests.
- `Services\Route` — registration (`get`/`post`/`put`/`patch`/`delete`/`options`/`group`/
  `fallback`) now builds a table that `dispatch(Request): Response` matches against once,
  which is what makes a real 404-vs-405 distinction possible (§22.2) — the previous
  match-and-call-immediately design structurally couldn't tell "no route matched this path"
  from "a route matched, wrong method." Named/typed (`{id:int}`, `{slug:alpha}`, `{id:uuid}`)/
  optional (`{name?}`) parameters, `where()` constraints, nested group prefix+middleware
  merging, and a middleware pipeline (`__invoke(Request, callable $next): Response`).
  Controller actions receive route parameters positionally, then `Request` last; return
  value must be a `Response` or array (auto-converted to JSON, §21.3). Route model binding
  intentionally not built — explicitly optional per `API_Contracts.md`. 16 tests.
- `Services\View` — fixed 6-step pipeline (§24.3): resolve, merge shared+local data, run
  composers, render, apply layout, return `Response`. A view opts into a layout explicitly
  (`$layout = '...'` in the template, or `render()`'s `$data['layout']`) — no implicit
  variable injection (§24.4); `View::configure(string $basePath)` replaces the legacy
  pattern of reading an ambient `PATH[]` global. 10 tests.
- `Services\Mediator` — registers PHP's error/exception/shutdown handlers. Development
  renderer covers all 8 §18 elements (class, message, file/line, source excerpt, ordered
  stack frames, request ID, request summary, previous-exception chain); production renderer
  hides internals, returns HTTP 500, logs the full exception via a PSR-3 logger, and returns
  an incident ID. Stays a static facade (`abstract class`, matching `Route`/`View`/`DB`) so
  `Mediator::handleException($e)` keeps working from `DB.php`/`Session.php`'s existing,
  not-yet-migrated call sites. 7 tests. Known interim gap: those call sites relied on the
  old handler's `exit`/`die`; the new one returns a `Response` and does not exit, so in
  debug mode a DB-layer exception no longer halts the page with a dev dump — `return false`
  still happens correctly either way. DB.php is Phase 3 scope; left as-is as it already
  double-logs (its own `logError` plus Mediator) and needs its own rework there regardless.
- `Middlewares\MetaTag` (was `Services\SeoService`) — relocated and renamed per
  `GapAnalysis_BuildPlan_26.07.md` item 10; behavior otherwise unchanged. Dropped one dead
  line (`(new View())->set('seo', ...)`) that called an instance API the new `View` no
  longer has and was itself the kind of implicit view-data injection `View` now explicitly
  forbids (§24.4) — `toViewData()` remains public for a caller to pass in explicitly. 6 tests.
- `resources/tests/Integration/Phase2HttpCoreTest.php` — the Build Plan's actual Phase 2
  exit criteria ("a request can be routed through middleware to a controller, rendered via
  View or returned as JSON, with dev and prod exception rendering working end to end"),
  which no single class's unit suite proves on its own: routes a synthetic `Request` through
  `Route::dispatch()` → middleware → controller → `View::render()`/array-to-JSON, and
  separately through the same dispatch-then-catch glue a real `public/index.php` would use,
  into `Mediator::handleException()` for both debug and production rendering. 5 tests.
- `LICENSE` (MIT).
- `README.md` with status, PHP floor, and testing instructions.
- `.gitattributes` `export-ignore` rules for `/resources`, `/CLAUDE.md`, and the dotfiles
  themselves, per `Architecture.md` §10.
- `.gitignore` (`/vendor/`, `composer.lock`, OS/PHPUnit cache artifacts).
- `phpunit.xml.dist` pointing the test suite at `resources/tests`.
- `composer.json` `scripts.test` (phpunit) and `scripts.lint` (PHP syntax check over `src/`).
- `.github/workflows/ci.yml`: validates `composer.json`, installs dependencies, lints, and runs
  PHPUnit on PHP 8.2 and 8.3, per `TestingStrategy.md` CI requirements.
- Initialised git repository; GitHub repo `gaiaco-io/monad-clarity` (public).

### Changed
- `Services\DB` (Phase 3): remains the query/connection layer, dialect concerns now
  delegated to `Schema` per the Build Plan. `configure(string $context, array $config)`
  replaces reading the ambient `DB[...]` global constant and a `getDBConfig()` global
  function — DB has no config service of its own, so the application supplies connection
  config explicitly, same pattern as `View`/`Mediator`/`Logger`. Contexts are now generic
  strings the application defines; dropped the hardcoded `'kerberos'`/`'session'`/`'shared'`/
  `'subscription'` names, which were leftover from a specific prior application, not a
  documented Clarity concept. Fixed `begin()`/`commit()`/`rollBack()`/`getLastInsertId()`/
  `getRowCount()` being declared as instance methods on an `abstract class` — uncallable,
  since an abstract class can't be instantiated; now static (`beginTransaction()` etc.).
  **Breaking for internal callers:** PDO is configured with `ERRMODE_EXCEPTION`, and DB no
  longer fights that — `PDOException` now propagates instead of being caught internally
  and turned into a `false`/`null` return (which also silently dropped every error since
  DB no longer knows about Mediator/`ENV_MODE`). `insert()` returns the row's ID (was
  `string|false`); `update()`/`delete()` return the affected row count (were `bool`).
  Reaches `Session.php`/`CsrfService.php`/`Files.php`'s existing `DB::insert()`/`update()`
  call sites — none inspect the old return value today, so nothing breaks now, but their
  own upgrades (Phase 5 for Session/Csrf; Files still pending, see below) inherit the new
  throw-on-failure/row-count contract. Same shape of interim gap as `Services\Mediator`'s
  entry above.
- Restructured source tree under `src/` (`src/Services/`, `src/Utils/`) per `RepoMap.md` and
  `Architecture.md` §2. Previously classes lived at repo root (`Services/`, `Middlewares/`,
  `Utils/`), which does not match the documented PSR-4 root.
- `composer.json` PSR-4 autoload corrected from `"Gaia\\Clarity\\Services\\": "Services/"` to
  `"Gaia\\Clarity\\": "src/"` per `Architecture.md` §2 and `CrossRepoContracts.md` §1. The
  previous mapping did not autoload `Middlewares\*` or `Utils\*` at all, and pointed `Services\*`
  at a nonexistent root-level directory.
- Added `require`: `php: >=8.2`, `nesbot/carbon`, `ramsey/uuid` (the latter was already an
  undeclared implicit dependency of `Services\DB` and `Services\Files`).
- Added `require-dev`: `phpunit/phpunit ^11.0`, `fakerphp/faker` per `TestingStrategy.md`.
  PHPUnit pinned to `^11.0` rather than latest (`^13`) — 13.x requires PHP `>=8.4`, which
  would break CI against the documented `>=8.2` floor and the 8.2/8.3 matrix.
- Added `autoload-dev` PSR-4 mapping `Gaia\\Clarity\\Tests\\` to `resources/tests/`.

### Removed
- `Middlewares/Keylock.php` — declared `namespace Gaia\Kerberos`, did not match the package
  namespace, and was not part of any documented middleware in `RepoMap.md`.
- `Services/$MYVIMRC` — stray editor artifact, not source code.
- `Utils/Number.php` — per `GapAnalysis_BuildPlan_26.07.md` §2.4, resolved as dropped;
  not part of the 26.07 scope, no predecessor in the seven `Utils\*` security helpers.
