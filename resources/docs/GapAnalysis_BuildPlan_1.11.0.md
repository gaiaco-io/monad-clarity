# Monad Clarity 1.11.0 — `StripeSubscription` Gap Analysis & Sequenced Build Plan

**Status:** DRAFT 2026-11-17, awaiting Marshal's decisions in §2. Nothing is built. Once the
decisions are taken they move, frozen, into `ReleaseNotes_1.11.0.md` (Task 1), and from then on
that document is the specification. This one stays as the build sequence and the acceptance
gate, which is how `GapAnalysis_BuildPlan_1.6.0.md` is used.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recurring billing through Stripe. The adapter is
`Services\CheckoutAdapters\StripeSubscription`, with the same public surface as
`PaddleSubscription`.

**Architecture:** A second Stripe adapter beside `StripeCheckout`, the same arrangement as
`PaddleSubscription` beside `PaddleCheckout` (`ReleaseNotes_1.4.0.md` §1.1). It reuses every
1.4.0 subscription value object and `SubscriptionLedger` unchanged. It adds no fifth method to
`Services\Checkout`, adds no table, and adds no Composer dependency. Machinery both Stripe
adapters need is extracted into a `SpeaksStripe` trait. The gate is the one 1.4.0 used:
`StripeCheckoutTest.php` must pass byte-unmodified.

**Tech Stack:** PHP ≥ 8.2, PHPUnit, Stripe REST API (form-encoded), `Services\HttpClient`,
`Utils\HMAC`, `Utils\ConstantTime`.

**Spec:** `ReleaseNotes_1.11.0.md`, written in Task 1 from §2 below. It inherits the binding parts
of `ReleaseNotes_1.4.0.md` (§2.2, §2.3, §2.5, §2.7, §2.8) and `ReleaseNotes_1.7.0.md`
(§2.3, the catalogue-mode `Money(0, …)` idiom).

**Version:** 1.11.0 (semver minor) is assumed, because every change in this plan is additive.
If another release lands first, renumber.

## Global Constraints

- PHP floor `>=8.2`. The new adapter is `final class StripeSubscription extends Checkout`.
- **No fifth abstract method on `Services\Checkout`.** Subscription operations are public
  methods on the adapter (`ReleaseNotes_1.3.0.md` §2.4 and 1.4.0 §2.2).
- No change to any `final readonly` value object's constructor or properties. That covers
  `CheckoutRequest`, `CheckoutSession`, `CallbackEvent`, `TransactionSnapshot`,
  `SubscriptionSnapshot`, `SubscriptionEvent`, `SubscriptionItem` and `BillingCycle`.
- No new enum case on `SubscriptionStatus`, `ProrationBillingMode`, `PaymentFailureBehaviour`,
  `SubscriptionEffectiveFrom` or `ResumeBilling` (D3).
- No new table and no change to `checkout:install`, `DDL.sql` or `CrossRepoContracts.md` §8.
  `checkout_subscriptions` is already gateway-agnostic.
- No new Composer dependency. `stripe/stripe-php` is deliberately not pulled in, as in 1.2.0.
- `StripeCheckout`'s public surface and its pinned `API_VERSION = '2024-06-20'` do not move.
- Gateway name: `stripe_subscription`.
- Signature verification runs before the body is decoded, in both parsers. A parser that
  promises `CheckoutException` throws nothing else, including on signed payloads that are
  malformed (1.4.0 §5, "one exception type out of a Tier 1 parser").
- No live gateway calls in the automated suite (`TestingStrategy.md`). Every adapter test uses
  `FakeHttpClient`.
- Exception messages are product surface. Each refusal names the argument, the reason, and
  what to pass instead, in the voice of the existing adapters.
- Every task lands with its tests. Before the release: CHANGELOG updated and nothing stray in
  `src/`.

## Review Focus

These are the five inputs most likely to hurt a merchant that no happy-path test would catch.
Each one is pinned by a named test in the task that owns the code.

1. **A webhook endpoint pinned to an older Stripe API version.** Stripe renders each event in
   the *endpoint's* API version, not the version the adapter sends. A merchant whose endpoint
   predates basil (2025-03-31) gets `current_period_*` on the subscription itself and
   `invoice.subscription` at the top level. Both parsers must read both shapes, preferring the
   newer one. Pinned in Task 4 and Task 6.
2. **Stray traffic at a shared endpoint.** One Stripe endpoint receives every enabled event
   type. `customer.subscription.*` sent to `parseCallback()` must be refused by name. So must
   `invoice.*` and `checkout.session.*` sent to `parseSubscriptionCallback()`, a one-off invoice
   with no subscription parent, and `product.*`/`charge.*`. This is the defect the 1.2.0 live
   run found in `StripeCheckout`. Pinned in Task 4 and Task 6.
3. **A signed payload that is well-formed JSON but nonsense**: a subscription with no items, two
   item currencies, a negative `unit_amount`, or an unrecognised status. It must raise
   `CheckoutException`, never `InvalidArgumentException` or `UnhandledMatchError`. Pinned in
   Task 6.
4. **Same-second sibling events.** Stripe's `event.created` has one-second resolution, and a
   pause emits `customer.subscription.paused` and `customer.subscription.updated` together.
   Redelivering either must return `false` from `SubscriptionLedger::record()`. Pinned in
   Task 10.
5. **A refund that cannot exist.** A refund against the `cs_` of a trial checkout
   (`no_payment_required`, nothing charged), or against an invoice that is not paid, must say
   why in plain words. It must not post a malformed refund to Stripe. Pinned in Task 5.

---

## 1. What this proposes

```php
// Inline plan: no Stripe catalogue needed. One adapter per plan's terms (1.4.0 §2.4).
$monthly = new StripeSubscription($key, $http, new BillingCycle(BillingInterval::Month),
    trialPeriod: new BillingCycle(BillingInterval::Day, 14), webhookSecret: $whsec);

// Catalogue plan: a recurring price_… the merchant maintains in the Stripe dashboard.
$annual = StripeSubscription::forCatalogPrice($key, $http, 'price_1Pro', webhookSecret: $whsec);

$session = $monthly->createCheckout($request);   // cs_… — the sub_ does not exist yet
```

