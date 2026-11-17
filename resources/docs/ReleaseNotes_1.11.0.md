# Monad Clarity 1.11.0 Release Notes

**Status:** FROZEN — canonical specification for the 1.11.0 release, tagged 2026-11-17.

- **Live verification.** The live release gate is complete (§5.3). It ran against the code at
  `42316de`, before the five fixes it prompted, so those fixes are covered by mocked tests only.
- **Checklist.** §5.4 records the release checklist as it actually went.
This document is the source of truth for WHAT ships in 1.11.0. It does not restate 1.0.0, 1.2.0,
1.4.0 or 1.7.0; those remain frozen for what they specified, and this release inherits the
binding parts of `ReleaseNotes_1.4.0.md` (§2.2, §2.3, §2.5, §2.7, §2.8) and
`ReleaseNotes_1.7.0.md` (§2.3, the catalogue-mode `Money(0, …)` idiom).

> **Scope note.** 1.2.0 shipped Stripe for one-time payments. 1.4.0 added recurring billing, but
> only through Paddle, and said that a second gateway's subscription surface was the test of
> whether the design fit. 1.11.0 is that test: **recurring billing through Stripe**, built on
> the 1.4.0 value objects and ledger without changing any of them. The facade is untouched, no
> table is added, and no Composer dependency is added.

The decisions below were laid out as alternatives in `GapAnalysis_BuildPlan_1.11.0.md` §2 (D1 to
D12) and taken by Marshal on 2026-11-17, every recommendation. Task 0's test-mode spike ran the
same day and refined three of them (D2, D5, D6) without reopening any. Each heading below names
the plan's decision it records, and the refined text is what was decided. That plan stays as the
build sequence and the acceptance gate; this document is the specification.

## 1. What ships in 1.11.0

### 1.1 Stripe subscription adapter

`Monad\Clarity\Services\CheckoutAdapters\StripeSubscription` — a `final class` extending
`Checkout`, gateway name `stripe_subscription`. Subscriptions are started through Stripe
Checkout in `subscription` mode, then read and changed through `/subscriptions`.

It sits beside `StripeCheckout` exactly as `PaddleSubscription` sits beside `PaddleCheckout`
(`ReleaseNotes_1.4.0.md` §1.1), and its public surface is the same as `PaddleSubscription`'s.

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
| `pause()` / `resume()` | `POST /subscriptions/{id}/pause` · `/resume` (endive, §2.2) |
| `changePlan()` | `GET` then `POST /subscriptions/{id}` with the whole item set replaced |

A refund that cannot exist is refused in plain words and never posted to Stripe: a refund
against a checkout session that charged nothing, or against an invoice that is not paid, says
why.

**Everything 1.4.0 introduced is reused unchanged:** the twelve subscription value objects and
`SubscriptionLedger`. A catalogue plan carries the 1.7.0 idiom, `Money(0, $currency)` with the
real figure read back off `CheckoutSession::$amount`, and a catalogue price may carry a trial
(§2.5).

**How Stripe differs from Paddle, and what the adapter does about each difference:**

- **The link is synchronous.** A completed subscription-mode session already carries
  `subscription`. Paddle's `transaction.subscription_id` arrives seconds late (1.4.0 §5.1).
  `subscriptionReferenceOf()` still returns `?string`, because an *open* session has none.
- **The subscription entity never names the session.** So
  `SubscriptionSnapshot::$transactionReference` is always null for Stripe. The ledger link comes
  from `subscriptionReferenceOf()` plus `SubscriptionLedger::linkTransaction()`, the second of
  1.4.0 §2.3's two routes.
- **`$reference` is populated deliberately.** `client_reference_id` does not reach the
  subscription, so `createCheckout()` sends `subscription_data[metadata][reference]`. That is
  the same effect Paddle's `custom_data` inheritance has, and the first live run confirmed it
  works (§5.1).
- **There is no `scheduled_change` block.** `ScheduledChange` is built from
  `cancel_at_period_end` and `cancel_at`, and only ever carries the action `Cancel` (§2.2).
- **Stripe takes idempotency keys.** So `changePlan()` can be made retry-safe, which Paddle's
  cannot (§2.11).

### 1.2 `CheckoutAdapters\SpeaksStripe`

The machinery both Stripe adapters share, extracted rather than duplicated: `verifySignature()`,
`send()`, `requireString()`, `referenceOf()`, `amountOf()`, the refund-reason rule as
`refundParams()`, and the line-item synthesis for an empty `lineItems`. See §2.9.

### 1.3 What does not change

- **No fifth abstract method on `Services\Checkout`.** Subscription operations are public
  methods on the adapter (`ReleaseNotes_1.3.0.md` §2.4, 1.4.0 §2.2).
- **No `final readonly` value object changed.** `CheckoutRequest`, `CheckoutSession`,
  `CallbackEvent`, `TransactionSnapshot`, `SubscriptionSnapshot`, `SubscriptionEvent`,
  `SubscriptionItem` and `BillingCycle` keep their constructors and properties.
- **No new enum case** on `SubscriptionStatus`, `ProrationBillingMode`,
  `PaymentFailureBehaviour`, `SubscriptionEffectiveFrom` or `ResumeBilling` (§2.3).
- **No new table.** `checkout_subscriptions` is already gateway-agnostic, so `checkout:install`,
  `DDL.sql` and `CrossRepoContracts.md` §8 do not move.
- **No new Composer dependency.** `stripe/stripe-php` is still deliberately not pulled in, as in
  1.2.0. Signature verification uses `Utils\HMAC` and `Utils\ConstantTime`.
- **`StripeCheckout`'s public surface and its pinned `API_VERSION = '2024-06-20'` do not move.**

## 2. Resolved specification decisions

Recorded here because `ReleaseNotes_1.0.0.md` is frozen. Each heading names the decision in
`GapAnalysis_BuildPlan_1.11.0.md` §2 that it records.

