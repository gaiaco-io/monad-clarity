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
- Checkout was DEFERRED through 1.1.0 and was formally scheduled for 1.2.0. `Checkout.php`,
  `Checkout/`, and `CheckoutAdapters/StripeCheckout.php` are now on `main`, joined in 1.3.0 by
  `CheckoutAdapters/PaddleCheckout.php` and in 1.4.0 by `CheckoutAdapters/PaddleSubscription.php`
  (recurring billing) plus the `SpeaksPaddle` trait the two Paddle adapters share. The eight
  remaining adapters (`StripeConnectExpress`, `Fiuu`, `iPay88`, `BillPlz`, `Adyen`, `Airwallex`,
  `HitPay`, `Xendit`) are still unbuilt: their namespaces are reserved, and an unbuilt adapter
  must be an absent file, never a stub. That list is the roadmap's current state, not a closed
  set — §9's roster is illustrative, so a gateway it never named may be built
  (`ReleaseNotes_1.3.0.md` §2.1), and one gateway may have more than one adapter where its flows
  genuinely differ. §9.5 (custom checkout page) and §9.6.7 (reports) are still open — see
  `ReleaseNotes_1.2.0.md`.
- IMPORTANT: `Services\Checkout` takes **no fifth abstract method**. Adding one breaks every
  downstream adapter and is semver-major (`ReleaseNotes_1.3.0.md` §2.4). Capabilities only some
  gateways have — subscriptions, payouts, connected accounts — are public methods on the
  adapter that has them.
- Semver strictly: patch = fixes, minor = additive, major = breaking. Update CHANGELOG.md with every change.
- Built-in tables use `DATETIME` (second precision) and UUID `char(36)` primary keys by default.
- Setup-owned tables are exactly `sessions` and `caches`. A feature that needs its own table
  ships an opt-in, re-runnable `*:install` command instead (`checkout:install`,
  `schedule:install`) — never an addition to `setup` or to `DDL.sql`.
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
- `ReleaseNotes_1.0.0.md` — WHAT ships: every component's requirements (§1–§31). Canonical spec.
- `GapAnalysis_BuildPlan_1.0.0.md` — WHEN/in what order: 8 dependency-sequenced phases,
  resolved decisions, acceptance gate detail.
- `Architecture.md` — WHY each structural decision was made (namespace, PHP floor, middleware
  and Console boundaries, PSR strategy, facade/adapter pattern, Checkout scheduling, data
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
- `ReleaseNotes_1.2.0.md` — WHAT ships in 1.2.0 (Checkout + StripeCheckout), what explicitly
  does not, and the three §9 specification gaps resolved there. Canonical for Checkout;
  `ReleaseNotes_1.0.0.md` §9 is the frozen original spec and is not edited.
- `ReleaseNotes_1.3.0.md` — WHAT ships in 1.3.0 (PaddleCheckout), and four further §9
  decisions: that the gateway roster is illustrative rather than closed, and the three places
  Paddle's behaviour differs from a conventional PSP (no gateway-hosted page, no idempotency
  keys, asynchronous refunds). Read before adding any adapter beyond the reserved eight.
- `ReleaseNotes_1.4.0.md` — WHAT ships in 1.4.0 (PaddleSubscription, SubscriptionLedger, the
  `checkout_subscriptions` table, the `SpeaksPaddle` extraction), and eight further §9 decisions.
  Read before touching subscriptions. The three most load-bearing: a subscription is born from a
  transaction so `createCheckout()` returns a `txn_` and never a `sub_` (§2.3); `past_due` maps
  to `Pending` for a subscription and `Failed` for a one-time payment, which is why
  `mapTransactionStatus()` is abstract on the shared trait (§2.6); and `checkout_subscriptions`
  is mutable, so its idempotency is an honestly weaker monotonic guard rather than the
  unique-index guarantee `TransactionLedger` gives (§2.5).
- `ReleaseNotes_1.5.0.md` — WHAT ships in 1.5.0 (`Services\Scheduler`, its cron parser and run
  ledger, `schedule:install` and `schedule:run`), and nine further decisions. Read before
  touching scheduled work. The three most load-bearing: this is the first service the frozen
  1.0.0 spec never contemplated, and §2.1 records that scope expansion rather than assuming it
  (§9's "illustrative roster" hatch does not reach a new service); the guarantee is **at most
  one run per job per minute cluster-wide, not at-least-once** (§2.4); and `schedule:run`
  prints nothing when nothing happened, which is why its crontab line is documented without
  `> /dev/null 2>&1` (§2.8).
- `ReleaseNotes_1.6.0.md` — WHAT ships in 1.6.0 (`Services\Mail`, its value objects and MIME
  builder, seven `MailAdapters\*`, and the `MailerPool` that gives optional failover), and
  sixteen decisions. Read before touching mail. The three most load-bearing: `Mail` declares
  **no constructor** — the first Clarity abstraction whose implementations do not share one,
  since SMTP has neither an API key nor an HttpClient (§2.2); failover keys on **whose fault
  it is** (`FailureScope::Mailer` vs `::Message`), never on the status code, which is why a
  `401` fails over and a malformed recipient does not (§2.4); and the guarantee is **at least
  once, not exactly once** — the honest inverse of the Scheduler's, because no cross-provider
  idempotency key exists (§2.5). `MimeMessage` never emits a `Bcc:` header and header
  injection is refused at construction (§2.12, §2.13) — both security-critical.
- `GapAnalysis_BuildPlan_1.6.0.md` — the five-phase build sequence for 1.6.0 and its
  acceptance gate. Superseded as specification by the release notes above; still live for
  sequence and for which phases remain.

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