| Method | How Stripe serves it |
| --- | --- |
| `createCheckout()` | `POST /checkout/sessions`, `mode=subscription` |
| `retrieveStatus()` | `GET /checkout/sessions/{cs_}`, or `GET /invoices/{in_}` for a renewal |
| `parseCallback()` | `checkout.session.*` and subscription `invoice.*` only |
| `refund()` | `POST /refunds` on the PaymentIntent, resolved from `pi_`, `cs_` or `in_` |
| `subscriptionReferenceOf()` | `subscription` on the session, or the invoice's subscription parent |
| `retrieveSubscription()` | `GET /subscriptions/{sub_}` |
| `parseSubscriptionCallback()` | `Stripe-Signature` verification, then `customer.subscription.*` only |
| `cancel()` | `POST /subscriptions/{id}` `cancel_at_period_end=true` · `DELETE /subscriptions/{id}` |
| `removeScheduledChange()` | `POST /subscriptions/{id}` clearing the pending cancellation |
| `pause()` / `resume()` | `POST /subscriptions/{id}/pause` · `/resume` (endive, D2) |
| `changePlan()` | `GET` then `POST /subscriptions/{id}` with the whole item set replaced |

How Stripe differs from Paddle, and what the adapter does about each difference:

- **The link is synchronous.** A completed subscription-mode session already carries
  `subscription`. Paddle's `transaction.subscription_id` arrives seconds late (1.4.0 §5.1).
  `subscriptionReferenceOf()` still returns `?string`, because an *open* session has none.
- **The subscription entity never names the session.** So `SubscriptionSnapshot::$transactionReference`
  is always null for Stripe. The ledger link comes from `subscriptionReferenceOf()` plus
  `SubscriptionLedger::linkTransaction()`, the second of 1.4.0 §2.3's two routes.
- **`$reference` is populated deliberately.** `client_reference_id` does not reach the
  subscription, so `createCheckout()` sends `subscription_data[metadata][reference]`. That is
  the same effect Paddle's `custom_data` inheritance has.
- **There is no `scheduled_change` block.** `ScheduledChange` is built from
  `cancel_at_period_end` and `cancel_at`, and only ever carries the action `Cancel` (D2).
- **Stripe takes idempotency keys.** So `changePlan()` can be made retry-safe, which Paddle's
  cannot (D11).

## 2. Decisions for Marshal

`CLAUDE.md` asks for both approaches to be laid out where two exist. Each decision below
carries a recommendation, and **the tasks in §4 are written against the recommendations.** If
you choose the alternative, the tasks named under that decision change. No other task does.

### D1. Which Stripe API version `StripeSubscription` pins

- **Recommended: pin `StripeSubscription` to `2026-09-30.endive`**, or the newest endive
  version at build time, recorded in the release notes. Pin it per class, so `StripeCheckout`
  stays on `2024-06-20`. Three reasons:
  - This is the earliest version with a real `/pause` endpoint (D2).
  - A new adapter should not be built on payload shapes Stripe retired 18 months ago.
  - The parsers have to read the basil-and-later shapes anyway, because webhooks arrive in the
    endpoint's version.
- *Alternative:* pin `2024-06-20` to match `StripeCheckout`. The old shapes are simpler, and
  `invoice.payment_intent` is still there. But the alternative to D2 is forced, and the adapter
  would ship on a version 2½ years old.
- Tasks affected: 2 (the version is read per class), 4 and 5 (where the PaymentIntent is read),
  and 8.

### D2. What "pause" means on Stripe (read Task 0's L1 answer first)

- **Recommended: use the real `POST /subscriptions/{id}/pause` and `/resume`.** After a pause,
  `status` is `paused`, so the adapter's `SubscriptionStatus::Paused` is Stripe's own word. The
  constraints, enforced locally by name, as `PaddleSubscription` does:
  - `pause()` accepts only `SubscriptionEffectiveFrom::Immediately`. Stripe's pause takes
    effect immediately; a scheduled pause needs Subscription Schedules, which are out of scope.
  - `pause()` refuses any `$resumeAt` or `$onResume`. The endpoint has no resume date.
  - `resume()` accepts only `Immediately`.
  - `ResumeBilling::StartNewBillingPeriod` maps to `billing_cycle_anchor[type]=now`.
    `ContinueExistingBillingPeriod` maps to `unchanged`.
  - `createCheckout()` sends `subscription_data[billing_mode][type]=flexible`, because Stripe
    pauses only flexible-mode subscriptions. (Live gate L1.)

  **The conflict with trials (D5), stated here so it is weighed now and not discovered later:**
  Stripe documents "no subscription-level trial (`trial_end` or `trial_period_days`)" as a pause
  restriction. Read literally, any subscription started with a trial could never be paused,
  even long after the trial ended. Task 0 settles which reading holds:

  - **If only an active trial blocks a pause,** the adapter documents that a trialing
    subscription cannot be paused, and lets Stripe's own refusal surface.
  - **If any past trial blocks it,** D2's recommendation costs trial merchants the ability to
    pause. Then `pause_collection` is the better choice for them, and this decision should be
    reopened before anything is built.

  **What a paused snapshot reports:** Stripe moves `current_period_end` to the moment of the
  pause. So `currentPeriodEndsAt` and `accessEndsAt()` are that moment, and `nextBilledAt` is
  null. That matches "service stops when billing stops", and the docblock says so.
- *Alternative:* `pause_collection[behavior]=void`, which works on any version. The status
  stays `active`, so the adapter would have to report `Paused` for something Stripe calls
  active. A scheduled resume would ride on `resumes_at`. That is a less honest mapping, and it
  is the only route if D1's alternative is taken.
- Tasks affected: 3 and 8.

### D3. Stripe's three extra statuses

Stripe has eight statuses and `SubscriptionStatus` has five. 1.4.0 §2.7 forbids defaulting.

- **Recommended: map them to existing cases and keep the raw status in `raw`:**
  - `incomplete` → `PastDue`. The first payment is outstanding and being collected.
  - `unpaid` → `PastDue`. Retries are exhausted but the subscription is not cancelled. The
    access decision stays the application's, per 1.4.0 §2.8.
  - `incomplete_expired` → `Cancelled`. Terminal: it never started.

  An unknown status still throws.
- *Alternative:* add `Incomplete` and `Unpaid` cases. That is more precise, but a new case
  breaks any exhaustive `match` an application has already written over `SubscriptionStatus`.
  That is semver-major in spirit and needs a `PastDue` docblock rewrite.
- Tasks affected: 6.

### D4. `ProrationBillingMode` and `PaymentFailureBehaviour` on Stripe

Stripe has three proration behaviours; Clarity's enum has five cases.

- **Recommended:**
  - `ProratedImmediately` → `always_invoice`
  - `ProratedNextBillingPeriod` → `create_prorations`
  - `DoNotBill` → `none`
  - `FullImmediately` and `FullNextBillingPeriod` are **refused by name**. Stripe has no
    equivalent that does not also reset the billing anchor, and a guessed equivalence would
    charge a real customer the wrong amount.
  - `PaymentFailureBehaviour::PreventChange` → `payment_behavior=error_if_incomplete`.
    `ApplyChange` → `allow_incomplete`.
