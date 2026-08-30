# Monad Clarity 1.4.0 Release Notes

**Status:** FROZEN — canonical specification for the 1.4.0 release.
This document is the source of truth for WHAT ships in 1.4.0. It does not restate 1.0.0, 1.2.0
or 1.3.0; those remain frozen for what they specified.

> **Scope note.** 1.2.0 shipped the Checkout facade, the transaction ledger, and the first
> adapter. 1.3.0 added a second and proved the design carried it. 1.4.0 adds **recurring
> billing** — the first thing Checkout has been asked to do that a transaction cannot express
> on its own. The facade is untouched: no method signature changed, no existing value object
> gained or lost a property, and no shipped table definition moved.

## 1. What ships in 1.4.0

### 1.1 Paddle subscription adapter

`Monad\Clarity\Services\CheckoutAdapters\PaddleSubscription` — subscriptions started through
Paddle's Transactions API, then read and changed through `/subscriptions`.

It sits beside `PaddleCheckout` exactly as `ReleaseNotes_1.0.0.md` §9.4 reserves
`StripeConnectExpress` beside `StripeCheckout`: one gateway, two genuinely different flows,
one class each.

| Method | How Paddle serves it |
| --- | --- |
| `createCheckout()` | `POST /transactions` with `billing_cycle` on the inline price |
| `retrieveStatus()` | `GET /transactions/{id}` — a renewal is still a transaction |
| `parseCallback()` | `transaction.*` events only, unchanged |
| `refund()` | `POST /adjustments` — a subscription's refunds are transaction refunds |
| `retrieveSubscription()` | `GET /subscriptions/{id}` |
| `parseSubscriptionCallback()` | `Paddle-Signature` verification, then `subscription.*` only |
| `cancel()` | `POST /subscriptions/{id}/cancel` |
| `pause()` / `resume()` | `POST /subscriptions/{id}/pause` · `/resume` |
| `changePlan()` | `PATCH /subscriptions/{id}` |
| `subscriptionReferenceOf()` | reads `subscription_id` off a settled transaction |

**No new Composer dependency.** Signature verification continues to use `Utils\HMAC` and
`Utils\ConstantTime`, and `paddle/paddle-php-sdk` is still deliberately not pulled in.

Items go on the wire as **non-catalog** inline prices, as in 1.3.0 — now carrying a
`billing_cycle`, and optionally a `trial_period`. A Monad application therefore never has to
seed a Paddle catalogue to sell a subscription, and `changePlan()` accepts inline prices too,
so it does not have to seed one to offer an upgrade button either.

### 1.2 Subscription value objects

New in `Monad\Clarity\Services\Checkout\*`, all gateway-agnostic so a future
`StripeSubscription` reuses them: `SubscriptionStatus`, `SubscriptionSnapshot`,
`SubscriptionEvent`, `ScheduledChange`, `ScheduledChangeAction`, `BillingCycle`,
`BillingInterval`, `SubscriptionItem`, `ProrationBillingMode`, `PaymentFailureBehaviour`,
`SubscriptionEffectiveFrom`, `ResumeBilling`.

### 1.3 `Checkout\SubscriptionLedger` and a fourth table

`checkout_subscriptions`, created by the existing `php mitosis checkout:install`. A separate
ledger class rather than more methods on `TransactionLedger` — see §2.5.

### 1.4 `CheckoutAdapters\SpeaksPaddle`

The machinery both Paddle adapters share, extracted rather than duplicated: the signed
callback scheme, the `data` envelope, cursor pagination, inline item building, the two checkout
modes, and the three transaction-scoped operations that read the same endpoints either way —
including the over-refund guard, which is the one piece of this codebase a second divergent
copy would be most costly in. `PaddleCheckout` fell from 763 lines to 147 and **its 51 tests
passed unmodified**, which is the evidence that the extraction changed no behaviour.

## 2. Resolved specification decisions

Recorded here because `ReleaseNotes_1.0.0.md` is frozen — the route 1.2.0 and 1.3.0 took.

### 2.1 Subscriptions are in scope for Checkout

`ReleaseNotes_1.3.0.md` §3 listed subscriptions as explicitly not in that release, on the
grounds that "§9 never asks for one". That was a scope note about a shipped release, not a
prohibition — and §9 never asked for Paddle either.

