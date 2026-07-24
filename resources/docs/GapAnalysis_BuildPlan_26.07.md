# Monad 26.07 — Clarity Gap Analysis & Sequenced Build Plan

**Status:** Draft for review
**Scope basis:** Monad 26.07 Release Notes (final), `monad/clarity` tree (final), `monad/skeleton` tree (final)
**Audience:** Implementation guardrail document for `resources/docs`. Intended to be referenced by `CLAUDE.md` / implementation prompts.

---

## 1. Baseline

The pre-26.07 core (formerly `/Clarity` inside the application repository) consists of the following classes: `DB`, `Files`, `Mediator`, `Request`, `Response`, `Route`, `Session`, `View`, `CsrfService`, `SeoService`, and `Utils/Number`. Everything in 26.07 is measured against this baseline.

The target is the finalised `monad/clarity` package tree: 7 middlewares, 15 services (+ 4 LLM adapters), 7 security utils, and a Console namespace with a kernel and 15 command classes, distributed via Packagist and consumed by the `monad/skeleton` application skeleton.

---

## 2. Gap Analysis

### 2.1 Upgrade — existing classes carried forward

| # | Release item | Component | From → To | Key upgrade work |
|---|---|---|---|---|
| 1 | §20 | `Services\Request` | `Request.php` (existing) | Full accessor API (method/path/query/input/json dot-notation/header/cookie/file/ip/userAgent/all), validation hooks, context-aware escaping, PSR-7 interop |
| 2 | §21 | `Services\Response` | `Response.php` (existing) | Static constructors (json/htm/text/download/redirect/noContent/stream), array→JSON auto-conversion contract with Route |
| 3 | §22 | `Services\Route` | `Route.php` (existing) | Named routes, groups, prefixes, middleware pipeline, typed + optional params, constraints, optional model binding, fallback, 404 vs 405 |
| 4 | §24 | `Services\View` | `View.php` (existing) | `render/share/composer/exists`, 6-step pipeline, no runtime magic or implicit injection |
| 5 | §17 | `Services\Session` | `Session.php` (existing) | DB-backed `sessions` table (DDL fixed in notes), digest, payload JSON, expiry/revocation, `setup` binding |
| 6 | §18 | `Services\Mediator` | `Mediator.php` (existing) | Error/exception/shutdown handlers, dev renderer (8 elements), prod renderer (4 elements), Logger integration |
| 7 | §19 | `Services\Files` | `Files.php` (existing) | Content-based MIME detection, allowlist, size limits, safe naming, atomic move, storage adapters (filesystem, S3), visibility |
| 8 | §10 (partial) | `Services\DB` | `DB.php` (existing) | Remains query/connection layer; PDO baseline; delegates dialect concerns to new Schema service |
| 9 | §13 | `Middlewares\Csrf` | `CsrfService.php` (existing, relocated) | Moves from Services to Middlewares; session + DB-backed tokens, rotation, form/header tokens, origin checks, constant-time compare, exclusions, HMAC session-less mode |
| 10 | §23 | `Middlewares\MetaTag` | `SeoService.php` (existing, renamed + relocated) | Title/description/canonical/robots/OG/Twitter/JSON-LD/hreflang generation |

### 2.2 Net-new — no predecessor in the baseline

| # | Release item | Component | Depends on |
|---|---|---|---|
| 1 | §10 | `Services\Schema` | DB (PDO), config |
| 2 | §12 | `Services\Migration` | Schema, DB |
| 3 | §26 | `Services\Cache` (file / DB / Redis, PSR-16) | DB (for DB driver), `caches` table |
| 4 | §27 | `Services\Event` (sync dispatcher) | none |
| 5 | §25 | `Services\HttpClient` (cURL, PSR-18) | none |
| 6 | §11 | `Services\LLM` + `LLMAdapters\{OpenAI, Anthropic, DeepSeek, Gemini}` | HttpClient, config/llm.php |
| 7 | §14 | `Middlewares\Logger` (PSR-3, 3 log files, rotation, redaction) | Utils\Redactor |
| 8 | §15 | `Middlewares\Authentication` (16 requirements incl. Google SSO) | Session, Event, Utils\Hash, RateLimiter, HttpClient (SSO) |
| 9 | §16 | `Middlewares\RBAC` | Authentication, DB |
| 10 | §28 | `Middlewares\RateLimiter` | Cache (counter storage) |
| 11 | §30 | `Middlewares\CORS` (origins/methods/headers config, credentials, preflight + cache duration, route-level override, env-specific config, correct `Vary` headers) | Route (route-level override) |
| 12 | §31 | `Middlewares\Jsonify` (all valid JSON values, any body-carrying method, media-type detection, body-size limit, `JSON_THROW_ON_ERROR`, bigints as strings, depth limit, structured 400/415, separate JSON bag) | Request (bag contract — see Resolved Decisions §5) |
| 13 | §29 | `Utils\{CryptographicToken, Encryption, SignedURL, HMAC, Hash, Redactor, ConstantTime}` | none (PHP stdlib: `random_bytes`, `sodium`/`openssl`, `hash_hmac`, `password_*`, `hash_equals`) |
| 14 | §8 | `Console\Console` (kernel) + 15 commands | varies per command; Migrate\* → Migration; Setup → Schema + Session/Cache DDL; CacheClear → Cache; Serve/Health/Test → config, DB, storage |