- *Alternative:* map `FullNextBillingPeriod` to `none` as well. Both would then mean "the next
  renewal bills the new price", but that collapses two cases the enum keeps apart on purpose.
- Tasks affected: 9.

### D5. Trials

Stripe's Checkout takes `subscription_data[trial_period_days]`, an integer number of days.

- **Recommended:**
  - `BillingInterval::Day` maps directly.
  - `Week` becomes 7 × frequency days.
  - `Month` and `Year` are **refused at construction**. Converting them would silently turn
    "one month" into 30 days.
  - Unlike Paddle, a trial **is** allowed in catalogue mode, because a Stripe price carries no
    trial.
- *Alternative:* send `trial_end` computed from the moment the session is created. That drifts
  by however long the customer takes to pay, and Stripe refuses a `trial_end` less than 48
  hours out.
- Tasks affected: 3.

### D6. `changePlan()` with an inline `SubscriptionItem`

Stripe's subscription update takes `price_data` only with an existing `product` id; it does not
take `product_data`.

- **Recommended:** for each inline item, `POST /products` with `name` set to the item's
  description. Use an idempotency key derived from the call's key and the item index. Then send
  `price_data[product]`. Without this an "upgrade" button needs a Stripe catalogue, which 1.4.0
  §1.1 promised Paddle merchants they would never need. Live gate L6 confirms the call
  succeeds, and records whether Checkout's `product_data` already creates products the same
  way. If it does, a merchant's catalogue grows identically whether the plan was set at
  checkout or changed later.
- *Alternative:* refuse inline items on Stripe and require `SubscriptionItem::catalogPrice()`.
  That is simpler and creates no catalogue objects, but Stripe merchants would not get the
  parity Paddle merchants have.
- Tasks affected: 9.

### D7. Renewal transactions and `TransactionLedger`

**This affects both gateways.** `TransactionLedger::recordCallback()` throws when a reference
was never `open()`ed. A Stripe renewal is an `in_` with no checkout behind it, and a Paddle
renewal is a `txn_` with none either. The 1.4.0 end-to-end renewal test only passes because it
`open()`s the row itself.

- **Recommended: keep it out of this release, and say so in the release notes.**
  - `parseCallback()` still verifies and normalises subscription `invoice.*` events, so an
    application can act on a renewal.
  - `retrieveStatus()` and `refund()` accept an `in_`.
  - Recording an unsolicited transaction needs an amount, and `CallbackEvent` has none. The
    answer is a gateway-agnostic `TransactionLedger` method designed for both gateways at once,
    which is its own minor release.
- *Alternative:* design that method here. It is scope creep into a frozen ledger, and it would
  be done for one gateway when the gap is two gateways wide.
- Tasks affected: none if recommended; one new task if not.

**The cost is higher on Stripe than on Paddle, and must be documented, not discovered.** On
Paddle, the first payment's events name the `txn_` the application `open()`ed, so only renewals
miss the ledger. On Stripe, a subscription checkout is expected to raise `invoice.paid` for the
*first* invoice too (live gate L11), and that `in_` was never opened.

So the obvious handler, `$ledger->recordCallback($adapter->parseCallback($body, $headers))`,
would throw on **every signup**, not just on renewals. Task 11 documents the handler shape that
works: branch on the `CallbackEvent`'s reference prefix, and send `cs_` to the ledger while
handling `in_` in the application. `parseCallback()`'s docblock says the same in one sentence.

### D8. Dunning on a renewal invoice (the 1.4.0 §2.6 question, asked of Stripe)

- **Recommended:** map invoice states as follows.

  | Invoice state | `TransactionStatus` |
  | --- | --- |
  | `paid` | `Success` |
  | `open` | `Pending` (including after `invoice.payment_failed`) |
  | `draft` | `Pending` |
  | `uncollectible` | `Failed` |
  | `void` | `Cancelled` |

  `invoice.payment_failed` is dunning, not the end. `Failed` is terminal, and marking it there
  would lock a recovered renewal at `failed` — the exact 1.4.0 §2.6 defect.

  Session failures keep `StripeCheckout`'s mapping, where `async_payment_failed` is `Failed`.
  That is the first payment, attempted while the customer is present. Live gate L8 checks this
  holds when Stripe retries the first invoice.
- Tasks affected: 4.

### D9. A `SpeaksStripe` trait

- **Recommended: extract one.** The trait holds:
  - `verifySignature()` (security-critical, and the code a second copy would be most costly
    in), `send()`, `requireString()`, `referenceOf()`, `amountOf()`;
  - the refund-reason rule, as `refundParams()`;
  - the line-item synthesis for an empty `lineItems`.

  The gate is `StripeCheckoutTest.php` passing **byte-unmodified**, as with `SpeaksPaddle`.
  Per-class constants (`API_VERSION`, `GATEWAY`) are read through `self::`, exactly as
  `SpeaksPaddle` reads them.
- *Alternative:* duplicate the code into the new class. `CLAUDE.md`'s "minimal changes" argues
  for this. 1.4.0's precedent and the duplicated signature verifier argue against it.
- Tasks affected: 2.

### D10. Immediate cancellation does not refund on Stripe

- **Recommended:** `cancel(…, Immediately)` sends a bare `DELETE /subscriptions/{id}`, with no
  `prorate` and no `invoice_now`. Its docblock says plainly that Stripe, unlike Paddle, refunds
  nothing on an immediate cancel: a refund is a separate `refund()` call the merchant decides
  on. `prorate=true` only credits the customer's balance, and a credit balance on a cancelled
  customer is money nobody will see.
- Tasks affected: 7.

### D11. An idempotency key on `changePlan()`

- **Recommended:** add a trailing `?string $idempotencyKey = null` after `$timeoutSeconds`. The
  shared leading parameters stay positionally identical to `PaddleSubscription::changePlan()`.
  Pass the key on any call made with `ProratedImmediately`, because that is the call that
  charges.
- Tasks affected: 9.

### D12. 1.4.0 §3's facade question, answered

`ReleaseNotes_1.4.0.md` §3 deferred a `Services\Subscription` facade to "the release that
revisits it, if a second gateway's subscription surface turns out not to fit."

- **Recommended answer: it fits, so no facade.** Every `PaddleSubscription` method has a
  Stripe counterpart with the same signature. `changePlan()` gains one trailing optional
  parameter (D11). The differences are local refusals:
  - `pause()` and `resume()` are immediate only (D2);
  - two proration modes are refused (D4);
  - month-based trials are refused (D5).

  Those are gateway constraints, and a facade would have to express them as the same
  refusals. Task 1 records this in the release notes' "NOT in 1.11.0" list.

## 3. Live-gate questions

These are facts the design depends on that the documentation does not settle, each checked
against Stripe's docs on 2026-11-17.