**Decided on the same reasoning §2.1 of the 1.3.0 notes used for the gateway roster:** §9.2
specifies an abstraction layer for "various payment gateways" and fixes the *shape* — one class
per flow, its own namespace under `Services\CheckoutAdapters`, built end to end or not at all —
not the feature set. Recurring billing is a thing a payment gateway does, and an abstraction
layer that could not express it would be describing gateways as they were rather than as they
are.

### 2.2 The facade gains no fifth method

`ReleaseNotes_1.3.0.md` §2.4 settled that adding an abstract method to `Services\Checkout`
breaks every downstream adapter and is semver-major. That holds, and this release is built
around it: all seven subscription operations are public methods on the adapter, not contract
methods on the facade.

That is the honest place for them. `Checkout` is the contract for what *every* gateway does;
most of the reserved eight do not sell subscriptions at all, and an abstract `cancel()` they
would each have to throw from is worse than no `cancel()`. An application that wants
subscriptions types the adapter class, which is exactly what §9.4's one-class-per-flow shape
implies.

### 2.3 A subscription is born from a transaction, so there are two identifiers

There is no `POST /subscriptions`. Paddle creates the subscription when a transaction whose
price carries a `billing_cycle` is actually paid. So `createCheckout()` returns a `txn_`, and
the `sub_` every subscription operation acts on does not exist yet.

The link is published on both sides, in different places: the transaction object carries
`subscription_id`, and the `subscription.created` delivery carries `transaction_id`. Both
routes are read, because either delivery can be dropped:

- `subscriptionReferenceOf(CallbackEvent|TransactionSnapshot)` reads it off a settled
  transaction — using `raw`, which is what that field is for, so **no `final readonly` value
  object changed**.
- `parseSubscriptionCallback()` returns a snapshot carrying both.
- `SubscriptionLedger::linkTransaction()` fills in whichever arrived second, and never
  overwrites a link already recorded.

`CheckoutSession::$paymentReference` is deliberately **not** overloaded to carry a `sub_`. Its
documented meaning is the id refunds act on; a subscription is not a payment, and putting one
there would corrupt refund reconciliation for every gateway.

### 2.4 The billing cycle is adapter configuration, not request data

`createCheckout()`'s signature is fixed by the facade and `CheckoutRequest` is `final readonly`
and semver-frozen, so the cycle has one place left to live: the constructor. One adapter
instance therefore means one plan's billing terms, and a merchant with monthly and annual tiers
constructs two — the same shape as constructing one adapter per gateway.

Trials are included rather than deferred. `SubscriptionStatus::Trialing` has to be interpreted
whether or not this adapter can start a trial, and shipping a status it could report but never
produce would be a partial implementation. Paddle's `trial_period` has the identical
`{interval, frequency}` shape, so `BillingCycle` serves both and no second class appears.

### 2.5 `checkout_subscriptions` is mutable, and its idempotency is weaker than §2.3 of 1.2.0

A subscription is a long-lived arrangement whose current state is what applications query, not
an event to append. So the table is mutable, carries an ordinary UUIDv4 key, and lives behind
its own `SubscriptionLedger` rather than inside `TransactionLedger` — whose two stated
invariants (status rows are insert-only; every repeatable write is keyed on a unique index) are
**both false here**. Folding subscriptions in would have turned a class whose value is two
crisp invariants into one whose invariants have exceptions.

**The consequence must be stated plainly rather than implied away: this is a weaker guarantee
than 1.2.0 §2.3's.** A mutable row has nowhere to hang a unique index on event id, so what
protects it is a monotonic guard: anything older than the state already stored is refused, and
an exact redelivery is recognised against `last_event_ids` — **the set of ids applied at the
newest stored moment**, not a single id. Three points follow:

- The set is not belt and braces, and the live run is why it exists. Paddle emits several
  distinct events within one second — observed here, a `subscription.resumed` and a
  `subscription.updated` **126 microseconds apart** — and `DATETIME`'s second precision
  collapses them together. With only the newest id remembered, redelivering one of its siblings
  was applied again *and reported as a real change*, which would double-fire whatever an
  application does on a `true` return. Keeping the ids for the current second makes redelivery
  recognition exact. A newer second resets the set, so it cannot grow without bound.
- What remains weaker is **ordering, not idempotency**: inside a single second the events cannot
  be sequenced, so the last writer wins. Refusing same-second events instead would be worse —
  they are frequently real, distinct state. Sub-second ordering would need sub-second timestamps,
  which `Architecture.md` §9 rules out for built-in tables.