### 2.1 `StripeSubscription` pins `2026-09-30.endive` (build plan D1)

The adapter pins `2026-09-30.endive`, **per class**, so `StripeCheckout` stays on `2024-06-20`.
Three reasons:

- It is the earliest version with a real `/pause` endpoint (§2.2).
- A new adapter should not be built on payload shapes Stripe retired 18 months ago.
- The parsers have to read the basil-and-later shapes anyway, because Stripe renders a webhook
  in the *endpoint's* API version rather than the version the adapter sends.

**The newest version is now `2026-10-28.endive`**, and the adapter does not use it. The pin is
the version every request in the first live run used (§5.1). Moving to the newer one is a
deliberate later change, verified the same way, not a silent bump.

The alternative was to pin `2024-06-20` to match `StripeCheckout`. The old shapes are simpler,
but it forces D2's alternative (§2.2) and ships a new adapter on a version two and a half years
old.

**Both parsers read both webhook shapes, preferring the newer one.** A merchant whose endpoint
predates basil (2025-03-31) gets `current_period_*` on the subscription itself and
`invoice.subscription` at the top level. This is a real configuration, not a hypothesis: the
test account's default API version is `2025-02-24.acacia`, so an endpoint created with defaults
receives the legacy shape (§5.1).

### 2.2 Pause is Stripe's real `/pause`, and a resume pays the invoice it raises (build plan D2)

`pause()` and `resume()` use `POST /subscriptions/{id}/pause` and `/resume`. After a pause
`status` is `paused`, so `SubscriptionStatus::Paused` is Stripe's own word. The constraints are
enforced locally by name, as `PaddleSubscription` does:

- `pause()` accepts only `SubscriptionEffectiveFrom::Immediately`. A scheduled pause needs
  Subscription Schedules, which are out of scope (§3).
- `pause()` refuses any `$resumeAt` or `$onResume`. The endpoint has no resume date.
- `resume()` accepts only `Immediately`.
- `ResumeBilling::StartNewBillingPeriod` maps to `billing_cycle_anchor[type]=now`;
  `ContinueExistingBillingPeriod` maps to `unchanged`.
- `createCheckout()` sends `subscription_data[billing_mode][type]=flexible`, because Stripe
  pauses only flexible-mode subscriptions.

The alternative was `pause_collection[behavior]=void`, which works on any version. The status
stays `active`, so the adapter would have to report `Paused` for something Stripe calls active,
and it is the only route if §2.1's alternative is taken. That is a less honest mapping.

**A paused snapshot.** Stripe moves `current_period_end` to the moment of the pause. So
`currentPeriodEndsAt` and `accessEndsAt()` are that moment, and `nextBilledAt` is null. That
matches "service stops when billing stops", and the docblock says so.

**What the first live run settled, and is binding:**

- **Only an active trial blocks a pause.** Stripe documents "no subscription-level trial" as a
  pause restriction, which read literally would stop any subscription that ever had a trial from
  pausing. Pausing a *trialing* subscription is refused with "Can't pause a subscription that is
  trialing. Set `trial_end` to `now`…", and the same subscription paused normally once its trial
  had ended. The adapter does not refuse this locally: whether a subscription is trialing needs
  a read, and Stripe's own message already says what to do. The docblock records it.
- **Pausing credits the unused period.** Under Stripe's default
  `invoicing_behavior=pending_invoice_item`, the pause created a pending −1450 proration
  ("Unused time … after 19 Nov"). That credit lands on the invoice raised at resume. This is
  unlike Paddle, where a paused subscription keeps its period. The adapter sends the default and
  the docblock says so plainly.