- **L1, L2, L6 and L7 are answered by Task 0, before Marshal decides**, because D1, D2, D5 and
  D6 rest on them.
- **The rest are answered by the release-gate run in Task 12.**

None is assumed.

- **L1.** Does `subscription_data[billing_mode][type]=flexible` work on a Checkout Session at
  the pinned version? Can a Checkout-created subscription that had a trial be paused once the
  trial is over? Stripe documents "no subscription-level trial" as a pause restriction.
- **L2.** On a completed subscription-mode session, is `payment_intent` null and `invoice`
  populated?
- **L3.** Does `subscription_data[metadata][reference]` reach the subscription and its webhooks?
- **L4.** Does `/pause` then `/resume` (both anchors) behave as D2 maps it? What does a paused
  subscription report for `current_period_end`?
- **L5.** After `cancel_at_period_end=true`, is `cancel_at` populated? Which parameter actually
  withdraws the cancellation: `cancel_at_period_end=false`, or `cancel_at=''`?
- **L6.** Does `POST /products` plus `price_data[product]` work on a subscription update (D6)?
  Does Checkout's `product_data` create a Product?
- **L7.** On the pinned version, does an invoice webhook carry no `payments`, so that
  `GET /invoices/{id}?expand[]=payments` is the only route to `payment.payment_intent`?
- **L8.** On a test clock, does a renewal raise `invoice.paid`? Does a declined renewal raise
  `invoice.payment_failed` with the invoice still `open` and the subscription `past_due`? What
  does a declined first payment in an async method do to the session?
- **L9.** With `always_invoice` plus `error_if_incomplete` and a declining card, is the call
  refused with a `402` and the subscription left on its old plan?
- **L10.** Do real `stripe listen` signatures verify in both parsers? As a negative control,
  does the same delivery fail under the wrong secret?
- **L11.** Does a subscription checkout raise `invoice.paid` (or `invoice.payment_succeeded`)
  for the *first* invoice, with `billing_reason=subscription_create`? This settles D7's
  documented handler shape.

---

## 4. Tasks

File map:

| File | Responsibility |
| --- | --- |
| `resources/docs/ReleaseNotes_1.11.0.md` (create) | The frozen specification |
| `src/Services/CheckoutAdapters/SpeaksStripe.php` (create) | Wire format, signature, shared parsing |
| `src/Services/CheckoutAdapters/StripeCheckout.php` (modify) | Uses `SpeaksStripe`; no behaviour change |
| `src/Services/CheckoutAdapters/StripeSubscription.php` (create) | The adapter |
| `resources/tests/Services/CheckoutAdapters/StripeSubscriptionTest.php` (create) | Adapter tests, mocked |
| `resources/tests/Services/CheckoutAdapters/StripeSubscriptionLedgerTest.php` (create) | Round-trip through `SubscriptionLedger` on in-memory SQLite |
| `resources/docs/API_Contracts.md`, `RepoMap.md`, `CLAUDE.md`, `CHANGELOG.md` (modify) | Close-out |

Test file conventions: copy `StripeCheckoutTest.php` — namespace
`Monad\Clarity\Tests\Services\CheckoutAdapters`, `FakeHttpClient`,
`decodedLastRequestForm()`, a `signedHeaders()` helper using `HMAC::sign("$t.$body", $secret)`,
and `const WEBHOOK_SECRET = 'whsec_test_secret'`. Run every command from the worktree. Run
`composer install` there first: a symlinked `vendor/` silently tests the main checkout's
`src/`.

### Task 0: Pre-decision spike in Stripe test mode (L1, L2, L6, L7)

This is about an hour of `curl` against a test-mode account, sent with
`Stripe-Version: 2026-09-30.endive`. No code is written in the repo, and no credential is
committed.

- [ ] **Step 1:** Create a subscription-mode Checkout Session with
  `subscription_data[billing_mode][type]=flexible`, `trial_period_days=1`, and an inline
  `price_data` with `product_data`.
  - Complete it with card `4242…`.
  - Record the completed session's `payment_intent`, `invoice` and `subscription` fields (L2).
  - Record whether a Product was created (L6, part 2).
- [ ] **Step 2:** Pause that subscription while it is trialing, then end the trial on a test
  clock and pause it again. Record both answers, which decide between D2's two readings (L1).
  Resume it.
- [ ] **Step 3:** Run `POST /products`, then `POST /subscriptions/{id}` with
  `price_data[product]`. Record whether it is accepted (L6, part 1).
- [ ] **Step 4:** Capture an `invoice.paid` delivery with `stripe listen`. Record whether
  `payments` is present, and the path to the PaymentIntent from
  `GET /invoices/{id}?expand[]=payments` (L7).
- [ ] **Step 5:** Append the four answers to §3 of this document and commit. If L1 shows that
  any past trial blocks a pause, flag D2 for Marshal before Task 1.

### Task 1: Freeze the decisions into `ReleaseNotes_1.11.0.md`

**Files:** Create `resources/docs/ReleaseNotes_1.11.0.md`. Modify `CHANGELOG.md` (`[Unreleased]`).

- [ ] **Step 1:** Write the notes in the shape of `ReleaseNotes_1.4.0.md`:
  - §1 What ships (§1 of this plan, as decided)
  - §2 Resolved decisions (D1–D11 as Marshal chose them, each with its reasoning)
  - §3 Explicitly NOT in 1.11.0:
    - scheduled pauses or resumes (Subscription Schedules)
    - `TransactionLedger` recording of renewals (D7)
    - a `Services\Subscription` facade (D12)
    - `StripeConnectExpress`
    - §9.5 and §9.6.7
    - `Event::` constants
  - §4 Compatibility (minor; the list in Global Constraints)
  - §5 Verification (left for Task 12)

  Status: DRAFT.
- [ ] **Step 2:** Add an `[Unreleased]` CHANGELOG line pointing at the notes.
- [ ] **Step 3: Commit:**
  `git add resources/docs/ReleaseNotes_1.11.0.md CHANGELOG.md && git commit -m "Specify StripeSubscription for 1.11.0"`

### Task 2: Extract `SpeaksStripe` with zero behaviour change (D9)

**Files:** Create `src/Services/CheckoutAdapters/SpeaksStripe.php`. Modify
`src/Services/CheckoutAdapters/StripeCheckout.php`. Test: `StripeCheckoutTest.php`, **unmodified**.

**Interfaces — Produces** (trait members, all `private` unless noted). The using class supplies
`private const API_VERSION`, `private const GATEWAY`, `private readonly string $webhookSecret`
and `private readonly string $baseUri`:

- `verifySignature(string $rawBody, array $headers): void`. The error text must name the using
  class, via `static::class`'s short name, as `SpeaksPaddle` does at line 233. Each of
  `StripeCheckout`'s exact existing messages must survive.