### 2.3 Deferred — out of 26.07

| Release item | Component | Disposition |
|---|---|---|
| §9 | `Services\Checkout` + `CheckoutAdapters` (9 gateways) | Off production `main`; kept on feature branch as reference. §28.5 RateLimiter requirement marked deferred accordingly. |

### 2.4 Orphaned from baseline

`Utils/Number.php` (number display formatting) — **RESOLVED: dropped.** Rarely used in existing projects; will be replaced by a mature Packagist package where needed. Existing internal apps that use it must swap in the replacement package during their Clarity upgrade — note this in the upgrade guide.

---

## 3. Sequenced Build Plan

Ordering is strictly dependency-driven. Each phase produces testable output before the next begins. Phases 1–6 are Clarity-repo work; Phase 7 is skeleton-repo work; Phase 8 is release engineering across both.

### Phase 0 — Repository scaffolding (both repos)

Create the two repositories with final `composer.json` files: Clarity with PSR-4 `"Monad\\Clarity\\": "src/"`, `"bin"` omitted (stub lives in skeleton), Carbon and ramsey/uuid as requires, PHPUnit + FakerPHP as require-dev; skeleton with `"monad/clarity": "^1.0"` and `"App\\": "app/"`. Set the PHP version floor (Open Decision #1 — blocking). Initialise `CHANGELOG.md`, `LICENSE`, CI (lint + PHPUnit), branch protection, and `CrossRepoContracts.md` defining the skeleton-visible API surface. Nothing else starts until this lands.

### Phase 1 — Foundations (zero-dependency layer)

Build in this order: the seven `Utils` classes (pure functions over PHP stdlib, trivially unit-testable, needed by nearly everything downstream), then `Services\Event` (tiny sync dispatcher, no deps), then `Middlewares\Logger` (needs `Utils\Redactor`; establishes the three log files and PSR-3 surface that Mediator and everything else will log through), then `Services\HttpClient` (cURL wrapper, PSR-18; needed later by LLM and Google SSO). Rationale: everything in this phase has no dependency on the HTTP or data layers, and everything after this phase depends on at least one of these.

### Phase 2 — HTTP core (upgrade wave 1)

Upgrade `Request`, `Response`, `Route`, `View`, and relocate/rename `MetaTag`. Then upgrade `Mediator` last in this phase, since its production renderer requires Logger (Phase 1). Exit criteria: a request can be routed through middleware to a controller, rendered via View or returned as JSON, with dev and prod exception rendering working end to end under the built-in server.

### Phase 3 — Data layer

`DB` upgrade first (PDO baseline, connection management, parameterised queries), then `Schema` (dialect abstraction: MySQL default, PostgreSQL, SQLite; UUID-default primary keys with integer option), then `Migration` (all eight §12 requirements, including idempotent DDL export), then `Cache` (file driver first, then DB driver against the fixed `caches` DDL — `key_hash BINARY(32)` PK over SHA-256 of the key; driver must compare `cache_key` on read rather than trusting the hash alone — then Redis adapter; PSR-16 surface). Migration depends on Schema; Cache's DB driver depends on DB.

### Phase 4 — Console

`Console\Console` kernel first: argv parsing, command registry, `app/routes/cli.php` loading, help output, exit codes, shared output formatting. Then the 15 commands, grouped: the four `make:*` generators (template-driven, low risk), the three `migrate*` commands plus `db:seed`/`db:execute` (thin wrappers over Phase-3 Migration), `setup` (creates `sessions` + `caches` tables), and the operational set (`serve` on port 8000, `health` with its five checks, `test`, `cache:clear`, `logs:clear`). The skeleton's `mitosis` stub is written in Phase 7 but can be simulated in Clarity's test suite now.

### Phase 5 — Session & security middlewares

`Session` upgrade first (DB-backed per the §17 DDL with `user_id` nullable, enabling guest/pre-login sessions). Then `Csrf` (depends on Session for token storage, `Utils\HMAC` + `ConstantTime` for the session-less path). Then `RateLimiter` (depends on Cache for counters). Then `Authentication` — the largest single item in 26.07 (16 requirements) — depending on Session, Event, `Utils\Hash`, RateLimiter (login throttling), and HttpClient (Google SSO). Then `RBAC` (depends on Authentication's user resolution). `CORS` (§30) and `Jsonify` (§31) close the phase; note Jsonify can be built as early as Phase 2 if convenient, since it only depends on Request. Wire Event emissions as they become real: successful/failed login, user registered (here), file uploaded (retrofit Phase 2's Files), migration completed (retrofit Phase 3).

### Phase 6 — LLM

`Services\LLM` facade defining the ten-field contract (§11.3), then the four adapters in shipping-priority order: Anthropic, OpenAI, then DeepSeek and Gemini. Each adapter translates the contract to provider wire format over HttpClient; no agents, tools, memory, or cross-provider retries per §11.4. If the 26.07 date tightens, DeepSeek/Gemini are the designated cut — adapter additions are semver-minor.

### Phase 7 — Skeleton assembly

Write the three thin entry points: `public/index.php`, `config/bootstrap.php` (autoloader + `.env` + hand config to Clarity's kernel — nothing else), and the `mitosis` stub delegating to `Console\Console::run($argv)`. Populate `config/*.php`, `.env_example`, `CLAUDE.md.example`, `app/middlewares` extension stubs, the example `UserController`/`UserModel`/views, `public/router.php`, and Vite/Tailwind/jQuery wiring. Verify `composer create-project` from a local path produces a working app: `php mitosis setup && php mitosis serve` renders the Home view.

### Phase 8 — Release engineering

Test coverage per `TestingStrategy.md`; `php mitosis health` and `php mitosis test` green on a fresh create-project; README for both repos; CHANGELOG entries; tag `monad/clarity 1.0.0` first, point the skeleton at `^1.0`, tag the skeleton, submit both to Packagist; verify the full public flow (`composer create-project monad/skeleton NewApp`) from Packagist, not local paths. 26.07 ships when that command works for a stranger.

### 1.0.0 acceptance gate (summary)

`create-project` from Packagist succeeds; all 15 mitosis commands function; `health` passes all five checks on a fresh install; test suite green; both READMEs and `CrossRepoContracts.md` published; no placeholder or non-functional code on `main` (Checkout excluded).

---

## 4. Decisions — ALL RESOLVED

All seven open decisions were resolved on 2026-07-12. The build is unblocked.

1. **PHP version floor: `>=8.2`.** Set in both repos' `composer.json` (`"php": ">=8.2"`), CI matrix, and READMEs.
2. **PSR depth: bridge approach adopted.** Implement PSR-3 (Logger), PSR-16 (Cache), PSR-18 (HttpClient) interfaces directly. Request/Response stay Monad-native with `toPsr7()` / `fromPsr7()` bridge methods satisfying §20.7. Record in `Architecture.md`.
3. **`sessions.user_id` becomes nullable.** Enables guest/pre-login sessions and DB-backed pre-auth CSRF tokens. **Residual action:** the §17 DDL must be edited — `user_id char(36) COLLATE utf8mb4_unicode_ci NULL` — before it feeds `setup` or implementation prompts.
4. **`Utils\Number` dropped.** Replaced by a mature Packagist package where needed. Note in the upgrade guide for existing internal apps.
5. **CORS specced as §30, Jsonify as §31.** Requirements now in the release notes. **Jsonify ↔ Request contract confirmed:** `Request::json()` reads Jsonify's data bag when the middleware ran; lazy-parses with identical defaults (`JSON_THROW_ON_ERROR`, bigints-as-strings, same depth limit) when it did not. **Residual action:** record this contract verbatim in `CrossRepoContracts.md`. §31.3.15's deferrals (streaming JSON, schema validation, strict duplicate-key detection) are the parking lot.
6. **`caches` DDL defined and finalised** (release notes, under §26): `key_hash BINARY(32)` PK over SHA-256 of the key, `cache_key VARCHAR(512)`, `cache_value LONGBLOB` with `encoding` column, `expires_at DATETIME NULL` (= never expires, mapping PSR-16 `ttl: null`), index on `expires_at`. Timestamp columns harmonised to `DATETIME` (second precision), matching the `sessions` table — sufficient for PSR-16 TTLs, which are integer seconds. Driver rule: compare `cache_key` on read; never trust the hash alone.
7. **`php mitosis test` = PHPUnit wrapper.** Delegates to the bundled PHPUnit against the skeleton's test suite; no bespoke runner.

---

## 5. Solo-Builder Sequencing Notes

Phases 1–3 are the highest-leverage stretch: every later item consumes them, and they are the most unit-testable (fast feedback, low rework risk). Authentication (§15) is the single largest work item and carries the most security exposure; schedule it when uninterrupted time is available, not fragmented between client work. The designated scope-relief valves, in order of least damage: cut DeepSeek + Gemini adapters (semver-minor later), cut the Cache Redis adapter (file + DB drivers satisfy the majority case), cut S3 from Files (filesystem adapter first, S3 as 26.08). Do not cut: Utils, Logger, Mediator's production renderer, Csrf, or any migrate command — these are the "fundamentals are given" layer, and shipping without them contradicts the framework's stated philosophy.