- The unique index on `gateway_reference` is still load-bearing, but for **concurrency** rather
  than redelivery: two simultaneous first-deliveries both find no row, and the index stops the
  loser inserting a duplicate. That collision is caught and applied as an update.

Timestamps use two clocks on purpose. `created_at`/`updated_at` follow `TransactionLedger`'s
local convention so the sibling tables read alike; `last_event_occurred_at` is always UTC,
because it is what the gateway says happened. Mixing a local `now()` into that column would
make the ordering guard wrong by the host's UTC offset — a bug invisible in Greenwich.

### 2.6 `past_due` means different things in the two Paddle adapters

`PaddleCheckout` maps Paddle's `past_due` to `TransactionStatus::Failed`. That is right for a
one-time payment that will not be retried.

**On a renewal it would be a silent revenue defect.** `past_due` is dunning: Paddle Retain
keeps retrying and the charge often completes days later. `Failed` is terminal, and
`TransactionLedger::apply()` refuses to move a transaction away from a settled status — so the
recovered renewal would sit at `failed` in the merchant's books for good while its status
history quietly recorded the truth.

`PaddleSubscription` therefore maps `past_due` to **`Pending`**: the money has not arrived and
has not been given up on, and `Pending` is the status that can still change. `PaddleCheckout`'s
mapping is unchanged — it is not this release's to alter.

Because a shared mapping would have re-introduced the bug by inheritance,
`mapTransactionStatus()` is declared **abstract** on `SpeaksPaddle`: each adapter must state
its own, and neither can acquire the other's by accident.

### 2.7 An unrecognised subscription status throws rather than defaulting

`mapTransactionStatus()` defaults unknown states to `Pending`, reasoning that "pending never
releases goods, so an unfamiliar state errs in the safe direction."

**There is no safe direction for a subscription.** Every `SubscriptionStatus` value is an
assertion about whether a paying customer still has what they paid for: defaulting to `Paused`
locks out a paying customer, defaulting to `Active` gives the product away. So an unrecognised
status is refused by name. The webhook fails loudly, Paddle retries, and a human looks — which
is the correct response to a gateway that has started sending a state nothing here has heard
of.

### 2.8 A cancellation is a scheduled change, not a status — and no `grantsAccess()` is provided

A customer who cancels does not become `Cancelled`. They stay `Active` with a `ScheduledChange`
pending until the period they have already paid for runs out. Revoking access on the click
takes away paid-for service; waiting for the status to change is correct. `SubscriptionSnapshot`
carries the two together, and offers `isCancelling()`, `isPausing()` and `accessEndsAt()` —
factual readings of the payload.

It deliberately offers **no** `grantsAccess()`. Whether a `PastDue` customer keeps the product
while the gateway retries their card is a product decision — most merchants allow a few days
and show a banner, some cut off at once, and both are defensible. Encoding one would be the
framework dictating rather than enabling, which `PRD.md`'s freedom principle rules out. The
convention is documented on the enum; the judgement stays with the application.

## 3. Explicitly NOT in 1.4.0

- **A `Services\Subscription` facade.** Subscriptions ride on the Checkout adapter contract, per
  §2.2. If a second gateway's subscription surface turns out not to fit, that is the release
  that revisits it.
- **Subscription reporting.** `§9.6.7 — built-in report generation` was open after 1.2.0 and
  remains open, subscriptions included.
- **Refund outcome reconciliation** (1.3.0 §2.4) and **§9.5, the custom checkout page.** Both
  still open.
- **`Event::` constants for subscription lifecycle.** `payment.completed` already fires when a
  subscription's charge settles, which is what applications act on. A stable event identifier
  nothing consumes is scaffolding; purely additive to introduce later if a real call site
  appears.
- **Eight of the nine gateways in §9.3/§9.4.** `StripeConnectExpress`, `Fiuu`, `iPay88`,
  `BillPlz`, `Adyen`, `Airwallex`, `HitPay`, and `Xendit` are unbuilt. Their namespaces remain
  reserved, and per `CLAUDE.md` an unbuilt adapter is an absent file, never a placeholder.

## 4. Compatibility

Semver **minor**. Every change is additive:

- New class `Services\CheckoutAdapters\PaddleSubscription` and new trait `SpeaksPaddle`, both in
  a namespace that already existed.