- `send(string $method, string $path, array $params, int $timeoutSeconds, ?string $idempotencyKey = null): array`
- `requireString(array $payload, string $key): string`
- `referenceOf(mixed $value): ?string`
- `amountOf(array $object, ?Money $fallback = null): Money`. It reads
  `amount_total`/`currency`, falling back to `total`/`currency` for invoices.
- `refundParams(RefundRequest $request, string $paymentIntent): array`
- `lineItemParams(CheckoutRequest $request, ?array $recurring = null): array`. When
  `$recurring` is given, it adds `price_data[recurring]` to every line.
- Constants: `SIGNATURE_TOLERANCE_SECONDS = 300`, `REFUND_REASONS`, `DEFAULT_BASE_URI`.

Steps:

- [ ] **Step 1:** Run `vendor/bin/phpunit resources/tests/Services/CheckoutAdapters/StripeCheckoutTest.php`.
  Expected: green. Record the count.
- [ ] **Step 2:** Move the members into the trait. `StripeCheckout` keeps `createCheckout`,
  `retrieveStatus`, `parseCallback`, `refund`, `resolvePaymentIntent`, `mapSessionStatus`,
  `failureReasonOf`, and its `API_VERSION`/`GATEWAY` constants.
- [ ] **Step 3:** Run the same command, then `git diff --exit-code resources/tests/`. Expected:
  the same count, green, and an empty test diff.
- [ ] **Step 4:** Run `vendor/bin/phpunit`. Expected: the whole suite is green.
- [ ] **Step 5: Commit:** `Extract SpeaksStripe from StripeCheckout; its tests pass unmodified`

### Task 3: `StripeSubscription` construction and `createCheckout()` (D1, D2, D5)

**Files:** Create `src/Services/CheckoutAdapters/StripeSubscription.php` and
`resources/tests/Services/CheckoutAdapters/StripeSubscriptionTest.php`.

**Interfaces — Produces:**

```php
final class StripeSubscription extends Checkout {
    use SpeaksStripe;
    private const API_VERSION = '2026-09-30.endive';   // D1; newest endive at build time
    private const GATEWAY = 'stripe_subscription';
    public function __construct(string $apiKey, HttpClient $httpClient,
        private readonly ?BillingCycle $billingCycle,
        private readonly ?BillingCycle $trialPeriod = null,
        private readonly string $webhookSecret = '',
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
        private readonly ?string $catalogPriceId = null);
    public static function forCatalogPrice(string $apiKey, HttpClient $httpClient, string $catalogPriceId,
        ?BillingCycle $trialPeriod = null, string $webhookSecret = '', string $baseUri = self::DEFAULT_BASE_URI): self;
    public function createCheckout(CheckoutRequest $request): CheckoutSession;
}
```

Wire format for `createCheckout()` (form fields):

- Always: `mode=subscription`, `success_url`, `cancel_url`, `client_reference_id`,
  `subscription_data[metadata][reference]` = `$request->reference`, and
  `subscription_data[billing_mode][type]=flexible`.
- `customer_email` and `metadata` only when given.
- Inline mode: `line_items` from `lineItemParams($request, ['interval' => …, 'interval_count' => …])`.
- Catalogue mode: `line_items[0][price]=price_…`, `line_items[0][quantity]=1`.
- With a trial: `subscription_data[trial_period_days]`.
- The `Idempotency-Key` header is `$request->idempotencyKey()`.
- Never `payment_intent_data`.

The returned `CheckoutSession` has gateway `stripe_subscription`, `gatewayReference` `cs_…`,
`redirectUrl` from `url`, and `paymentReference` **null**. `amount` comes from `amount_total`.
In catalogue mode it never falls back to `$request->amount`, which is inert there (1.7.0 §2.3).

- [ ] **Step 1: Write the failing tests:**
  - `testCreateCheckoutSendsASubscriptionModeSessionWithARecurringInlinePrice`: assert
    `mode=subscription`, `line_items[0][price_data][recurring][interval]=month`, `interval_count=1`,
    currency lower-cased, `subscription_data[metadata][reference]=order-1`, and
    `billing_mode[type]=flexible`. Assert no `payment_intent_data` key.
  - `testCreateCheckoutReturnsTheSessionNotASubscription`: `gatewayReference` is `cs_test_123`
    and `paymentReference` is null.
  - `testCreateCheckoutSendsTheMerchantReferenceAsAnIdempotencyKeyByDefault`
  - `testCatalogueModeSendsThePriceIdAndReadsTheAmountBackOffTheSession`: request with
    `Money(0,'USD')`, `amount_total: 2900` → `amount->minorUnits === 2900`.
  - `testCatalogueModeThrowsWhenTheSessionCarriesNoAmount`
  - `testATrialInDaysIsSentAsTrialPeriodDays`: `Day,14` → `14`.
  - `testATrialInWeeksIsConvertedToDays`: `Week,2` → `14`.
  - `testATrialInMonthsIsRefusedAtConstruction` (data provider over `Month` and `Year`):
    `CheckoutException`, and the message contains `trial_period_days`.
  - `testCatalogueModeAcceptsATrial`, which differs from Paddle by design.
  - `testConstructionRefusesNeitherOrBothPlanSources`
  - `testConstructionRefusesACatalogueIdThatIsNotAPrice`: `prod_x` refused, and the message
    names `price_`. It follows `SpeaksPaddle::assertCatalogPriceId()`, which explains a product
    id specifically.
- [ ] **Step 2:** `vendor/bin/phpunit --filter StripeSubscriptionTest`. Expected: FAIL (class
  not found).
- [ ] **Step 3:** Implement. A private `trialDays(BillingCycle): int` holds the D5 rule. The
  constructor guards mirror `PaddleSubscription::assertOnePlanSource()`, without the trial
  clause.
- [ ] **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `Add StripeSubscription::createCheckout()`

### Task 4: The transaction side — `retrieveStatus()`, `parseCallback()`, `subscriptionReferenceOf()` (D8)

**Files:** Modify `StripeSubscription.php` and `StripeSubscriptionTest.php`.

**Interfaces — Produces:**

- `retrieveStatus(string $reference, int $timeoutSeconds = 30): TransactionSnapshot`
  - `cs_…` → `GET /checkout/sessions/{id}?expand[]=invoice.payments`, so `paymentReference`
    is filled in from the first invoice
  - `in_…` → `GET /invoices/{id}?expand[]=payments`
  - Any other prefix is refused by name.
- `parseCallback(string $rawBody, array $headers): CallbackEvent`
  - Accepts `object === 'checkout.session'`, and `object === 'invoice'` when the invoice
    belongs to a subscription.
