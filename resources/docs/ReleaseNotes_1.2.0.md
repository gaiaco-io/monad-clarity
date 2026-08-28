# Monad Clarity 1.2.0 Release Notes

**Status:** FROZEN — canonical specification for the 1.2.0 release.
This document is the source of truth for WHAT ships in 1.2.0. It does not restate 1.0.0;
`ReleaseNotes_1.0.0.md` remains the frozen specification for everything released there.

> **Scheduling note.** `Services\Checkout` and `Services\CheckoutAdapters\*` were specified
> in `ReleaseNotes_1.0.0.md` §9 and **deferred** — reserved namespace, never on `main`, per
> `Architecture.md` §8. That deferral is lifted by this release. Checkout is now formally
> scheduled, and 1.2.0 ships the first part of it. The scope below is deliberately narrower
> than §9 as written; §9's remaining requirements are listed as open, not as shipped.

## 1. What ships in 1.2.0

### 1.1 Clarity Checkout Service

Namespace `Monad\Clarity\Services\Checkout` — a thin facade defining the shared contract
every payment gateway adapter implements, following the service/adapter pattern
`Architecture.md` §7 establishes for multi-provider services (the same shape as
`Services\LLM` + `Services\LLMAdapters\*`).

Four operations, covering the gateway-facing half of `ReleaseNotes_1.0.0.md` §9.6:

| Method | §9.6 requirement |
| --- | --- |
| `createCheckout(CheckoutRequest): CheckoutSession` | §9.6.1 — checkout / authorisation with payment gateway |
| `retrieveStatus(string, int): TransactionSnapshot` | §9.6.3 — transaction status re-query |
| `parseCallback(string, array): CallbackEvent` | §9.6.4 — capture callbacks from payment gateway |
| `refund(RefundRequest): RefundResult` | §9.6.6 — initiate refunds |

Supporting value objects live in `Monad\Clarity\Services\Checkout\*`: `Money`, `LineItem`,
`TransactionStatus`, `CheckoutRequest`, `CheckoutSession`, `TransactionSnapshot`,
`CallbackEvent`, `RefundRequest`, `RefundResult`, `CheckoutException`.

### 1.2 Transaction ledger

`Monad\Clarity\Services\Checkout\TransactionLedger` — the stateful half of §9.6, kept out
of the facade so nine future gateways share one persistence layer rather than each carrying
a copy of it.

- §9.6.2 — transaction creation, status `pending`.
- §9.6.5 — transaction update after status re-query or callback capture
  (`success` / `failed` / `cancelled`).
- §9.6.8 — the built-in table structure: transaction records (§9.6.8.1) and immutable,
  insert-only status records with a failure reason column (§9.6.8.2).

### 1.3 Stripe Checkout adapter

`Monad\Clarity\Services\CheckoutAdapters\StripeCheckout` — Stripe's hosted payment page via
the Checkout Sessions API, with refunds through `/v1/refunds` and callbacks through Stripe's
signed webhook scheme.

Speaks Stripe's form-encoded REST API through the existing `Services\HttpClient`. **No new
Composer dependency**: signature verification uses `Utils\HMAC` and `Utils\ConstantTime`,
which already provide the primitives.

### 1.4 `php mitosis checkout:install`

A sixteenth built-in `mitosis` command, creating the three checkout tables. Deliberately
separate from `setup`:

- The checkout tables are **not** a setup-owned compatibility surface —
  `CrossRepoContracts.md` §8 names exactly `sessions` and `caches`, and that set is
  unchanged by this release.
- Payments are opt-in. An application that takes none runs `setup` and carries no payment
  tables at all.

Tables created: `checkout_transactions`, `checkout_transaction_statuses`, `checkout_refunds`.
Conventions follow `Architecture.md` §9 — UUID `char(36)` primary keys, `DATETIME` at second
precision.

## 2. Resolved specification decisions

Three points where §9 as written did not determine the implementation. Recorded here because
`ReleaseNotes_1.0.0.md` is frozen and cannot carry them.

### 2.1 A refund is a record, not a status

§9.6.5 enumerates terminal statuses as exactly `success` / `failed` / `cancelled`, while
§9.6.6 requires refunds and §9.6.8.2 makes status rows insert-only — leaving no stated place
for a refund to land.