- Twelve new value objects and `SubscriptionLedger` in `Services\Checkout\*`.
- No method signature on `Services\Checkout` or any existing `Services\Checkout\*` value object
  changed, gained, or lost anything. A 1.3.0 application upgrades with no edit.
- `PaddleCheckout`'s public surface is byte-identical; only where its private members live
  changed. It remains `final`.
- No new `mitosis` command, no new Composer dependency, no entry-point or `Console::run()`
  change.
- No setup-owned table DDL changed, so `CrossRepoContracts.md` §8's compatibility surface is
  untouched. That section's **enumeration** of Checkout's tables goes from three to four, which
  is additive to a non-setup-owned list — but the skeleton mirror now drifts, so §10's sync
  procedure **is** triggered by this release where 1.3.0 did not trigger it.

### 4.1 Upgrading from 1.3.0

Run `php mitosis checkout:install` again. It is now re-runnable: each table is skipped when
already present, and only `checkout_subscriptions` is created. No migration ships and none is
needed.

That re-runnability is a **fix**, not a restatement. Before this release a second run of
`checkout:install` **failed** — `Schema::createTable()` emits `CREATE TABLE IF NOT EXISTS` but
then creates the table's indexes unconditionally, so the command died on a duplicate index. The
guard is a `hasTable()` check rather than an `IF NOT EXISTS` on the index, because MySQL has no
`CREATE INDEX IF NOT EXISTS` and there is no portable clause to reach for. Found by writing the
test that claimed the upgrade path worked.

## 5. Verification

- **142 new tests**, alongside the existing suite: **783 green** on PHP 8.2 and 8.3. Adapter
  tests mock `HttpClient`; no live gateway calls in the automated suite, per
  `TestingStrategy.md` Tier 4.
- **The extraction gate.** `PaddleCheckoutTest.php` passes **byte-unmodified** after
  `SpeaksPaddle` was carved out of the class it tests. That, rather than an assertion in this
  document, is what makes "zero behaviour change" a fact.
- The security-critical callback path is covered adversarially per Tier 1, mirroring 1.3.0's
  set on the new parser: valid signature, tampered payload, wrong signing secret, stale
  timestamp, malformed and absent headers, a missing configured secret, case-insensitive header
  matching, and secret rotation with several signatures on one header.
- **Both event guards, and the ordering between them.** A non-`subscription.*` event and a
  `subscription.*` event carrying a `txn_` are each refused by name. And
  `testParseSubscriptionCallbackVerifiesTheSignatureBeforeTheEventGuard` signs a
  `customer.created` body with the **wrong** secret and asserts it is refused for the signature
  rather than the event type — pinning, in the mocked suite, the ordering that 1.3.0 §5.1 could
  only observe live.
- **The §2.6 defect, proved end to end against a real ledger**, not asserted in a docblock:
  a renewal goes `past_due`, is recorded, later completes, and must finish at `success`. The
  test was confirmed non-vacuous by temporarily restoring `PaddleCheckout`'s mapping, under
  which it fails.
- **One exception type out of a Tier 1 parser.** `Money` validates what it is handed and throws
  `InvalidArgumentException` — correct for a value object, wrong to let escape a webhook parser
  that promises `CheckoutException`, since a handler catching the latter would take an uncaught
  fatal on a payload that had verified. Summing item prices was new surface (`PaddleCheckout`
  never added amounts together), so a signed payload carrying a negative price, a non-ISO
  currency code, or two currencies is caught and reported as a gateway error. All three are
  tested.
- The §2.5 guard is tested for exact redelivery, an out-of-order event that must not overwrite
  newer state, an `updated` arriving before its `created`, a same-second event that must still
  apply, and UTC normalisation of a gateway timestamp taken in another zone.
- Ledger tests drive the very blueprint closure `checkout:install` emits, on in-memory SQLite,
  so the shipped DDL and the code writing to it cannot drift.

**Outstanding before tagging** (see `ReleasePolicy.md` § Packagist publication checklist):

### 5.1 What the live sandbox run established

Run against a live Paddle sandbox account, driving the adapter itself rather than a script
written to agree with it. Confirmed end to end:

- **An inline non-catalog price carrying a `billing_cycle` really does create a subscription.**
  The premise the whole release rests on. `{"interval":"month","frequency":1}` went out and read
  back verbatim; the transaction was created `draft` → `pending`, `paymentReference` null.
