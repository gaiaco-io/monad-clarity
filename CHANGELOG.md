# Changelog

All notable changes to `gaia/monad-clarity` are documented in this file. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
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