- **`resume()` with `StartNewBillingPeriod` must pay the invoice it raises.** `/resume` with
  `billing_cycle_anchor[type]=now` returned the subscription **still `paused`**. Its new invoice
  was `open`, with `auto_advance: false` and no `next_payment_attempt`. Stripe's default
  `payment_behavior=resume_on_payment_attempt` resumes only when someone attempts that payment,
  and Stripe never will on its own: two hours later on the test clock it was still `open` and
  the subscription still `paused`. `POST /invoices/{id}/pay` moved it to `active` with a fresh
  period. So:
  - `resume()` calls `/resume`.
  - If the returned subscription's `latest_invoice` is `open`, `resume()` pays it.
  - It then re-reads the subscription and returns that snapshot.
  - **A declined payment raises `CheckoutException`, and the subscription is no longer paused.**
    The release-gate run (§5.3) found that Stripe resumes on the payment *attempt*, so after a
    refusal the subscription is `past_due` with a new period and the invoice stays `open`.
    Stripe never retries that invoice, and a second `resume()` is refused ("You can only resume
    a subscription if it is `paused`"). The failure carries no machine-readable cause, so the
    subscription is re-read and its raw status decides. **Only `past_due`** earns the decline
    wording: Stripe accepted the resume but could not collect the invoice, will not retry it,
    and will refuse a second `resume()`; the resume completes once the invoice is paid, and the
    invoice's `hosted_invoice_url` is named as the place to pay it **when Stripe supplies one**
    (the field was not observed in the live run, so that clause rests on the documented
    invoice shape). **Any other status, or a failed re-read,** gets the unknown-outcome wording,
    which makes no claim about the charge, because a 5xx may have charged, a 2xx with an
    undecodable body did, and a 429 or 401 did not. It names the status Stripe reports if
    known and says to confirm with `retrieveSubscription()`. A payment that never got an
    answer (a transport failure) keeps its own wording: the subscription is still paused *as far
    as the call knows*.
  - Paddle's `resume()` "may charge the customer immediately", so charging here is parity, not a
    new behaviour.
- **`ContinueExistingBillingPeriod` (`anchor=unchanged`) resumes at once.** It raised no charge
  inside the original period: Stripe restored the period end the pause had cut short. It also
  voided an open resume invoice left by an earlier `anchor=now` call, so a second resume safely
  supersedes a first that was never paid.
- **A pause that outlasts the period raises no invoice at resume either** (§5.3). With
  `ContinueExistingBillingPeriod`, Stripe created a pending proration for the resumed time and
  billed it, together with the pause credit, at the next renewal. `resume()` had nothing to pay
  and returned `Active`.

### 2.3 Stripe's three extra statuses map onto the existing five (build plan D3)

Stripe has eight subscription statuses and `SubscriptionStatus` has five. 1.4.0 §2.7 forbids
defaulting, so each extra is mapped by name, and the raw status is kept in `raw`:

- `incomplete` → `PastDue`. The first payment is outstanding and being collected.
- `unpaid` → `PastDue`. Retries are exhausted but the subscription is not cancelled. The access
  decision stays the application's, per 1.4.0 §2.8.
- `incomplete_expired` → `Cancelled`. Terminal: it never started.

An unknown status still throws, as 1.4.0 §2.7 requires.

The alternative was to add `Incomplete` and `Unpaid` cases. That is more precise, but a new case
breaks any exhaustive `match` an application has already written over `SubscriptionStatus`. It
is semver-major in spirit and would need a `PastDue` docblock rewrite.

### 2.4 Proration and payment-failure modes map where Stripe has an equivalent, and are refused where it does not (build plan D4)

Stripe has three proration behaviours; Clarity's enum has five cases.

| Clarity | Stripe |
| --- | --- |
| `ProrationBillingMode::ProratedImmediately` | `always_invoice` |
| `ProrationBillingMode::ProratedNextBillingPeriod` | `create_prorations` |
| `ProrationBillingMode::DoNotBill` | `none` |
| `PaymentFailureBehaviour::PreventChange` | `payment_behavior=error_if_incomplete` |
| `PaymentFailureBehaviour::ApplyChange` | `payment_behavior=allow_incomplete` |

`FullImmediately` and `FullNextBillingPeriod` are **refused by name**. Stripe has no equivalent
that does not also reset the billing anchor, and a guessed equivalence would charge a real
customer the wrong amount.

The alternative was to map `FullNextBillingPeriod` to `none` as well. Both would then mean "the
next renewal bills the new price", but that collapses two cases the enum keeps apart on
purpose.

### 2.5 Trials are whole days (build plan D5)

Stripe's Checkout takes `subscription_data[trial_period_days]`, an integer number of days.

- `BillingInterval::Day` maps directly, from one day (`BillingCycle` already guarantees a
  frequency of at least 1). The spike found that `trial_period_days=1` is refused: "The trial
  end date must be at least 2 days after the customer's test clock time." That is a rule for a
  customer **on a test clock** only: the release-gate run (§5.3) had Stripe accept one for an
  ordinary customer and run a 24-hour trial. The adapter therefore sends a one-day trial as one
  day, and a developer driving it on a test clock will meet Stripe's own refusal.
- `Week` becomes 7 × frequency days.
- `Month` and `Year` are **refused at construction**. Converting them would silently turn "one
  month" into 30 days.
- Unlike Paddle, a trial **is** allowed in catalogue mode, because a Stripe price carries no
  trial.

The alternative was to send `trial_end` computed from the moment the session is created. That
drifts by however long the customer takes to pay, and Stripe refuses a `trial_end` less than 48
hours out.

### 2.6 `changePlan()` with an inline item creates a product, uses it, and archives it (build plan D6)

Stripe's subscription update takes `price_data` only with an existing `product` id; it does not
take `product_data`. So, for each inline `SubscriptionItem`:

1. `POST /products` with `name` set to the item's description. It must be created **active**:
   Stripe refuses an inactive product on a subscription update ("…is marked as inactive, and
   thus no new subscriptions can be create[d]…").
2. Send the subscription update with `price_data[product]`.
3. **Archive** each product just created, with `POST /products/{id}` `active=false`. Do this
   whether step 2 succeeded or failed, so a refused change never leaves a stray active product.
4. Each product call carries an idempotency key derived from the call's key and the item index.
   *Superseded by §2.13 item 7:* only product **creation** carries the derived key; activation
   and archiving carry none.

The first live run confirmed the result is the footprint Checkout already leaves: Checkout's own
`product_data` creates a real Product, **inactive**, and the live subscription kept billing
against a product archived after the update. So a merchant's catalogue grows identically
whether the plan was set at checkout or changed later. Without this, an "upgrade" button needs a
Stripe catalogue, which 1.4.0 §1.1 promised Paddle merchants they would never need.

The alternative was to refuse inline items on Stripe and require `SubscriptionItem::catalogPrice()`.
That is simpler and creates no catalogue objects, but Stripe merchants would not get the parity
Paddle merchants have.

### 2.7 Renewal transactions are not recorded by `TransactionLedger`, and the notes say so (build plan D7)

**This affects both gateways.** `TransactionLedger::recordCallback()` throws when a reference was
never `open()`ed. A Stripe renewal is an `in_` with no checkout behind it, and a Paddle renewal is
a `txn_` with none either. The 1.4.0 end-to-end renewal test only passes because it `open()`s the
row itself.

This release keeps the gap out and documents it:

- `parseCallback()` still verifies and normalises subscription `invoice.*` events, so an
  application can act on a renewal.
- `retrieveStatus()` and `refund()` accept an `in_`.
- Recording an unsolicited transaction needs an amount, and `CallbackEvent` has none. The answer
  is a gateway-agnostic `TransactionLedger` method designed for both gateways at once, which is
  its own minor release.

The alternative was to design that method here. It is scope creep into a frozen ledger, and it
would be done for one gateway when the gap is two gateways wide.

**The cost is higher on Stripe than on Paddle, and is documented rather than discovered.** On
Paddle, the first payment's events name the `txn_` the application `open()`ed, so only renewals
miss the ledger. On Stripe, a subscription checkout raises `invoice.paid` for the *first*
invoice too, and that `in_` was never opened. The first live run confirmed it: every signup
raises `invoice.paid` and `invoice.payment_succeeded` with `billing_reason:
subscription_create`, the zero-amount trial invoice included.

So the obvious handler, `$ledger->recordCallback($adapter->parseCallback($body, $headers))`,
**would throw on every signup**, not just on renewals. The handler shape that works is to branch
on the `CallbackEvent`'s reference prefix: send `cs_` to the ledger and handle `in_` in the
application. This is documented with the adapter, and `parseCallback()`'s docblock says the same
in one sentence.

### 2.8 A renewal invoice's dunning state is `Pending`, never `Failed` (build plan D8)

This is 1.4.0 §2.6, asked of Stripe. Invoice states map as follows:

| Invoice state | `TransactionStatus` |
| --- | --- |
| `paid` | `Success` |
| `open` | `Pending` (including after `invoice.payment_failed`) |
| `draft` | `Pending` |
| `uncollectible` | `Failed` |
| `void` | `Cancelled` |

`invoice.payment_failed` is dunning, not the end. `Failed` is terminal, and marking it there
would lock a recovered renewal at `failed` — the exact 1.4.0 §2.6 defect.

Session failures keep `StripeCheckout`'s mapping, where `async_payment_failed` is `Failed`. That
is the first payment, attempted while the customer is present. Whether this holds when Stripe
retries the first invoice could not be checked live (§5.2).

Two further facts from the release-gate run (§5.3). A `past_due` subscription that survives to
its period end is billed there, so `nextBilledAt` is its period end rather than null, though
`incomplete` and `unpaid` still report none. And the invoice behind a dunned renewal that
Stripe finally cancels is left `open`, so it reads `Pending` indefinitely.

### 2.9 The shared machinery is a `SpeaksStripe` trait (build plan D9)

The trait holds `verifySignature()` (security-critical, and the code a second copy would be most
costly in), `send()`, `requireString()`, `referenceOf()`, `amountOf()`, the refund-reason rule as
`refundParams()`, and the line-item synthesis for an empty `lineItems`.

The gate is `StripeCheckoutTest.php` passing **byte-unmodified**, as with `SpeaksPaddle`.
Per-class constants (`API_VERSION`, `GATEWAY`) are read through `self::`, exactly as
`SpeaksPaddle` reads them.

The alternative was to duplicate the code into the new class. `CLAUDE.md`'s "minimal changes"
argues for that; 1.4.0's precedent and the duplicated signature verifier argue against it.

### 2.10 Immediate cancellation does not refund on Stripe (build plan D10)

`cancel(…, Immediately)` sends a bare `DELETE /subscriptions/{id}`, with no `prorate` and no
`invoice_now`. Its docblock says plainly that Stripe, unlike Paddle, refunds nothing on an
immediate cancel: a refund is a separate `refund()` call the merchant decides on. `prorate=true`
only credits the customer's balance, and a credit balance on a cancelled customer is money
nobody will see.

### 2.11 `changePlan()` takes a trailing idempotency key (build plan D11)

`changePlan()` gains a trailing `?string $idempotencyKey = null` after `$timeoutSeconds`. The
shared leading parameters stay positionally identical to `PaddleSubscription::changePlan()`. The
key is passed on any call made with `ProratedImmediately`, because that is the call that
charges.

### 2.12 1.4.0 §3's facade question is answered: it fits, so no facade (build plan D12)

`ReleaseNotes_1.4.0.md` §3 deferred a `Services\Subscription` facade to "the release that
revisits it, if a second gateway's subscription surface turns out not to fit." This is that
release, and the surface fits. Every `PaddleSubscription` method has a Stripe counterpart with
the same signature, and `changePlan()` gains one trailing optional parameter (§2.11). The
differences are local refusals:

- `pause()` and `resume()` are immediate only (§2.2);
- two proration modes are refused (§2.4);
- month-based and year-based trials are refused (§2.5).

Those are gateway constraints, and a facade would have to express them as the same refusals.

### 2.13 Decisions taken during the build

Twelve further decisions were taken while the adapter was built. They are facts about the shipped
code that the sections above could not know, and each is recorded with its reason.

1. **Every parser refuses a malformed signed payload as `CheckoutException`, and nothing else.**
   Untrusted fields are type-checked, never cast, because the framework's `Mediator` turns a PHP
   warning into `ErrorException`, and an "Array to string conversion" would leak one. A
   malformed *descriptive* field (customer, `metadata.reference`, `price.recurring`) reads as
   absent. A malformed load-bearing field is refused. An array-valued invoice `status` reads as
   an unknown status, which is `Pending`.
2. **`SpeaksStripe::referenceOf()` reads a non-string `id` as absent** rather than casting it.
   The method is shared, so `StripeCheckout` now reads a malformed nested id as absent instead
   of raising a warning. Valid input is unaffected, and `StripeCheckoutTest.php` passes
   byte-unmodified.
3. **A recurring amount that would overflow `PHP_INT_MAX` is refused** with `CheckoutException`,
   so a malformed price cannot leak a `TypeError` from `Money`.
4. **`recurringAmount` is null when any item's `price.unit_amount` is null.** Tiered and
   metered pricing has no fixed amount to report, and a partial sum would be a wrong one.
5. **`resume()` re-reads the subscription only after paying an open resume invoice.** Otherwise
   it returns the `/resume` response's snapshot, which is Stripe's state at that moment and can
   still say `Paused` when Stripe raised nothing to pay. A failed `/pay` raises a
   `CheckoutException`. Only when the re-read says `past_due` does it say the resume took effect
   and the invoice is unpaid (§2.2). Otherwise, and when the payment never got an answer, it
   names the invoice, points at `retrieveSubscription()`, and makes no claim about whether the
   card was charged, because a 5xx leaves that unknown.
6. **`changePlan()` refuses a partial item list.** When Stripe's embedded `items` reports
   `has_more: true`, the change is refused before anything is created, because any item left out
   of the replacement set would keep billing. A subscription with more items than one page is
   refused rather than paged through.
7. **`changePlan()` retries safely with an idempotency key, and the exact semantics are:**
   - Each product creation carries the derived key `{key}:product:{index}`, so a retry reuses
     the same product.
   - Each product is then re-activated with **no** key before the update, and the archive is sent
     with **no** key. Both are naturally idempotent state-setters, and a keyed replay would
     return Stripe's cached response without applying the state.
   - A same-key retry has three outcomes. If the first update never ran, it runs now. If it ran
     and was declined, Stripe replays the decline. If it ran and changed the items, Stripe
     refuses the reused key with different parameters, and **that refusal means the change
     happened**: confirm with `retrieveSubscription()`, and never retry with a fresh key. The
     last outcome was confirmed live (§5.3).
   - **The adapter recognises that refusal in code** (Marshal's decision, 2026-11-17). Stripe
     answers HTTP 400 with `error.type` of `idempotency_error` and a message that advises "try
     using a key other than …", which is exactly what must not be done. So on every request that
     carries the caller's key or one derived from it (the product creations and the update),
     `changePlan()` detects the refusal **structurally, from the status and `error.type`, and
     never by parsing the message**, and throws a `CheckoutException` that names the key, says an
     earlier attempt with it already went through, points at `retrieveSubscription()`, and
     forbids a fresh key unless the subscription shows the change was not made. Stripe's body is
     not embedded. A 400 of any other type, or a body that is not JSON, is the ordinary failure.
     An unkeyed call is unaffected, since Stripe cannot raise this without a key. Products
     created earlier in the call are still archived. Other methods are not covered.
8. **If the update succeeds but archiving a just-created product fails, `changePlan()` returns
   the successful snapshot and does not throw.** A throw invites a retry that could charge
   again. The product may remain active in the Stripe catalogue, which costs nothing.
9. **A catalogue `SubscriptionItem` price id must start with `price_`** in `changePlan()`,
   refused locally exactly as the constructor refuses one.
10. **`removeScheduledChange()` sends only `cancel_at_period_end=false`.** Whether `cancel_at=''`
    is also needed was release-gate item L5, answered in §5.3: it is not.
11. **An invoice's reported amount is its `amount_due`, not its `total`.** `amount_due` is never
    negative, is what the card is actually charged, and is 0 for the credit invoice a downgrade
    through `changePlan()` produces. So `retrieveStatus()` on a credit invoice reports `Success`
    at zero rather than failing, because a negative `total` is an amount no `Money` can hold. A
    missing, non-integer or negative `amount_due`, or an unreadable currency, is refused as
    `CheckoutException`.
12. **A refund resolves only to a payment whose status is `paid`, on an invoice whose status is
    `paid`.** An unpaid invoice, including a first payment still processing, is refused before
    anything is posted, and so is a declined or processing attempt, because a refund posted
    against a payment that collected nothing cannot succeed and should not be tried. Snapshot and
    event `paymentReference` is **only** a payment whose status is `paid`, and the legacy
    top-level `payment_intent` counts only on an invoice that is itself `paid`. The first draft
    also reported an attempt still `open` and never a `canceled` one, but the release-gate run
    (§5.3, defect 5) found that Stripe lists a declined attempt as `open` too, so `open` cannot
    tell a decline from a payment still processing. A caller could hand that `pi_` straight to
    `refund()`; the safe answer is null until a payment has paid.

## 3. Explicitly NOT in 1.11.0

- **Scheduled pauses or resumes.** They need Stripe Subscription Schedules, which this release
  does not touch (§2.2).
- **`TransactionLedger` recording of renewals.** A gateway-agnostic method for both gateways is
  its own minor release (§2.7).
- **A `Services\Subscription` facade.** Answered in §2.12: the surface fits.
- **`StripeConnectExpress`.** Still unbuilt, its namespace reserved, and per `CLAUDE.md` an
  unbuilt adapter is an absent file, never a placeholder. The other seven reserved adapters
  (`Fiuu`, `iPay88`, `BillPlz`, `Adyen`, `Airwallex`, `HitPay`, `Xendit`) are likewise unbuilt.
- **§9.5, the custom checkout page, and §9.6.7, built-in report generation.** Both still open.
- **`Event::` constants for subscription lifecycle.** As in 1.4.0 §3: `payment.completed`
  already fires when a subscription's charge settles, and a stable event identifier nothing
  consumes is scaffolding. Purely additive to introduce later if a real call site appears.
- **Two defects found while building, left for a later patch.** Both are outside this release's
  scope under the minimal-change rule. `StripeCheckout::parseCallback()` still casts `id`, `type`
  and `object`, so a signed malformed payload can leak `ErrorException` there, as it could before
  1.11.0. `PaddleSubscription::recurringAmountOf()` has the same latent integer overflow that
  §2.13 item 3 fixes for Stripe.
- **The release-gate run's two further findings are both resolved in this release, not
  deferred:**
  - **Stripe's reused-key refusal.** Resolved in §2.13 item 7.
  - **The gateway-error leak in the shared `Checkout::assertSuccessful()`.** Since 1.2.0 it
    embedded the whole response body in exception messages, which for a declined PaymentIntent
    includes its `client_secret`. It is fixed for every Checkout adapter: see §4 and the
    CHANGELOG's `### Security` entry.

## 4. Compatibility

Semver **minor**. Every change is additive, with one exception that the CHANGELOG records under
`### Security`:

- **Exception text.** `Checkout::assertSuccessful()` no longer embeds a gateway's raw response
  body in a `CheckoutException` message. The message keeps its
  `'<gateway> request failed with HTTP <status>: '` prefix, followed by a one-line summary of the
  gateway's error fields:
  - nested objects such as the PaymentIntent are dropped;
  - `*_secret_*` tokens are masked;
  - other bodies are redacted and truncated.

  This affects every Checkout adapter. No signature changes. Exception text was never a
  documented contract. Code that parsed the old raw body out of the message must read the
  gateway's state through the adapter instead.

Otherwise:

- New class `Services\CheckoutAdapters\StripeSubscription` and new trait `SpeaksStripe`, both in
  a namespace that already existed.
- No method signature on `Services\Checkout` or any existing `Services\Checkout\*` value object
  changed, gained, or lost anything. A 1.10.0 application upgrades with no edit.
- `StripeCheckout`'s public surface and its pinned `API_VERSION = '2024-06-20'` are
  byte-identical; only where its private members live changes. It remains `final`.
- No new enum case anywhere, so no application's exhaustive `match` breaks (§2.3).
- No new table, no new `mitosis` command, no new Composer dependency, no entry-point or
  `Console::run()` change.
- No setup-owned table DDL changed, and `checkout:install` is untouched, so
  `CrossRepoContracts.md` §8's compatibility surface is untouched and its skeleton mirror does
  not drift. **`RepoMap.md` does drift**, because it gains `SpeaksStripe.php` and
  `StripeSubscription.php`. Its skeleton mirror therefore needs the sync that
  `ReleasePolicy.md`'s checklist item 8 describes (§5.4).

## 5. Verification

Verification is split in two: what a pre-decision spike established against live Stripe, and
what the release-gate run established before the tag. No automated test
makes a live gateway call, per `TestingStrategy.md` Tier 4; every adapter test uses
`FakeHttpClient`. The test-count and CI figures are recorded at tagging, not here.

### 5.1 What the Task 0 spike established

Run on 2026-11-17 in Stripe test mode with the Stripe CLI, on one test clock that was deleted
afterwards, with requests pinned to `2026-09-30.endive`. It ran before the decisions were taken,
because D1, D2, D5 and D6 rested on its answers. Confirmed:

- **A subscription path is drivable without a browser.** Both sessions were completed through
  `/v1/payment_pages/{cs}/confirm`, the internal endpoint the Stripe CLI's own `trigger`
  fixtures use. It needs a real PaymentMethod with `billing_details[name]` set. Deliveries were
  captured twice with `stripe listen`, once in the account's default version and once with
  `--latest`.
- **Checkout accepts `subscription_data[billing_mode][type]=flexible`**, and the subscription
  comes out `billing_mode.type: flexible`.
- **A subscription that had a trial pauses normally once the trial is over.** Only a currently
  trialing one is refused. §2.2 stands.
- **Stripe refused `trial_period_days=1` for a customer on the test clock**, with "The trial end
  date must be at least 2 days after the customer's test clock time." That is a test-clock rule
  and not a general one: §5.3 found Stripe accepting a one-day trial for an ordinary customer,
  and §2.5 was corrected.
- **A completed subscription-mode session has `payment_intent: null`**, with `invoice` and
  `subscription` both populated synchronously.
- **A trial session completes with `payment_status: "paid"` at `amount_total: 0`**, not
  `no_payment_required` as the plan had assumed. Its invoice is `paid`, totals 0, and has an
  empty `payments` list. That is the real shape the adapter is built against.
- **Checkout's `product_data` creates a real, inactive Product.** A subscription update refuses
  an inactive product, but accepts an active one that is archived immediately afterwards. The
  whole-set replacement (old item `deleted=true`, new inline item added) worked, and
  `always_invoice` charged the 1400 proration at once. §2.6 is written from this.
- **At the pinned version, `GET /invoices/{id}` has no `payment_intent` field.**
  `expand[]=payments` gives `payments.data[].payment.payment_intent` with
  `payment.type: "payment_intent"`. An `invoice.paid` webhook rendered at `2026-10-28.endive`
  carries no `payments`, no `payment_intent` and no top-level `subscription`, only
  `parent.subscription_details.subscription`.
- **`subscription_data[metadata][reference]` reaches the subscription.**
  `subscription.metadata.reference` was set from it.
- **The first invoice of every signup raises `invoice.paid` and `invoice.payment_succeeded`**
  with `billing_reason: subscription_create`, the zero-amount trial invoice included. §2.7's
  warning is real.
- **Pause, both resume anchors and the paused period** behaved as §2.2 records, including the
  finding that a new-period resume stays paused until its invoice is paid.

And three things it found without being asked:

> **This account's default API version is `2025-02-24.acacia`, which is pre-basil.** An endpoint
> created with defaults therefore receives the legacy shape: a top-level `invoice.subscription`
> and `invoice.payment_intent` alongside `parent`. A webhook endpoint pinned to an older Stripe
> API version is a real merchant's configuration, not a hypothesis, and both parsers must read
> both shapes.

> **Same-second siblings occur in practice.** `customer.subscription.resumed` and `invoice.paid`
> were delivered with the same `created` second. Stripe's `event.created` has one-second
> resolution, so redelivering either must return `false` from `SubscriptionLedger::record()`,
> the same defect 1.4.0 §5.2 found on Paddle.

> **The newest API version is now `2026-10-28.endive`.** The adapter pins `2026-09-30.endive`
> (§2.1).

### 5.2 The release-gate checklist

The release-gate run (plan Task 12) answered each of these against live Stripe test mode. Every
answer is in §5.3.

- **L4, a declined resume payment.** Done, and found a defect (§5.3, defect 1).
- **L5, `cancel_at` and what withdraws a cancellation.** Done.
- **L8, renewals on a test clock.** Done for a paid and a declined renewal, and `nextBilledAt`
  for `past_due` found a defect (§5.3, defect 2). **Outstanding: an asynchronous first-payment
  failure.** NOT EXERCISABLE on the gate account, which offers only `card` and `link` with the
  adapter's request shape and has no SEPA or ACH. It needs a test account with an asynchronous
  method enabled.
- **L9, `changePlan()` on a declining card.** Done.
- **L10, real signatures, the negative control, both shapes.** Done, and found a defect (§5.3,
  defect 4).
- **A zero-total resume invoice.** Done.
- **A reused idempotency key on `changePlan()`.** Done; the error's shape and a decision it leaves
  are in §5.3 and §3.
- **A quantity-only `changePlan()` on the same price.** Done.
- **A resume with the anchor unchanged after a pause that outlasts the period.** Done.
- **A one-day trial off a test clock.** Done, and found a defect (§5.3, defect 3).
- **The nested `expand[]=invoice.payments` on a session `GET`.** Done.
- **`nextBilledAt` for a `past_due` subscription.** Done (§5.3, defect 2).
- **`paymentReference` of a declined attempt**, added by the run itself. Done (§5.3, defect 5).

This is a payment integration; mocked coverage is not evidence that it works. The live gate is
complete as of 2026-11-17, apart from the one item marked outstanding above.

### 5.3 What the release-gate run established

Run on 2026-11-17 in Stripe test mode, in the same voice and with the same Stripe CLI as §5.1,
against `StripeSubscription` itself through its own autoloader, with every request pinned to
`2026-09-30.endive`. Seven test clocks carried every customer and subscription except the
one-day-trial probe, which needs an off-clock customer, and all seven were deleted afterwards;
the account listed none. Two `stripe listen` processes captured 950 deliveries, 475 per
listener: one at the account's default version (`2025-02-24.acacia`) and one at
`2026-10-28.endive`. Each delivery was parsed on arrival under the real signing secret and under
a wrong one. The key was asserted to be a test key before any call, and none was written down.

**Confirmed, and now facts rather than assumptions:**

- **L5.** After `cancel(NextBillingPeriod)` Stripe sets `cancel_at_period_end=true` **and**
  populates `cancel_at` with the period end. `cancel_at_period_end=false` alone withdraws the
  cancellation and clears `cancel_at`; `cancel_at=''` is never needed, and §2.13 item 10 stands.
  A `cancel_at` set outside the adapter to an explicit mid-period date was also cleared, and
  in flexible billing mode setting or clearing a mid-period `cancel_at` creates prorations. The
  adapter's own `cancel(NextBillingPeriod)` sets it to the period end, so it creates none.
- **L8, a paid renewal.** It raises `invoice.paid` (with `invoice.created`, `invoice.finalized`,
  `invoice.payment_succeeded` and `invoice.updated`), and both listeners delivered it.
- **L8, a declined renewal.** `invoice.payment_failed` arrives for every attempt with the
  invoice `open` and the subscription `past_due`; both parsers map every one to `Pending`, and
  `retrieveStatus()` agrees. On this account dunning cancelled the subscription after 14 days
  (`cancellation_details.reason=payment_failed`) and left the invoice `open` with
  `auto_advance=false`, so `retrieveStatus()` reports `Pending` for ever. §2.8's mapping is
  deliberate, but an application's ledger row for such a renewal never leaves `Pending` unless
  the merchant's revenue-recovery settings mark the invoice uncollectible or void.
- **L9.** `changePlan(ProratedImmediately, PreventChange)` on a declining card is refused with
  HTTP 402. The subscription keeps its old item and price, no invoice or pending item is left
  behind, and the product the call created is archived.
- **L10.** Real signatures verified in both parsers with **0 failures in 950 deliveries**, and
  the wrong secret was refused **1,900 of 1,900 times** with the signature message, never a
  tolerance failure. Every failure of any kind was a `CheckoutException`, and the capture
  server logged no PHP warning. `customer.subscription.*` (128 deliveries, trialing and deleted
  included) and `invoice.*` (282) parsed in both shapes: item-level `current_period_end` read in
  every one, `subscriptionReferenceOf()` found a subscription in every invoice, and
  `paymentReference` is null in the newer shape because the webhook carries no `payments`.
  Events outside the two parsers' remit are refused with the routing message, as designed.
- **A zero-total resume invoice.** Resuming straight after a same-second pause returned `Active`
  with the invoice `paid` for zero and no `/pay` attempted: nothing was left paused. The
  docblock's warning that a resume can return with the status still `Paused` is a safe hedge
  with no live evidence behind it. (That the `/resume` response itself said `active` is
  inferred from the invoice's state, not separately captured.)
- **A reused idempotency key on `changePlan()`.** The retry of a change that succeeded is
  refused with HTTP 400, `error.type=idempotency_error`, **no `error.code`**, and the message
  "Keys for idempotent requests can only be used with the same parameters they were first used
  with. Try using a key other than '…' …". The subscription kept its one new item, nothing was
  charged twice, and the product was archived again. §2.13 item 7 holds. The message's own
  advice is the one the docblock forbids; the adapter now recognises it in code (§2.13 item 7).
- **A quantity-only `changePlan()` on the same price** is accepted as a whole-set replacement
  and prorates exactly: the unused half of the old quantity is credited and the remaining half
  of the new is charged. The subscription item id changes, so anything keyed on the `si_` sees a
  new one.
- **The nested `expand[]=invoice.payments` on a session `GET`.** Stripe honours the two-level
  expansion, each entry carries `status`, and `retrieveStatus()` and `refund()` of a `cs_` read
  and refund the PaymentIntent behind its invoice. A partial refund of 500 succeeded.
- **The happy paths.** An inline and a catalogue `createCheckout()` (amount zero with a trial,
  the plan's price without), a completed signup (`billing_mode=flexible`, `metadata.reference`
  set), `retrieveSubscription()`, `cancel(Immediately)` (no refund and no credit, confirming
  §2.10), `pause()` (with its pending proration credit) and a keyed inline `changePlan()` all
  behaved as specified. The unkeyed path and the `ProratedNextBillingPeriod` and `DoNotBill`
  modes were not exercised live. Checkout's `product_data` products are created inactive and are
  absent from `GET /products`, so a merchant auditing the catalogue through the API will not see
  them.

**The gate ran before the fixes.** It exercised the code at `42316de`. The five fixes below were
written afterwards, test-first, and are covered by mocked tests only; they have not been
re-driven against Stripe. The first of them is the one that most deserves a re-run.

**Found live, which no mock written from the documentation caught, and fixed before tagging:**

1. **A declined resume payment is not "still paused".** Stripe resumes on the payment
   *attempt*. After the declined `/pay`, the subscription was `past_due` with a new billing
   period, and the invoice was `open` with `auto_advance=false` and no
   `next_payment_attempt`. Stripe never retried it, and both retries the old message advised
   were refused ("You can only resume a subscription if it is `paused`"). Left alone the
   invoice stays open indefinitely, a renewal invoice raised at the period end went into
   dunning, and the subscription was cancelled with the resume invoice still `open`. Paying
   that invoice once the card was fixed recovered the subscription. **Fix:** any failure of
   the `/pay` re-reads the subscription, and only a raw `past_due` earns a
   `CheckoutException` (previous chained) saying Stripe accepted the resume but could not collect
   the invoice, that Stripe will not retry it, that `resume()` will be refused, and where to pay
   it (`hosted_invoice_url`, when Stripe supplies one; not observed live). Any other status, or
   a failed re-read, gets a message that makes no claim about the charge, because the failure
   alone cannot say whether the card was charged. A transport failure keeps its own "confirm
   with `retrieveSubscription()`" message. §2.2 is corrected.
2. **A `past_due` subscription is billed at its period end.** The one a failed resume leaves
   behind had a new `subscription_cycle` invoice raised and attempted at the end of its period.
   The adapter reported `nextBilledAt` as null. **Fix:** `nextBilledAt` is the period end for a
   raw status of `active`, `trialing` or `past_due` with nothing scheduled, and stays null for
   `incomplete` and `unpaid`, which also map to `PastDue` but were not observed. On this
   account an ordinary failed renewal is cancelled by dunning before it reaches its period end.
3. **A one-day trial is accepted.** Stripe ran a 24-hour trial for an off-clock customer. The
   two-day minimum seen in the spike applies only to a customer on a test clock. **Fix:** the
   constructor guard is gone, and a `Day` trial of any length from one is sent as it is. §2.5
   is corrected.
4. **`invoice.upcoming` has no `id`.** Stripe delivers it before every renewal, and its object
   carries none, so `parseCallback()` raised "Stripe response was missing the expected "id"
   field" on an event the docblock tells applications to route there. **Fix:** it is refused by
   name, with a message saying it previews an invoice that does not exist yet, so there is no
   transaction to record. Any other invoice object without a string `id` is refused with its
   own malformed-payload message.
5. **A declined attempt is `open`, not `canceled`.** On every declined invoice Stripe listed the
   declined PaymentIntent with the invoice payment's status `open`, so "paid, then processing,
   never canceled" reported a payment that never collected anything. Stripe refused a refund of
   it and the `in_` route was refused locally, so no money was at risk. **Fix:** `paymentReference`
   comes only from an invoice payment whose status is `paid`, and the legacy top-level
   `payment_intent` is read only when the invoice itself is `paid`. §2.13 item 12 is corrected.

**One docblock corrected, not a defect.** A pause that outlasted the period, followed by
`resume(ContinueExistingBillingPeriod)`, raised **no invoice at resume**. Stripe created a
pending proration for the resumed time and billed it, with the pause credit, at the next
renewal, so `resume()` correctly had nothing to pay. §2.2 and the docblock now say exactly that.

**Not exercisable.** An asynchronous first-payment failure could not be produced: the adapter
sends no `payment_method_types`, and a subscription session built exactly as it builds one offered
only `card` and `link` on this account, in USD and in EUR. Neither is asynchronous, and SEPA
Direct Debit and ACH are not enabled. §2.8's `async_payment_failed` mapping therefore stays
unverified live.

### 5.4 The release checklist

This follows `ReleasePolicy.md`'s numbering and records what was actually done. Items done in this
repository before the tag are ticked. Items that can only happen at or after the tag, or in
another repository, are marked outstanding, and a post-tag commit records them rather than this
document claiming them in advance.

1. **CHANGELOG** cut to `[1.11.0] - 2026-11-17`, with an empty `[Unreleased]` above it. ✔
2. **`composer.json` reviewed.** The PHP floor and dependencies are unchanged. The `dev-main`
   branch alias was stale at `1.7.x-dev` and is now `1.11.x-dev`. ✔
3. **Full suite green:** 1394 tests, 3087 assertions, locally on PHP 8.5. CI runs PHP 8.2–8.5
   on the release PR. ✔
4. **`php mitosis health` against a fresh `create-project` resolving the tagged `1.11.0` from
   Packagist.** Outstanding, because it can only run once the tag exists.
5. **`.gitattributes` export-ignore confirmed** against `git archive` of the release commit. The
   archive holds `src`, `composer.json`, `CHANGELOG.md`, `LICENSE`, `README.md`,
   `phpunit.xml.dist` and `.github/`, with no `resources/` and no `CLAUDE.md`. `.github/`
   (the CI workflow) ships in the archive. It is not on the policy's exclusion list and is
   harmless, so it is left as it is. ✔
6. **Tag `v1.11.0` pushed, Packagist updated, and a GitHub Release published.**
   Outstanding at this commit. Note that v1.9.0, v1.9.1 and v1.10.0 were tagged without
   GitHub Releases, so the website's `/changelog`, which keys on published Releases, likely
   skips them.
7. **Website documentation (`monad-www`).** This release is user-facing: a new adapter, and
   changed exception text for every Checkout adapter. So a `monad-www` change is required
   before the tag. Outstanding.
8. **Skeleton mirrors.** `RepoMap.md` changed in this release, so the skeleton's mirror needs
   syncing. `CrossRepoContracts.md` did not change. Outstanding.
9. **README Status line** bumped to `1.11.0` in this repository ✔. The skeleton's README is
   outstanding.
