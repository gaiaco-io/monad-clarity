# TestingStrategy.md — monad/clarity

## Tooling

PHPUnit (bundled dev dependency) for all tests; FakerPHP (bundled dev dependency) for
generating test fixtures — user records, request payloads, file uploads, etc. Tests live in
`resources/tests` (excluded from the Packagist dist per `Architecture.md` §10; present in the
git repo for contributors and CI).

## Core rule: tests are written alongside implementation, never after

Per `CLAUDE.md` and the phase discipline in `GapAnalysis_BuildPlan_26.07.md`: no phase or
sub-phase is considered complete until its PHPUnit tests are green. "Tests later" is treated
the same as a placeholder — not permitted per the no-partial-implementation rule.

## Priority tiers

**Tier 1 — security-critical, highest rigor, adversarial test cases required:**
`Middlewares\Csrf`, `Middlewares\Authentication`, `Middlewares\RateLimiter`,
`Middlewares\RBAC`, `Utils\ConstantTime`, `Utils\Encryption`, `Utils\Hash`, `Utils\HMAC`,
`Utils\CryptographicToken`, `Utils\SignedURL`. Tests must include failure/attack-shaped
inputs, not just happy-path: token replay, timing-attack resistance (statistical, not just
functional, for `ConstantTime`), expired/tampered signed URLs, brute-force throttling
thresholds, rehash-on-verify behaviour for `Hash`.

**Tier 2 — data integrity:** `Services\Schema`, `Services\Migration`, `Services\Session`,
`Services\Cache` (all three drivers), `Services\DB`. Tests must cover the DDL in `DDL.sql`
directly — round-trip a session with `user_id = NULL`, verify the Cache DB driver rejects a
`cache_key` collision at the same `key_hash` (per `Architecture.md` §9), verify migration
rollback restores prior schema state exactly.

**Tier 3 — HTTP core:** `Services\Request`, `Services\Response`, `Services\Route`,
`Services\View`, `Services\Mediator`, `Middlewares\Jsonify`, `Middlewares\CORS`,
`Middlewares\MetaTag`. Cover the full accessor surface in `API_Contracts.md`, the 404-vs-405
distinction, the Jsonify↔Request contract from `CrossRepoContracts.md` §6 explicitly (both
branches: middleware ran / middleware absent), and Mediator's dev vs prod renderer outputs.

**Tier 4 — pure utilities and integrations:** `Utils\Redactor`, `Services\Event`,
`Services\HttpClient`, `Services\LLM` + adapters. LLM adapter tests mock HttpClient — no live
provider API calls in the automated suite (cost, flakiness, and secrets exposure).

**Tier 5 — Console:** kernel dispatch (`Services\Console::run()`), each of the 15 command
classes individually, using a temp filesystem/SQLite fixture rather than touching a real
project. `make:*` commands are tested by asserting generated file content and location; `db:*`
and `migrate*` commands are tested against a throwaway test database.

## Coverage philosophy

No arbitrary percentage target. The bar is: no security-relevant code path (Tier 1) ships
untested, and every public method listed in `API_Contracts.md` has at least one passing test
exercising its documented contract. Coverage tooling (if added) reports gaps; it does not set
policy by itself.

## Integration testing against the skeleton

Because Clarity has no runnable application of its own, end-to-end verification happens by
`composer create-project`-ing (or path-repository-installing during development) the skeleton
against a local working copy of Clarity, then running `php mitosis setup && php mitosis health
&& php mitosis test`. This is the closest thing to a smoke test and should be run at the end
of every build-plan phase that touches a skeleton-visible contract (per `CrossRepoContracts.md`).

## CI requirements

Every pull request runs the full PHPUnit suite against the PHP version floor (`>=8.2`, plus
at least one newer minor, e.g. 8.3) before merge. `CHANGELOG.md` entry presence is checked per
`ReleasePolicy.md`. No merge to `main` with a red test suite, regardless of urgency.