- `subscriptionReferenceOf(CallbackEvent|TransactionSnapshot $transaction): ?string`
  - Session: `subscription`.
  - Invoice: `parent.subscription_details.subscription`, then legacy `subscription`.
  - Either may be a bare id or an expanded object (`referenceOf()`).
- Private `invoiceSubscriptionOf(array $invoice): ?string` (both shapes), `mapInvoiceStatus(array $invoice): TransactionStatus`
  (the D8 table), and `paymentIntentOfInvoice(array $invoice): ?string`, which reads
  `payments.data[*].payment.payment_intent` where `payment.type === 'payment_intent'`, then
  legacy `payment_intent`.

Session status: `payment_status` `paid` or `no_payment_required` → `Success`; session `expired`
→ `Cancelled`; otherwise `Pending`. Events: `async_payment_succeeded` → `Success`,
`async_payment_failed` → `Failed`, `expired` → `Cancelled`.

- [ ] **Step 1: Write the failing tests:**
  - `testRetrieveStatusOfACompletedSessionIsSuccessAndCarriesTheSubscription`:
    `subscriptionReferenceOf($snapshot) === 'sub_test_1'`.
  - `testRetrieveStatusOfATrialSessionWithNoPaymentRequiredIsSuccess`
  - `testRetrieveStatusOfAnInvoiceExpandsPaymentsAndReadsThePaymentIntent`: the request URI
    contains `expand%5B0%5D=payments`, and `paymentReference === 'pi_test_9'`.
  - `testInvoiceStatesMapOntoTransactionStatus` (data provider over the D8 table).
  - `testAnOpenInvoiceAfterAFailedAttemptIsPendingNotFailed`: `status=open`, `attempt_count=2`
    → `Pending`. The docblock cites 1.4.0 §2.6.
  - `testRetrieveStatusRefusesAReferenceThatIsNeitherASessionNorAnInvoice` (`sub_x`, `pi_x`)
  - `testParseCallbackAcceptsInvoicePaidForASubscription`: `gatewayReference === 'in_…'`,
    `status === Success`.
  - `testParseCallbackMapsInvoicePaymentFailedToPending`
  - `testSubscriptionReferenceOfReadsTheBasilInvoiceParent` and
    `testSubscriptionReferenceOfReadsTheLegacyTopLevelInvoiceSubscription` — Review Focus 1.
  - `testParseCallbackRefusesAOneOffInvoiceWithNoSubscription`: the message says the invoice
    belongs to no subscription. Review Focus 2.
  - `testParseCallbackRefusesNonTransactionObjects` (data provider: `subscription`, `product`,
    `charge`). The message names the object type and points `subscription` objects at
    `parseSubscriptionCallback()`. Review Focus 2.
  - Tier 1 adversarial set for this parser, with the same names as in `StripeCheckoutTest`:
    - tampered payload
    - wrong secret
    - timestamp outside tolerance
    - missing header
    - malformed header
    - no configured secret
    - case-insensitive header
    - rotation with two `v1`
  - `testParseCallbackVerifiesTheSignatureBeforeTheObjectGuard`: a `product.created` signed
    with the wrong secret is refused for the signature.
- [ ] **Step 2:** Run `--filter StripeSubscriptionTest`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Re-run. Expected: PASS.
- [ ] **Step 5: Commit:** `StripeSubscription reads sessions and renewal invoices`

### Task 5: `refund()` resolution

**Files:** Modify `StripeSubscription.php` and `StripeSubscriptionTest.php`.

**Interfaces — Produces:** `refund(RefundRequest $request): RefundResult`. The reference is
resolved to a PaymentIntent:

- `pi_…` → used as is.
- `in_…` → `GET /invoices/{id}?expand[]=payments` → `paymentIntentOfInvoice()`.
- `cs_…` → `GET /checkout/sessions/{id}?expand[]=invoice.payments` → the same reader on the
  expanded invoice.

Then `POST /refunds` with `refundParams()` and the request's idempotency key.

- [ ] **Step 1: Write the failing tests:**
  - `testRefundPostsAgainstAPaymentIntentDirectly`: one request.
  - `testRefundResolvesARenewalInvoiceToItsPaymentIntent`: two requests; the second form has
    `payment_intent=pi_test_9`.
  - `testRefundResolvesASubscriptionSessionThroughItsInvoice`
  - `testRefundExplainsItselfForATrialSessionThatChargedNothing`: `no_payment_required` and an
    invoice with no payments. The `CheckoutException` names the session and says nothing was
    charged. Exactly one request is sent. Review Focus 5.
  - `testRefundExplainsItselfForAnInvoiceThatIsNotPaid`. Review Focus 5.
  - `testRefundKeepsAFreeTextReasonAsMetadataRatherThanFailing`, which pins the shared
    `refundParams()`.
- [ ] **Steps 2–4:** fail, implement, pass, as in Task 3.
- [ ] **Step 5: Commit:** `StripeSubscription refunds sessions, invoices and payment intents`

### Task 6: The subscription read side — `retrieveSubscription()`, `parseSubscriptionCallback()` (D3)

**Files:** Modify `StripeSubscription.php` and `StripeSubscriptionTest.php`.

**Interfaces — Produces:**

- `retrieveSubscription(string $subscriptionReference, int $timeoutSeconds = 30): SubscriptionSnapshot`
  — `GET /subscriptions/{id}`. A reference not starting with `sub_` is refused locally.
- `parseSubscriptionCallback(string $rawBody, array $headers): SubscriptionEvent`
  - Verify the signature first.
  - Require `object === 'subscription'` and `type` starting `customer.subscription.`.
  - `occurredAt` = `event.created` read as a UTC `DateTimeImmutable` from unix seconds.
- Private `snapshotFrom(array $subscription): SubscriptionSnapshot`, which builds:
  - `status` via `mapSubscriptionStatus()`:
    - `active`, `trialing`, `past_due`, `paused` → the same-named case
    - `canceled` → `Cancelled`
    - `incomplete`, `unpaid` → `PastDue`
    - `incomplete_expired` → `Cancelled`
    - anything else → `CheckoutException` naming the status (1.4.0 §2.7)
  - `scheduledChange`: `ScheduledChange(Cancel, cancel_at ?? currentPeriodEnd)` when
    `cancel_at_period_end` is true or `cancel_at` is set and the subscription is not yet
    cancelled. Otherwise null.
  - `reference` = `metadata.reference`; `customerReference` = `customer` (via `referenceOf()`);
    `transactionReference` = null.
  - `recurringAmount`: Σ `items.data[*].price.unit_amount × quantity`. A single currency is
    required. A negative amount, a non-ISO currency, mixed currencies, or zero items is a
    `CheckoutException`, catching `Money`'s `InvalidArgumentException`.
  - `billingCycle` from `items.data[0].price.recurring` {`interval`, `interval_count`}.
  - `currentPeriodStartsAt`/`EndsAt` from `items.data[0].current_period_*`, then the top-level
    legacy fields.
  - `nextBilledAt` = `currentPeriodEndsAt` when the status is `Active` or `Trialing` and
    nothing is scheduled; otherwise null.
  - `raw` = the object.

