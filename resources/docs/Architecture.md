# Architecture.md — monad/clarity

Resolved architectural decisions for the Clarity core library, with rationale. This document
records WHY; `ReleaseNotes_1.0.0.md` records WHAT; `CrossRepoContracts.md` records the
compatibility promises that follow from these decisions. Where documents disagree, stop and ask Marshal.

## 1. Two-package split

Monad ships as two Composer packages: `monad/skeleton` (the application scaffold, cloned
once via `create-project` and owned by the developer forever) and `monad/clarity` (this
repo — the core library, installed to `vendor/` and upgraded via `composer update`).

Rationale: structurally enforces "do not modify Monad core" — nobody edits `vendor/` by
accident — and lets every scaffolded app receive core fixes with one command. Mirrors the
Laravel `laravel/laravel` vs `laravel/framework` split and the Symfony `symfony/skeleton` model.

## 2. Namespace

Root namespace is `Monad\Clarity\`, PSR-4 mapped to `src/` (`"Monad\\Clarity\\": "src/"`).

Rationale: a bare `Clarity\` root would claim a generic namespace with collision risk on
Packagist; `Monad\Clarity\` is collision-proof and matches the vendor/package naming already
in use. Namespace segments must match directory names exactly — PSR-4 is case-sensitive.

## 3. PHP version floor

`>=8.2`.

Rationale: PHP 8.1 exited security support before 26.07's release; shipping a new framework
against an EOL floor invites avoidable criticism. 8.2/8.3 also provide readonly classes and
typed constants that suit Clarity's "elegant coding" principle.

## 4. Middleware boundary

Middleware *engines* live in Clarity (`Monad\Clarity\Middlewares\*`); thin extension classes
live in the skeleton's `app/Middlewares/`, extending the Clarity engines for developer
customisation.

Rationale: authentication, CSRF, and rate limiting are exactly the code that must receive
security patches via `composer update`. Shipping the logic as copy-once skeleton code would
make every scaffolded app permanently unpatched against the engine that matters most.
Developers retain freedom to customise via the extension classes, satisfying the "freedom"
principle without sacrificing patchability.

## 5. Console / mitosis boundary

The `mitosis` executable is a thin stub in the skeleton, frozen at `create-project`:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/config/bootstrap.php';
exit(Monad\Clarity\Services\Console::run($argv));
```

The console **kernel** — argument parsing, command dispatch, `app/routes/cli.php` loading,
output formatting, exit codes — lives at `Monad\Clarity\Services\Console`
(`src/Services/Console.php`). The 15 built-in **command classes** live under
`Monad\Clarity\Console\*` (`src/Console/`).

Rationale for the split within Clarity itself: `Services\Console::run()` is the one symbol
the frozen skeleton stub calls, so it is the stable, semver-locked contract surface. The
command classes are internals invoked *by* the kernel — free to be added to, reorganised, or
refactored in any minor release without breaking a single scaffolded app, because nothing
outside Clarity references them directly. This mirrors Laravel's `artisan` (skeleton) /
`Illuminate\Foundation\Console\Kernel` (framework) split.

## 6. PSR compatibility strategy

- **PSR-3** (Logger), **PSR-16** (Cache), **PSR-18** (HttpClient): implemented directly.
  Each is a small, stable interface with no ergonomic conflict with Monad's native API style.
- **PSR-7** (Request/Response): NOT implemented directly. Full PSR-7 immutability semantics
  conflict with the ergonomic accessor API specified in §20 (`$request->input('email')`,
  `$request->json('customer.name')`, etc.). Instead, Request/Response stay Monad-native and
  expose `toPsr7()` / `fromPsr7()` bridge methods, satisfying "compatible with PSR-7" (§20.7)
  without contorting the core API.

Rationale: adopt PSRs where they cost nothing and buy interoperability; bridge rather than
adopt where the PSR's design assumptions would fight the framework's own elegance principle.

## 7. Service/adapter facade pattern (LLM, Checkout)