- **`custom_data` IS inherited from the transaction onto the subscription** — undocumented on
  Paddle's side, and the **opposite** of what §2.3 assumed. `SubscriptionSnapshot::$reference`
  therefore populates for free. The design works either way because it also links through
  `transaction_id`, but the assumption is now corrected rather than left to be rediscovered.
- **The subscription entity carries no `transaction_id`**, only the `subscription.created`
  delivery does. So `$transactionReference` is always null on a snapshot read by
  `retrieveSubscription()`, which is why it is nullable.
- **`transaction.subscription_id` is asynchronous.** Read immediately after the transaction
  settled it was still `null`; seconds later it carried the id. `subscriptionReferenceOf()`
  returning null means "not yet", never "never" — now recorded on the method.
- **Every mutation returns the full subscription entity.** This was the release's biggest
  structural risk, since four methods are typed `: SubscriptionSnapshot` on the strength of it.
  `PATCH`, `/cancel`, `/pause` and `/resume` each came back with the complete entity — a
  `changePlan()` to an annual plan read back as `25000 GBP, every year` with recalculated period
  dates.
- **`changePlan()` accepts inline non-catalogue prices**, so the §2 correction holds against the
  API and no merchant is forced to seed a catalogue. `do_not_bill` alongside
  `on_payment_failure` is accepted rather than rejected.
- **The cancellation trap behaves exactly as modelled.** `cancel(NextBillingPeriod)` returned
  `status: active`, `isTerminal() === false`, `isCancelling() === true`, `resumeAt` null, and
  `accessEndsAt()` at the period end. The one thing the whole access-gating design exists for.
- **The `scheduled_change` shape is one block**, `action` + `effective_at` + `resume_at`, with
  `resume_at` populated only on a pause. `ScheduledChange` models it correctly.
- **A zero-amount trial does create a transaction**, at `grand_total: "0"` — so
  `createCheckout()`'s premise holds on the trial path too. Paddle adds
  `requires_payment_method: true` to the trial period itself.
- **A paused subscription keeps its `current_billing_period`** rather than nulling it, contrary
  to the documentation. The fields were nullable anyway, so nothing moved.
- **VAT applies on a GB address** — a line item of subtotal 2083 / tax 417 / total 2500 — which
  is the condition 1.3.0 §5.3 identified as necessary for the refund allocator to be testable at
  all.

And three defects it found that the mocked suite structurally could not, which is the whole
argument for running it:

> **`pause()` was broken for every indefinite pause.** It sent `on_resume` unconditionally, and
> Paddle refuses that: `cannot use on_resume if resume_at is not present`. So a pause with no
> return date — the commonest kind — failed outright. Fixed by sending `on_resume` only
> alongside `resume_at`, and refusing the invalid pairing locally with a message that says why.
> A fixture that accepts any well-formed body cannot see this.

> **A scheduled change locks the subscription, and the adapter had no way out.** While any change
> is pending, Paddle refuses every further change with `subscription_locked_pending_changes` — so
> a customer who cancelled for the end of the period could not then pause, re-plan, or cancel
> immediately, and *nothing in the shipped surface could withdraw the cancellation*. They would
> have had to wait out their own change of mind. Fixed by adding `removeScheduledChange()`,
> verified against the API. The same shape of operational constraint as the pending-refund lock
> 1.3.0 recorded on `refund()`.

> **`resume` accepts only `immediately`.** `next_billing_period` is refused as a bare
> `bad_request` / "Invalid request." naming no field, which is close to undiagnosable from the
> reply alone. The adapter now refuses it locally and says so. Relatedly, Paddle's documentation
> states that resuming a pause-scheduled subscription edits the pending pause's resume date; the
> API refuses it with `subscription_must_be_paused`. The docblock claiming otherwise was wrong
> and has been corrected.

Two further behaviours worth recording so they are not rediscovered in production:

> **The invoice route is open after all, and it is how this run got a subscription without a
> browser.** `ReleaseNotes_1.3.0.md` §5.3 recorded `collection_mode: manual` as closed, refused
> with `transaction_customer_not_suitable_for_collection_mode`. The real blocker was the
> **address**, not the customer: with a full postal address (`region` and `second_line` present)
> the transaction was accepted, reached `ready`, and a `PATCH` to `billed` created a live
> subscription with no card and no Paddle.js. That matters beyond convenience — it means a
> subscription integration can be verified end to end in CI-like conditions rather than only
> through a browser. Note Paddle materialises the inline price into a real catalogue `pri_...`
> when it does this.

