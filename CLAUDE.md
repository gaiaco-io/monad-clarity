# CLAUDE.md — monad/clarity

Clarity is the core library of the Monad Framework: an MVC-based PHP framework for solo
developers and small teams, published on Packagist under MIT. This repo is the library only;
the application skeleton lives in the separate `monad/skeleton` repo.

## Philosophy (non-negotiable)

- Light scaffolding: if it's not necessary, don't implement it. No abstraction for aesthetics.
- Elegant coding: code reads like clear prose; every line has a meaningful purpose.
- Fundamentals are given: security, performance, and developer empathy are built in, never bolted on.
- Beautifully done: from source code to exception output, human-comprehensible at a glance.
- Freedom: Monad enables, it never dictates. No framework opinions forced on developers.
- Very fast: in development and at runtime.
- Reference Laravel/Symfony for what mature frameworks offer; do NOT adopt their philosophy.

## Hard rules

- PHP floor: `>=8.2`. Use readonly classes and typed constants where they clarify.
- PSR-4: `"Monad\\Clarity\\": "src/"`. Namespace segments match directory names exactly (case-sensitive).
- Console kernel is `Monad\Clarity\Services\Console` (stable contract); command classes live
  under `Monad\Clarity\Console\*` (internal, reorganise freely — see `Architecture.md` §5).
- IMPORTANT: No placeholders, no mock-only flows, no TODO-only code, no partial implementations.
  Every feature is built end to end in production-ready form or not started.
- Checkout (`src/Services/Checkout.php`, `src/Services/CheckoutAdapters/`) is DEFERRED —
  it must never exist on `main` or in any tagged release until formally scheduled.
- Semver strictly: patch = fixes, minor = additive, major = breaking. Update CHANGELOG.md with every change.
- Built-in tables use `DATETIME` (second precision) and UUID `char(36)` primary keys by default.
- `sessions.user_id` is NULLABLE (guest/pre-login sessions are valid).
- Cache DB driver: always compare `cache_key` on read; never trust `key_hash` alone.
- Make minimal changes — do not refactor unrelated code.
- When unsure between two approaches, explain both and let Marshal choose.
- `resources/` and `CLAUDE.md` are committed to GitHub but export-ignored from the Packagist
  dist via `.gitattributes`. Never move them into `src/`.

## Source-of-truth documents (read before implementing)

All in `resources/docs/` (git-tracked, export-ignored from the Packagist dist). This repo is
canonical for every document below — where the skeleton repo carries a mirror, this copy wins.

- `PRD.md` — WHY this release exists: purpose, audience, scope, non-negotiable constraints,
  1.0.0 acceptance gate. Start here for orientation.
- `ReleaseNotes_26.07.md` — WHAT ships: every component's requirements (§1–§31). Canonical spec.
- `GapAnalysis_BuildPlan_26.07.md` — WHEN/in what order: 8 dependency-sequenced phases,
  resolved decisions, acceptance gate detail.
- `Architecture.md` — WHY each structural decision was made (namespace, PHP floor, middleware
  and Console boundaries, PSR strategy, facade/adapter pattern, Checkout deferral, data
  conventions). Read before questioning why something is structured the way it is.
- `API_Contracts.md` — the method-level public API surface: every service/middleware's
  signatures. What application code and the skeleton write against.
- `CrossRepoContracts.md` — the boundary contract with `monad/skeleton`: entry-point
  signatures, Console kernel contract, stable command names, setup-owned table ownership,
  Jsonify↔Request contract, PSR bridge decision. Narrowing anything here is semver-major.
- `DDL.sql` — the two setup-owned table definitions (`sessions`, `caches`), consolidated.
- `DeploymentTopology.md` — runtime requirements, statelessness properties, storage/cache
  backend topology, outbound network dependencies, health-check scope.
- `ReleasePolicy.md` — semver rules, tagging order, CHANGELOG discipline, deprecation policy,
  Packagist publication checklist.
- `TestingStrategy.md` — test tiers by risk (security-critical first), coverage philosophy,
  CI requirements, skeleton-integration smoke testing.
- `RepoMap.md` — final directory trees for both repos.

**Conflict order:** ReleaseNotes defines requirements → CrossRepoContracts defines boundaries →
BuildPlan defines sequence → Architecture explains rationale. If two documents disagree, stop
and ask Marshal — do not guess or silently pick one.

## Commands

- Test: `vendor/bin/phpunit` (tests live in `resources/tests`; test priority tiers in `TestingStrategy.md`)
- Lint/static analysis: as configured in CI
- This package has no runnable app; end-to-end verification happens against a local
  `create-project` of monad/skeleton pointing at this working copy via a path repository.

## Workflow

- Work one build-plan phase (or sub-phase) per session. State the current phase at session start.
- Before marking any phase done: tests green, CHANGELOG.md updated, no stray files in `src/`.
- Write PHPUnit tests alongside every class, in the same phase — never "tests later".
- Exception messages and CLI output are user-facing product surface: write them beautifully.