Multi-provider services follow one shape: a thin facade defining the shared contract
(`Services\LLM.php`, `Services\Checkout.php`) plus a subdirectory of one-class-per-provider
adapters (`Services\LLMAdapters\*`, `Services\CheckoutAdapters\*`).

Rationale: provider APIs differ enough in request/response shape that a single file per
service would either grow into an unmaintainable mega-class or hide adapter logic inside
conditionals — the opposite of "every line has a meaningful purpose." One file per adapter
keeps each translation layer small, testable, and independently versionable.

## 8. Checkout deferral — lifted in 1.2.0

`Services\Checkout` and `Services\CheckoutAdapters\*` were specified (§9) but **deferred**
through 1.0.0 and 1.1.0 — not built, not released, not present on `main`, namespace reserved.

Original rationale: seven-plus payment gateway integrations are a permanent maintenance
obligation (gateway APIs change independently of Clarity's release cycle) and were the single
largest scope risk in 1.0.0. Deferring decoupled that release's timeline from gateway API
stability.

**This deferral was lifted for 1.2.0**, which ships the Checkout facade, the transaction
ledger, and the first adapter (`StripeCheckout`); 1.3.0 adds a second, `PaddleCheckout`. See
`ReleaseNotes_1.2.0.md` and `ReleaseNotes_1.3.0.md` for the exact scope of each. The reasoning
that produced the deferral is unchanged and still governs how further gateways arrive: each is
built end to end and ships in its own minor release, never as a stub. Eight of the nine
gateways in §9.3/§9.4 remain unbuilt, and their absence is an absent file rather than a
placeholder class — the "no placeholders" rule applies to an in-progress service exactly as it
applied to a deferred one. §9's roster is illustrative rather than closed, which is what lets
a gateway it never named be built at all (`ReleaseNotes_1.3.0.md` §2.1); the rate at which
gateways are added is still governed by the maintenance obligation above.

Two structural consequences of Checkout being stateful, where LLM is not:

- The facade stays a pure adapter contract (§7). Persistence lives beside it in
  `Services\Checkout\TransactionLedger`, so every gateway shares one ledger instead of each
  carrying a copy of identical database logic, and neither half needs the other to be tested.
- The checkout tables are **not** setup-owned. `mitosis setup` still creates exactly
  `sessions` and `caches`; the checkout tables come from `mitosis checkout:install`, keeping
  payment tables opt-in for applications that take no payments. See §9 and
  `CrossRepoContracts.md` §8.

## 9. Data conventions

- Built-in tables (`sessions`, `caches`) use `DATETIME` (second precision) uniformly — PSR-16
  TTLs are integer seconds, so no higher precision is needed, and uniform precision across
  built-in tables is simpler to reason about than mixed precision.
- Primary keys default to UUID `char(36)`, with Schema service offering a configurable
  integer option (§10.5).
- `sessions.user_id` is **nullable** — supports guest/pre-login sessions and, specifically,
  DB-backed CSRF token storage before authentication (§13.2 requires DB-backed CSRF tokens
  via the `sessions` table; CSRF tokens are needed on login forms, before a user exists).
- `caches.key_hash` is `BINARY(32)` (SHA-256 of the cache key) as primary key, sidestepping
  long-key index limits. The DB cache driver must compare the stored `cache_key` on read
  rather than trusting the hash alone — cheap, and makes the driver provably correct against
  the (theoretical) risk of hash collision.

## 10. Distribution hygiene

`resources/` and `CLAUDE.md` are committed to GitHub (version-controlled, full history) but
excluded from the Packagist distribution via `.gitattributes` `export-ignore`. End users
installing via Composer never receive internal planning documents or AI-assistant guardrails;
contributors working from the git repository see everything.

## 11. What Clarity is not

No agents, tool orchestration, vector databases, memory, or prompt pipelines in the LLM
service (§11.4) — it is a thin, provider-agnostic request/response contract, not an
agentic framework. No SPA framework assumptions anywhere in the core. No implicit "magic" in
View rendering (§24.4) — resolution, data merging, and layout application are explicit steps.