> **A mutation issued immediately after a charge-bearing one can return `409 conflict — lock
> already taken`.** Paddle holds a short per-subscription lock; the same call succeeded on retry
> moments later. Transient, surfaced verbatim, and the caller's to retry.

### 5.2 Real Paddle-signed webhook deliveries

Driven through a Cloudflare tunnel to a notification destination subscribed to eight
`subscription.*` types plus `customer.*`, capturing **raw request bytes** — signatures are
computed over exact bytes, so a capture that decoded and re-encoded would prove nothing.
Fourteen assertions over five genuine deliveries, all passing:

- **Real Paddle-generated signatures verify.** Four genuine `subscription.*` deliveries
  (`updated`, `paused`, `resumed`, `updated`) parsed — proving the semicolon-delimited
  `ts=`/`h1=` header format, the `ts:body` signed payload, and byte-exact raw-body handling
  against traffic Paddle actually signed. This is the one property the mocked suite structurally
  cannot establish, since it signs with the same helper it verifies with.
- **Negative control:** every one of those same deliveries, replayed with only the signing secret
  changed, was refused with "did not verify". So a pass means the signature was checked, not that
  checking was skipped.
- **The event guard refused a genuine `customer.created`** by name.
- **The documented ordering, proved by one delivery behaving two ways.** That same
  `customer.created` is refused by the *event guard* under the real secret and at the *signature*
  stage under the wrong one — so verification demonstrably happens before any parsing.
- **`custom_data` survives a real delivery**: `reference: 'sub-order-manual'` on all four
  subscription events, confirming §5.1's finding through the webhook path too.

And the defect that run found, which no mocked fixture could have:

> **Same-second siblings were not recognised on redelivery.** Paddle sent `subscription.resumed`
> and `subscription.updated` 126 microseconds apart; stored at second precision they are
> simultaneous. The guard remembered one `last_event_id`, so redelivering the sibling re-applied
> it and returned `true` — telling the application a real change had happened when nothing had.
> Fixed by remembering `last_event_ids`, the set of ids applied at the stored second. Verified by
> replaying the captured deliveries: 2 of 4 wrongly moved the record before, 0 of 4 after.

**Still outstanding, and the reason this document is DRAFT:**

1. **`$hostedCheckoutUrl` against a genuine `pay.paddle.io` hosted link.** Hosted *mode* is what
   this run used and it creates transactions correctly, but no real hosted link was exercised.
   1.3.0 left the same question open.
2. **`$paymentPageUrl` mode remains unusable on this account**, reproducing 1.3.0 §5.2 for
   subscriptions: `https://localhost` is the account's default payment link and is still refused
   as an override with `transaction_checkout_url_domain_is_not_approved`. The default-link
   setting and the Website-approval list are different things, and only the second governs the
   override.

### 5.3 The upgrade path, verified against the real 1.3.0

Not asserted from the code — driven against a `create-project` of the skeleton with
`monad/clarity: 1.3.0` installed from Packagist:

- On **1.3.0**, a second `php mitosis checkout:install` **fails**:
  `index uq_checkout_transactions_gateway_reference already exists`. The defect §4.1 describes is
  real and reproducible on the shipped release, not theoretical.
- Upgrading that same project to 1.4.0 and re-running the command reports
  **`1 of 4 tables created`** — only `checkout_subscriptions` — and the pre-upgrade rows in
  `checkout_transactions` are untouched.
- A third run reports `Checkout is already installed: all four tables were already present.`
- `php mitosis health` is green on the upgraded project, and on a fresh one.

### 5.4 The rest of the checklist

- `php mitosis health` green on a fresh `create-project` against the **tagged** version.
- **Website documentation** — checklist item 7. Subscriptions are user-facing and `monad-www`'s
  nav is hardcoded, so a page and its nav entry need merging before the tag.
- **`CrossRepoContracts.md` and `RepoMap.md` mirrors** in `monad/skeleton` synced. Unlike 1.3.0,
  this release edits `CrossRepoContracts.md`, so its §10 procedure applies.

This is a payment integration; mocked coverage is not evidence that it works.