Steps:

- [ ] **Step 1: Write the failing tests:**
  - `testRetrieveSubscriptionNormalisesAnActiveSubscription`: every field above asserted,
    including `reference === 'order-1'` and `recurringAmount` 2900 USD from two items
    (1900 + 2 × 500).
  - `testSubscriptionStatusesMap` (data provider over all eight Stripe statuses).
  - `testAnUnrecognisedStatusIsRefusedByName`: the message contains the status.
  - `testCancelAtPeriodEndIsAScheduledCancellationOnAnActiveSubscription`: `status === Active`,
    `isCancelling()`, `accessEndsAt()` = the period end, and `nextBilledAt` null.
  - `testPeriodsAreReadFromItemsOnBasilAndLaterPayloads` and
    `testPeriodsAreReadFromTheSubscriptionOnLegacyPayloads` — Review Focus 1.
  - `testAPayloadWithMixedItemCurrenciesIsAGatewayError`,
    `testAPayloadWithANegativeUnitAmountIsAGatewayError`, and
    `testAPayloadWithNoItemsIsAGatewayError`. Each asserts `CheckoutException` specifically.
    Review Focus 3.
  - `testParseSubscriptionCallbackReturnsTheEventWithAUtcOccurredAt`
  - `testParseSubscriptionCallbackRefusesInvoiceAndSessionEvents` (data provider). The message
    points at `parseCallback()`. Review Focus 2.
  - `testParseSubscriptionCallbackVerifiesTheSignatureBeforeTheEventGuard`
  - Tier 1 adversarial set (as in Task 4), on this parser.
  - `testRetrieveSubscriptionRefusesAReferenceThatIsNotASubscription` (`cs_x`)
- [ ] **Steps 2–4:** fail, implement, pass.
- [ ] **Step 5: Commit:** `StripeSubscription reads and verifies subscriptions`

### Task 7: `cancel()` and `removeScheduledChange()` (D10)

**Interfaces — Produces:**

- `cancel(string $subscriptionReference, SubscriptionEffectiveFrom $effectiveFrom, int $timeoutSeconds = 30): SubscriptionSnapshot`
  - `NextBillingPeriod` → `POST /subscriptions/{id}` with `cancel_at_period_end=true`.
  - `Immediately` → `DELETE /subscriptions/{id}` with an empty body.
- `removeScheduledChange(string $subscriptionReference, int $timeoutSeconds = 30): SubscriptionSnapshot`
  — `POST /subscriptions/{id}` with `cancel_at_period_end=false`. Live gate L5 may require
  `cancel_at=''` as well; if so, both are sent, and the release notes record which was needed.
- `send()` must support `DELETE`. Add it to the trait in this task. Do not change its
  `GET`/`POST` behaviour.

Steps:

- [ ] **Step 1: Write the failing tests:**
  - `testCancelForTheNextBillingPeriodSetsCancelAtPeriodEndAndStaysActive`
  - `testCancelImmediatelyDeletesTheSubscriptionAndSendsNoProration`: the method is `DELETE`,
    and the form has no `prorate` or `invoice_now`.
  - `testRemoveScheduledChangeWithdrawsAPendingCancellation`: the returned snapshot has
    `isCancelling() === false`.
  - `testCancelRefusesAReferenceThatIsNotASubscription`
- [ ] **Steps 2–4:** fail, implement, pass. The `cancel()` docblock states D10 in plain words.
- [ ] **Step 5: Commit:** `StripeSubscription cancels and withdraws cancellations`

### Task 8: `pause()` and `resume()` (D2)

