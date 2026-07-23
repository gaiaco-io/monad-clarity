# Changelog

All notable changes to `gaia/monad-clarity` are documented in this file. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
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
