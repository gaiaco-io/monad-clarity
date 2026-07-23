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