**Interfaces — Produces** (signatures identical to `PaddleSubscription`'s):

- `pause(string $subscriptionReference, SubscriptionEffectiveFrom $effectiveFrom, ?DateTimeInterface $resumeAt = null, ?ResumeBilling $onResume = null, int $timeoutSeconds = 30): SubscriptionSnapshot`
  — `POST /subscriptions/{id}/pause` with `type=subscription`.
- `resume(string $subscriptionReference, SubscriptionEffectiveFrom $effectiveFrom, ResumeBilling $onResume = ResumeBilling::StartNewBillingPeriod, int $timeoutSeconds = 30): SubscriptionSnapshot`
  — `POST /subscriptions/{id}/resume` with `billing_cycle_anchor[type]` = `now` or `unchanged`.

Steps:

- [ ] **Step 1: Write the failing tests:**
  - `testPauseImmediatelyPostsToThePauseEndpointAndReportsPaused`
  - `testPauseRefusesNextBillingPeriod`: the message says Stripe pauses take effect immediately
    and that a scheduled pause needs a subscription schedule. No request is sent.
  - `testPauseRefusesAResumeDate`: the message says Stripe's pause carries no resume date and
    `resume()` should be called when the time comes. No request is sent.
  - `testResumeMapsResumeBillingOntoTheBillingCycleAnchor` (data provider over both cases).
  - `testResumeRefusesNextBillingPeriod`
  - `testPauseAndResumeSendTheAdaptersPinnedApiVersion`: the `Stripe-Version` header is
    `2026-09-30.endive`.
- [ ] **Steps 2–4:** fail, implement, pass.
- [ ] **Step 5: Commit:** `StripeSubscription pauses and resumes`

### Task 9: `changePlan()` (D4, D6, D11)

**Interfaces — Produces:**

```php
public function changePlan(string $subscriptionReference, array $items,
    ProrationBillingMode $prorationBillingMode,
    PaymentFailureBehaviour $onPaymentFailure = PaymentFailureBehaviour::PreventChange,
    int $timeoutSeconds = 30, ?string $idempotencyKey = null): SubscriptionSnapshot;
```

The algorithm, which the signature and tests alone do not determine:

1. Validate locally, before any request:
   - `$items` must be non-empty.
   - Every element must be a `SubscriptionItem`.
   - The item billing cycles must all agree, as `PaddleSubscription::assertChangeableItems()`
     requires.
   - The proration mode must not be `Full*` (D4).
2. `GET /subscriptions/{id}`, and collect every existing `items.data[*].id`.
3. For each inline item, `POST /products` with `name` set to the line item's description and
   `Idempotency-Key` = `"{$idempotencyKey}:product:{$index}"` when a key was given.
4. `POST /subscriptions/{id}` with:
   - first `items[n][id]=si_…` and `items[n][deleted]=true` for every existing item;
   - then each new item, as either `items[n][price]=price_…` with its quantity, or
     `items[n][price_data][currency|unit_amount|product|recurring][interval|interval_count]`
     with its quantity;
   - `proration_behavior` and `payment_behavior` from D4;
   - the `Idempotency-Key` header when one was given.

Steps:

- [ ] **Step 1: Write the failing tests:**
  - `testChangePlanReplacesTheWholeItemSet`: the existing `si_a` and `si_b` are both
    `deleted=true`, and the new `price_pro` is added.
  - `testChangePlanCreatesAProductForAnInlineItemThenPricesAgainstIt`: three requests in
    order (GET, `POST /products`, `POST /subscriptions/…`), and
    `price_data[product]=prod_new`.
  - `testChangePlanMapsProrationModes` (data provider over the three allowed modes).
  - `testChangePlanRefusesTheFullProrationModesBeforeSendingAnything` (data provider).
    `requestCount() === 0`.
  - `testChangePlanMapsPaymentFailureBehaviour` (data provider).
  - `testChangePlanSendsTheIdempotencyKeyAndDerivesOnePerProduct`
  - `testChangePlanRefusesAnEmptyOrMixedIntervalItemSet`
- [ ] **Steps 2–4:** fail, implement, pass. The docblock carries the "replaces, never adds"
  warning from `PaddleSubscription`, plus D11's retry guidance.
- [ ] **Step 5: Commit:** `StripeSubscription changes plans`

### Task 10: Round-trip through `SubscriptionLedger`

**Files:** Create `resources/tests/Services/CheckoutAdapters/StripeSubscriptionLedgerTest.php`.
Build the in-memory SQLite schema with
`Schema::createTable(SubscriptionLedger::SUBSCRIPTIONS_TABLE, CheckoutInstall::subscriptionsBlueprint())`,
exactly as `SubscriptionLedgerTest` does, so that the shipped DDL is what gets tested.

- [ ] **Step 1: Write the tests:**
  - `testASubscriptionCreatedEventIsRecordedWithItsReference`: the row's `gateway` is
    `stripe_subscription` and its `reference` is `order-1`.
  - `testTheSessionIsLinkedThroughSubscriptionReferenceOf`: record the subscription, then call
    `linkTransaction($adapter->subscriptionReferenceOf($sessionEvent), 'cs_test_123')`, which
    returns `true`. Then `findByTransactionReference('cs_test_123')` finds it.
  - `testSameSecondPausedAndUpdatedSiblingsAreRecognisedOnRedelivery`: two events with one
    `created`, both applied, then both redelivered. Both redeliveries return `false`. Review
    Focus 4.
  - `testAnOlderUpdatedEventDoesNotOverwriteANewerCancellation`
- [ ] **Step 2:** Run `--filter StripeSubscriptionLedgerTest`. Expected: PASS without production
  changes. If anything fails, it is a real adapter defect: fix it in `StripeSubscription`, never
  in the ledger.
- [ ] **Step 3: Commit:** `Prove StripeSubscription round-trips through SubscriptionLedger`

### Task 11: Close-out documentation

**Files:** Modify:

- `resources/docs/API_Contracts.md`: a "Subscriptions — Stripe (1.11.0)" block under
  Subscriptions, with signatures, the local refusals (D2, D4, D5), and the
  `cs_` → `sub_` bridge. It also shows the webhook handler shape D7 requires: `cs_` events go
  to `TransactionLedger`, and `in_` events are the application's to handle.
- `RepoMap.md`: `SpeaksStripe.php` and `StripeSubscription.php` with a `1.11.0` comment.
- `CLAUDE.md`:
  - the Checkout hard-rule paragraph, adding `StripeSubscription` and `SpeaksStripe`;
  - a `ReleaseNotes_1.11.0.md` entry in the source-of-truth list, naming its three most
    load-bearing decisions.
- `CHANGELOG.md`: an `[Unreleased]` `### Added` section in the existing voice.
- Docblocks only, no code:
  - `SubscriptionStatus`, `SubscriptionEffectiveFrom` and `ProrationBillingMode`, where they
    speak only of Paddle, gain the Stripe mapping in one sentence each.
  - `SubscriptionSnapshot::$transactionReference` gains "always null on Stripe".

No change to `CrossRepoContracts.md`: no table or command moved, so its §10 mirror sync is not
triggered.

- [ ] **Step 1:** Make the edits.
- [ ] **Step 2:** `vendor/bin/phpunit`. Expected: the full suite is green on 8.2. CI covers
  8.3.
- [ ] **Step 3:** `git status --porcelain src/`. Expected: only `SpeaksStripe.php`,
  `StripeSubscription.php` and `StripeCheckout.php` changed, plus the docblock-only enum edits.
- [ ] **Step 4: Commit:** `Document StripeSubscription`

### Task 12: Live Stripe test-mode gate (not automated)

Run from a scratch script **outside the repository**, with keys taken from the environment. No
credential is committed, as in 1.6.0 §7.7. The script drives the adapter itself, never a script
written to agree with it. Use `stripe listen --forward-to` capturing **raw bytes** for
deliveries, and a **test clock** for renewals.

- [ ] **Step 1:** Answer L3–L5 and L8–L11 (§3), one line of evidence each. Re-confirm
  L1, L2, L6 and L7 against the built adapter.
- [ ] **Step 2:** For any answer that contradicts the plan: write the failing mocked test first,
  fix the code, and record the defect in `ReleaseNotes_1.11.0.md` §5 in 1.4.0's
  "found live, which no mock could" voice.
- [ ] **Step 3:** Record in §5 what was established, as 1.4.0 §5.1–§5.2 does. Then run the
  `ReleasePolicy.md` checklist: CHANGELOG cut, `composer.json` branch alias, the suite, health
  on a fresh `create-project`, dist export-ignore, tag plus GitHub Release, and website docs.

## 5. Acceptance gate

1. Every decision in §2 is resolved by Marshal and frozen in `ReleaseNotes_1.11.0.md`.
2. `StripeCheckoutTest.php` is byte-unmodified and green after Task 2.
3. Full suite green on PHP 8.2 and 8.3, with every Review Focus test present and named as above.
4. `Services\Checkout` and every existing `final readonly` value object have an unchanged public
   surface (`git diff v1.10.0 -- src/Services/Checkout.php src/Services/Checkout/` shows only
   docblock lines).
5. L1–L11 answered live (L1, L2, L6 and L7 before the decisions) and recorded in §5, with any
   defect fixed test-first.
6. CHANGELOG, `API_Contracts.md`, `RepoMap.md` and `CLAUDE.md` updated, and nothing stray in
   `src/`.