**Resolved:** `TransactionStatus` stays exactly four cases, and refunds are their own records
in `checkout_refunds`. A transaction that succeeded stays `success` for its lifetime while
refunds accumulate against it. A fifth `refunded` status was rejected because a *partially*
refunded transaction has no honest single-status answer, and because it would contradict
§9.6.5's enumeration.

### 2.2 Amounts are integer minor units

`Money` holds an integer count of a currency's smallest indivisible unit beside its ISO 4217
code. No decimal or float type appears anywhere in Checkout: zero-decimal currencies (JPY,
KRW, VND) do not scale by 100, and three-decimal ones (BHD, KWD, TND) do not scale by 100
either — any conversion layer would be a rounding bug waiting for the first non-USD merchant.

### 2.3 Idempotency is keyed on the gateway's own identifiers

Gateways redeliver callbacks aggressively and by design, and a gateway call that times out
after being accepted will be retried. Every write that can arrive twice is keyed on the
gateway's own id under a unique index — `gateway_event_id` for callbacks,
`gateway_refund_id` for refunds — so recording either is safe to repeat.

Append-only rows carry a **UUIDv7** primary key rather than the v4 the rest of the framework
defaults to. Built-in tables store `DATETIME` at second precision, so `created_at` alone
cannot order a history, and a v4 tie-break would order same-second rows at random. v7 sorts
lexically in generation order, making `ORDER BY created_at, id` genuinely chronological
without a schema-level sequence column — no portable one exists, since MySQL and SQLite both
refuse a second auto-increment column.

## 3. Explicitly NOT in 1.2.0

These remain specified in `ReleaseNotes_1.0.0.md` §9 and unbuilt. They are open requirements,
not shipped ones:

- **§9.5 — custom checkout page.** 1.2.0 supports the gateway-hosted page only. Stripe's
  custom-page path (PaymentIntents + Elements) is not implemented.
- **§9.6.7 — built-in report generation.** Deferred to its own sub-phase; reports are
  read-only queries over a ledger that had not run when 1.2.0 was scoped.
- **Eight of the nine gateways in §9.3/§9.4.** `StripeConnectExpress`, `Fiuu`, `iPay88`,
  `BillPlz`, `Adyen`, `Airwallex`, `HitPay`, and `Xendit` are unbuilt. Their namespaces
  remain reserved. Adding an adapter is semver-additive, exactly as
  `GapAnalysis_BuildPlan_1.0.0.md` contemplates for LLM adapters under its scope-relief
  valves — each ships in a later minor when built end to end.

Nothing above is stubbed. Per `CLAUDE.md`'s no-partial-implementation rule, an unbuilt
adapter is an absent file, never a placeholder class.

## 4. Compatibility

Semver **minor**. Every change is additive:

- New namespaces `Services\Checkout`, `Services\Checkout\*`, `Services\CheckoutAdapters\*`.
- New built-in command `checkout:install` — an addition to the command registry, which
  narrows nothing. `CrossRepoContracts.md` §3 now lists it among the stable command names.
- No change to any setup-owned table (`sessions`, `caches`), so `CrossRepoContracts.md` §8's
  compatibility surface is untouched and no migration path is required.
- No change to the three entry-point signatures or `Services\Console::run()`.
- No existing public method signature changed or removed.

## 5. Verification

- Full PHPUnit suite green, including the security-critical callback path: valid signature,
  tampered payload, wrong signing secret, stale timestamp outside the replay tolerance,
  malformed and absent signature headers, and secret rotation with multiple signatures.
  Adapter tests mock `HttpClient` — no live gateway calls in the automated suite, matching
  `TestingStrategy.md` Tier 4's rule for LLM adapters.
- The ledger's tests run against the very blueprint closures `checkout:install` emits, so the
  shipped DDL and the code that writes to it cannot drift.

**Outstanding before tagging** (see `ReleasePolicy.md` § Packagist publication checklist):

1. `php mitosis health` green on a fresh `create-project` against the **tagged** version, not
   a path repository — requires the skeleton repo and a pushed tag.
2. `CrossRepoContracts.md` mirror in `monad/skeleton` synced per its §10 procedure.
3. A live Stripe **test-mode** run against the real API. Every automated test here mocks
   `HttpClient`, so the wire format, the webhook signature scheme, and the refund path have
   never touched Stripe's servers. This is a payment integration; mocked coverage is not
   evidence that it works.
