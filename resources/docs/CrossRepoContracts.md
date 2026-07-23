# CrossRepoContracts.md — gaia/monad-clarity ↔ gaia/monad-skeleton

**CANONICAL COPY.** This file lives canonically in the `gaia/monad-clarity` repository.
The copy in `gaia/monad-skeleton` is a mirror; on any discrepancy, this copy wins.
When this file changes, sync the mirror in the same working session.

**Purpose.** The skeleton is cloned once at `composer create-project` and then owned by the
developer forever — it never updates via Composer. Clarity updates freely via
`composer update gaia/monad-clarity`. Everything in this document is therefore a
compatibility promise: Clarity may change anything NOT listed here without a major version;
anything listed here changes only under semver-major.

---

## 1. Package relationship

- Skeleton `composer.json` requires `"gaia/monad-clarity": "^1.0"` and maps `"App\\": "app/"`.
- Clarity `composer.json` maps `"Gaia\\Clarity\\": "src/"`, requires `"php": ">=8.2"`,
  bundles `nesbot/carbon` and `ramsey/uuid`, and carries PHPUnit + FakerPHP as dev dependencies.
- `resources/` and `CLAUDE.md` in the Clarity repo are committed to GitHub but excluded from
  the Packagist dist via `.gitattributes` `export-ignore`.

## 2. Entry-point contracts (skeleton-owned, frozen at create-project — keep THIN)

These three skeleton files are the only skeleton code that calls into Clarity's boot surface.
Their required behaviour, and nothing more:

1. **`public/index.php`** — registers the Composer autoloader via `config/bootstrap.php`
   and hands the web request to Clarity's kernel. No logic.
2. **`config/bootstrap.php`** — the single shared boot path for web, CLI, and scripts.
   Responsibilities limited to: require the Composer autoloader, load `.env`, hand config
   paths to Clarity. Anything beyond this belongs in Clarity.
3. **`mitosis`** — thin CLI stub. Shape (normative):

   ```php
   #!/usr/bin/env php
   <?php
   require __DIR__ . '/config/bootstrap.php';
   exit(Gaia\Clarity\Services\Console::run($argv));
   ```

**Clarity's promise:** the signatures these three files call — the kernel boot entry and
`Gaia\Clarity\Services\Console::run(array $argv): int` — are stable for the life of a major
version. Changing them is a semver-major event, because every scaffolded app in the wild has
these files frozen.

## 3. Console contract

- The console kernel is `Gaia\Clarity\Services\Console` (file: `src/Services/Console.php`).
  `Gaia\Clarity\Services\Console::run(array $argv): int` parses arguments, dispatches to the
  command classes under `src/Console/` (`Gaia\Clarity\Console\*`), and returns a process exit
  code (0 success, non-zero failure).
- The kernel loads `app/routes/cli.php` from the application, where developers register
  custom commands. The registration API exposed to `cli.php` is part of this contract.
- The 15 built-in commands and their names (`make:controller`, `make:model`, `make:migration`,
  `make:service`, `migrate`, `migrate:status`, `migrate:rollback`, `db:seed`, `db:execute`,
  `test`, `health`, `serve`, `cache:clear`, `logs:clear`, `setup`) are stable; removing or
  renaming one is semver-major. Adding commands is semver-minor.
- `test` delegates to the bundled PHPUnit against the skeleton's test suite. No bespoke runner.
- `serve` binds the PHP built-in server to port 8000 using `public/router.php`.

## 4. Skeleton directory expectations (Clarity reads/writes these paths)

Clarity may assume the following skeleton paths exist; renaming them in a scaffolded app is
developer-owned breakage:

- `app/routes/{web,api,cli}.php` — route registration files loaded by Route / Console.
- `app/middlewares/` — thin classes extending `Gaia\Clarity\Middlewares\*`.
- `app/views/` — View service root, with `Layouts/` and `Errors/` as first-class groups.
- `config/*.php` — configuration exposed to Clarity at boot.
- `database/migrations/`, `database/seeds/` — Migration service input.
- `storage/cache/`, `storage/logs/error/{app,db}.log`, `storage/logs/event/timeline.log`,
  `storage/userfiles/` — Cache, Logger, and Files service targets. Must be writable.
- `public/` — web root; `public/router.php` for the built-in server.

## 5. Middleware extension contract

Clarity middleware engines (`Gaia\Clarity\Middlewares\*`) are designed for extension: the
skeleton ships thin subclasses in `app/middlewares/` that developers customise. Therefore the
protected/extension surface of each middleware engine (hook methods, overridable configuration)
is part of this contract — narrowing it is semver-major. Security-critical internals remain
private and may change in any release; that is the point of the split.

## 6. Jsonify ↔ Request contract (confirmed 2026-07-13)

`Request::json()` reads Jsonify's separate JSON data bag when the Jsonify middleware ran on
the request. When Jsonify did NOT run, `Request::json()` lazy-parses the raw body itself using
**identical defaults**: `json_decode` with `JSON_THROW_ON_ERROR`, large integers as strings
(`JSON_BIGINT_AS_STRING`), associative arrays, and the same configurable maximum depth.
Behaviour of `Request::json()` must be indistinguishable to the caller regardless of whether
Jsonify was in the pipeline, except that Jsonify additionally enforces media-type detection,
body-size limits, structured `400` errors, and `415` for JSON-required routes.

## 7. PSR compatibility contract (decided 2026-07-12)

- Logger implements PSR-3, Cache implements PSR-16, HttpClient implements PSR-18 — directly.
- Request/Response are Monad-native. PSR-7 compatibility (§20.7) is satisfied via
  `toPsr7()` / `fromPsr7()` bridge methods, NOT by implementing PSR-7 interfaces on the
  core classes. The bridge methods are part of this contract.

## 8. Database contracts (setup-owned tables)

`php mitosis setup` creates the Monad built-in tables. Their DDL is fixed in
`ReleaseNotes_26.07.md` and is a compatibility surface:

- **`sessions`** (§17): UUID `char(36)` PK; `user_id` NULLABLE (guest/pre-login sessions and
  pre-authentication CSRF token storage are valid); unique `digest`; JSON `payload`;
  `expire_at` / `revoked_at`.
- **`caches`** (§26): `key_hash BINARY(32)` PK = SHA-256 of `cache_key`;
  `cache_key VARCHAR(512)`; `cache_value LONGBLOB` with `encoding` column;
  `expires_at DATETIME NULL` = never expires (PSR-16 `ttl: null`).
  Driver rule: compare `cache_key` on read; never trust the hash alone.
- Convention: built-in tables use `DATETIME` (second precision) and UUID `char(36)`
  primary keys. Schema service defaults to UUID PKs with a configurable integer option (§10.5).
- Altering a setup-owned table's DDL is semver-major and requires a shipped migration.

## 9. Versioning & release policy

- Both packages follow strict semver with tagged releases and CHANGELOG entries.
- Clarity `1.0.0` is tagged first; the skeleton pins `^1.0` and is tagged after.
- The name `Checkout` and namespace `Gaia\Clarity\Services\Checkout` /
  `Gaia\Clarity\Services\CheckoutAdapters\*` are RESERVED but deferred: they must not appear
  on `main` or in any tagged release until formally scheduled (see ReleaseNotes §9).

## 10. Change procedure for this document

Any change here requires: (1) edit the canonical copy in the Clarity repo, (2) sync the
skeleton mirror in the same session, (3) note the change in Clarity's CHANGELOG.md, and
(4) classify the change under semver before implementing code against it.
