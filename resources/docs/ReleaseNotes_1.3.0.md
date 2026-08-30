# Monad Clarity 1.3.0 Release Notes

**Status:** FROZEN — canonical specification for the 1.3.0 release.
This document is the source of truth for WHAT ships in 1.3.0. It does not restate 1.0.0 or
1.2.0; `ReleaseNotes_1.0.0.md` and `ReleaseNotes_1.2.0.md` remain frozen for what they
specified.

> **Scope note.** 1.2.0 shipped the Checkout facade, the transaction ledger, and the first
> adapter. 1.3.0 adds a second adapter and nothing else. The facade is untouched: no method
> signature changed, no value object gained or lost a property, and no table definition
> moved. This is the release that proves the 1.2.0 design carries a second gateway without
> bending.

## 1. What ships in 1.3.0

### 1.1 Paddle Billing adapter

`Monad\Clarity\Services\CheckoutAdapters\PaddleCheckout` — one-time payments through
Paddle's Transactions API, refunds through Adjustments, and callbacks through Paddle's
signed notification scheme.

| Method | How Paddle serves it |
| --- | --- |
| `createCheckout()` | `POST /transactions` with non-catalog inline prices and products |
| `retrieveStatus()` | `GET /transactions/{id}` |
| `parseCallback()` | `Paddle-Signature` verification, then `transaction.*` events only |
| `refund()` | `POST /adjustments` with `action: refund` |

Speaks Paddle's JSON REST API through the existing `Services\HttpClient`. **No new Composer
dependency**: signature verification uses `Utils\HMAC` and `Utils\ConstantTime`, exactly as
`StripeCheckout` does, and `paddle/paddle-php-sdk` is deliberately not pulled in.

Items go on the wire as **non-catalog** inline `price` + `product` objects. A Monad
application therefore never has to seed a Paddle catalogue before it can take a payment, and
`CheckoutRequest` remains the whole description of the sale. No `billing_cycle` is ever sent
— its absence is what keeps a price one-time rather than a subscription.

Because Paddle is the merchant of record, every product it bills carries a **tax category**
deciding how the sale is taxed. The adapter's `$taxCategory` argument defaults to `standard`,
which is right for ordinary goods and services and wrong for ebooks, SaaS, and software — each
of which has a category of its own that a merchant selling them must pass. The sandbox is
reached the same way, through `$baseUri`; it shares no keys, catalogue, or notification
destinations with live.

Amounts need no conversion in either direction. Paddle states them as strings in the
currency's lowest denomination, which is exactly what `Money` holds, so the zero-decimal
currencies (JPY, KRW, CLP) fall out correctly with no lookup table — the property §2.2 of
the 1.2.0 notes was designed for, now confirmed against a second gateway.

## 2. Resolved specification decisions

### 2.1 The §9 gateway roster is illustrative, not closed

`ReleaseNotes_1.0.0.md` §9.3 names seven gateways and §9.4 adds two more by example. Every
document since has restated that as a closed set of nine, and `ReleasePolicy.md`'s
reservation section listed the eight unbuilt ones by name. Paddle is not among them, so
adding it needed a decision rather than an assumption.

**Decided: the roster is illustrative.** §9.2 specifies an abstraction layer for "**various**
payment gateways", and §9.4's enumeration of adapter namespaces ends in "etc." — the frozen
spec names the gateways that were wanted at the time, not a set that excludes every other.
What §9 actually fixes is the *shape*: one class per gateway, its own namespace under
`Services\CheckoutAdapters`, built end to end or not at all.

Recorded here because `ReleaseNotes_1.0.0.md` is frozen and cannot carry it — the same route
1.2.0 took for the three §9 gaps it resolved.

Consequently `ReleasePolicy.md`'s reservation section now states the rule ("every unbuilt
adapter namespace") and lists the currently-reserved names as a current fact rather than a
closed set. Nothing about the rule itself changes: an unbuilt adapter is still an absent
file, and each still ships in its own minor when built end to end.

### 2.2 Paddle has no gateway-hosted page, so the adapter has two modes

Paddle renders checkout through Paddle.js rather than a page it hosts for you by default, so
`CheckoutSession::$redirectUrl` cannot be composed one single way. The adapter takes exactly
one of two constructor arguments, and refuses to create a checkout with neither or both:

- **`$hostedCheckoutUrl`** — the link copied from Paddle > Checkout > Hosted checkout, with
  `transaction_id` appended. Paddle hosts the page; nothing is needed on the merchant's side.
  Its post-payment redirect is configured on the link itself, so `CheckoutRequest`'s
  `successUrl` and `cancelUrl` cannot be honoured per checkout in this mode — they travel in
  `custom_data` instead, where a reconciliation handler can still read them.
- **`$paymentPageUrl`** — the merchant's own approved page running Paddle.js. The adapter
  sets it as the transaction's `checkout.url` override, so one Paddle account can serve
  several applications, and appends `success_url` and `cancel_url` to the returned link for
  the page's own `Paddle.Checkout.open()` call to pass on. This mode honours the whole
  `CheckoutRequest` contract.

Neither mode is §9.5. §9.5 is a checkout page whose *payment fields* the merchant renders;
both modes above are still Paddle's fields on Paddle's iframe. §9.5 remains open.

### 2.3 Paddle supports no idempotency keys, and the adapter says so rather than pretending

Stripe takes an `Idempotency-Key` header on every write. Paddle takes one on none, so
`CheckoutRequest::idempotencyKey()` is carried in `custom_data` for audit and cannot be
enforced. A replayed `createCheckout()` creates a second Paddle transaction — a draft, so no
money moves, but it is not the Stripe behaviour and a caller must not retry blindly.

For refunds, where a blind retry costs real money, the adapter guards what it can: before
posting an adjustment it reads the transaction's line items and every adjustment already
made against it, and refuses a refund that would exceed what is left. That catches
over-refunds. It does **not** deduplicate an identical retried refund, and the ledger's
`uq_checkout_refunds_gateway_refund_id` index only recognises a duplicate after Paddle has
already accepted it.

This is a genuine capability gap between the two gateways, recorded here so an application
choosing between them can see it before it matters rather than after.

### 2.4 A Paddle refund is asynchronous, and stays a record rather than a status

Paddle reviews live refunds: an adjustment is created `pending_approval` and reaches
`approved` or `rejected` later, reported by an `adjustment.updated` webhook. Sandbox
approves automatically, which is exactly the difference that hides this in testing.

`RefundResult::$status` already carries the gateway's own refund state verbatim — §2.1 of the
1.2.0 notes settled that a refund's lifecycle is not a transaction's — so `pending_approval`
needs no contract change and `checkout_refunds.status` stores it as it is. The transaction
itself is untouched, exactly as a Stripe refund leaves it.

Tracking an adjustment from `pending_approval` to its terminal state is **not** in this
release. It would need a fifth abstract method on the facade, which breaks every downstream
adapter and is therefore semver-major. See §4.

## 3. Explicitly NOT in 1.3.0

- **Subscriptions.** Paddle Billing's subscription surface is substantial and §9 never asks
  for one. Checkout deals in one-time payments; the adapter's inline prices carry no
  `billing_cycle`, so a subscription cannot be created by accident.
- **Refund outcome reconciliation** — see §2.4. `parseCallback()` refuses `adjustment.*`
  events rather than half-interpreting them.
- **§9.5 — custom checkout page** and **§9.6.7 — built-in report generation.** Both were
  open after 1.2.0 and both remain open.
- **Eight of the nine gateways in §9.3/§9.4.** `StripeConnectExpress`, `Fiuu`, `iPay88`,
  `BillPlz`, `Adyen`, `Airwallex`, `HitPay`, and `Xendit` are unbuilt. Their namespaces
  remain reserved, and per `CLAUDE.md`'s no-partial-implementation rule an unbuilt adapter is
  an absent file, never a placeholder class.

## 4. Compatibility

Semver **minor**. Every change is additive:

- New class `Services\CheckoutAdapters\PaddleCheckout`, in a namespace that already existed.
- No method signature on `Services\Checkout` or any `Services\Checkout\*` value object
  changed, gained, or lost anything. A 1.2.0 application upgrades with no edit.
- No setup-owned table DDL changed; the checkout tables are unchanged, and the `gateway`
  column stored `paddle_checkout` without alteration because it was never an enum.
- No entry-point or `Console::run()` signature changed, no `mitosis` command added or
  renamed, no new Composer dependency.

The one change to a shipped test double: `Tests\Services\CheckoutAdapters\FakeHttpClient`
gains `decodedLastRequestBody()` beside `decodedLastRequestForm()`, because Paddle is JSON
where Stripe is form-encoded and `parse_str` does not fail on JSON — it returns one nonsense
key, so a JSON body run through the form decoder would produce assertions that are quietly
wrong rather than red. `resources/` is export-ignored from the dist, so this is invisible to
consumers.

## 5. Verification

- **51 tests** for the adapter, alongside the existing suite. Adapter tests mock
  `HttpClient` — no live gateway calls in the automated suite, matching `TestingStrategy.md`
  Tier 4's rule for LLM adapters, which this release also writes into that document rather
  than leaving asserted only here.
- The security-critical callback path is covered adversarially per Tier 1: valid signature,
  tampered payload, wrong signing secret, stale timestamp outside the replay tolerance,
  malformed and absent signature headers, a missing configured secret, case-insensitive
  header matching, and secret rotation with several signatures on one header.
- Both event guards are tested: a non-`transaction.*` event and a `transaction.*` event
  carrying something other than a `txn_` id are each refused by name. This is the 1.2.0
  lesson applied before it could bite — a Paddle notification destination delivers every
  event type subscribed on it, exactly as a Stripe endpoint does.
- The partial-refund allocator is tested against a single item, across several items, with an
  earlier refund subtracted, with a rejected earlier refund correctly ignored, and against
  an over-refund and a currency mismatch.

### 5.1 What the sandbox run established

Run against a live sandbox account, driving the adapter itself rather than a script written
to agree with it. Confirmed end to end:

- Authentication and the `Paddle-Version: 1` header are accepted as the adapter sends them.
- **`createCheckout()` against the real API.** Non-catalog inline prices and products are
  accepted exactly as the adapter shapes them; the created transaction reads back with
  `status: draft` (→ `pending`), quantities honoured (2 × 1000 and 1 × 500 giving line totals
  of 2000 and 500 against a `grand_total` of 2500), and `paymentReference` correctly null.
- **`custom_data` round-trips**, with the adapter's own keys intact beside caller metadata —
  the merge order that protects the merchant reference does what §2.3 claims.
- **A zero-decimal amount is unscaled.** ¥1,200 sent as `"1200"` came back as `1200`. The
  decision in §2.2 of the 1.2.0 notes — `Money` holds minor units and nothing converts —
  survives contact with a second gateway's string amounts.
- **`retrieveStatus()` round-trips**, reading the amount from `details.totals.grand_total`.
- The account default payment link composes `checkout.url` as `<default>?_ptxn=<txn id>`,
  which is the shape the `$paymentPageUrl` mode's fallback assumes.
- The ten `transaction.*` event types Paddle actually publishes include one the adapter was
  not written against by name, `transaction.revised`. It needs no special case — it falls to
  the default branch and maps from `data.status`, which is the branch's purpose.
- **`GET /adjustments?transaction_id=` genuinely filters** — one row for the transaction that
  has an adjustment, zero for another — and rejects a malformed id with `400 bad_request`
  rather than silently returning everything. The over-refund guard rests on that filter.
- **A completed payment, made with a sandbox test card through Paddle.js on `localhost`.**
  Paddle.js accepts localhost for a sandbox client token, which is worth noting beside §5.2's
  second prerequisite: Paddle.js domain handling and the server-side `checkout.url` allowlist
  are **different checks**, and a page that Paddle.js will happily render is not necessarily a
  URL the Transactions API will accept as an override.
- **`retrieveStatus()` on the completed transaction** maps `completed` → `success`, reads
  `2500 GBP`, and leaves `failureReason` null against a `captured` payment.
- **`items[].amount` on an adjustment is measured against a line item's tax-inclusive
  `totals.total`, not its `subtotal`.** This was the open question most likely to fail, and it
  resolves in the allocator's favour. On a VAT-bearing line item of subtotal 1667 / tax 333 /
  total 2000, a refund of **1800** — above the subtotal, below the total — was accepted, and
  Paddle decomposed it internally into subtotal 1500 + tax 300. Capping each item at
  `totals.total` is correct as written. Note this is only observable on a taxed transaction:
  until VAT applied, the two figures were equal and no test could tell them apart.
- **A live refund really is created `pending_approval`**, confirming §2.4 against the API
  rather than against the documentation.
- **The over-refund guard fires on real data**, and counts a still-pending refund against the
  remaining balance — the conservative direction, and the right one.
- **Real Paddle-generated webhook signatures verify**, driven through a Cloudflare tunnel to
  a notification destination subscribed to `transaction.*` *and* several non-transaction event
  types. A genuine `transaction.created` delivery parsed — proving the semicolon-delimited
  `ts=`/`h1=` header format, the `ts:body` signed payload, and byte-exact raw-body handling
  against traffic Paddle actually signed. A genuine `customer.created` delivery was refused by
  the event guard by name. **Negative control:** the same live deliveries, replayed with only
  the signing secret changed, were refused with "did not verify" — so a pass means the
  signature was checked, not that checking was skipped. In that run `customer.created` was
  refused at the *signature* stage rather than the event guard, confirming the documented
  ordering: verification happens before any parsing. This is the one property the mocked suite
  structurally cannot establish, since it signs with the same helper it verifies with.
- **The partial-refund allocator spans line items correctly against real prior refunds.** With
  1800 already taken off a 2000 line item, a second refund of 700 was allocated 200 to that
  item's remainder and 500 to the next — the exact split the allocator computes, accepted by
  Paddle, and the path that depends on the paginated adjustments read. A further refund of 1
  was then refused as exceeding the remaining balance "by 1 minor units", so the guard is
  exact to the unit rather than approximately right: 1800 + 700 against a 2500 transaction
  leaves nothing.

And one defect it found that the mocked suite structurally could not, which is the whole
argument for running it:

> **`/adjustments` is paginated ten to a page**, and `refundedByItem()` read only the first.
> On a transaction with more than ten adjustments the over-refund guard would have
> under-counted what was already refunded and permitted the very over-refund it exists to
> stop. Fixed by paginating every list read — `per_page=200` and following the cursor while
> `meta.pagination.has_more`. A page size the fixtures never returned was invisible to 49
> passing tests.

One further behaviour the run turned up, which no amount of reading would have predicted:

> **While a refund is awaiting approval, Paddle refuses every further adjustment against that
> transaction** — `adjustment_pending_refund_request`. Since live refunds are created
> `pending_approval` and reviewed by Paddle, partial refunds on one transaction are serialised
> behind that review rather than issuable back to back. It is an operational constraint on the
> merchant, not a defect in the adapter, and it is recorded on `refund()` so it does not have
> to be rediscovered in production. Paddle's own message states the reason in plain English,
> so the adapter surfaces it as-is rather than special-casing one error code.

### 5.2 Two account prerequisites the run uncovered

Neither is application configuration, and neither can be set through the API. Both are in
`DeploymentTopology.md` beside the outbound hosts, because an application that skips them
fails at `createCheckout()` rather than at the redirect.

1. **A default payment link must be set on the account** (Paddle > Checkout > Checkout
   settings). Without one Paddle refuses transaction creation account-wide —
   `400 transaction_default_checkout_url_not_set` — on `collection_mode: automatic` and
   `manual` alike, **and with the per-transaction `checkout.url` override present**. The
   override does not substitute for the account setting; it only redirects a transaction once
   the account has one at all.
2. **`$paymentPageUrl`'s domain must be approved** (Paddle > Checkout > Website approval),
   in sandbox as much as in live. Paddle validates the override against the approved list and
   rejects anything else with `transaction_checkout_url_domain_is_not_approved` — including,
   in this run, a domain that was already serving as the account's default payment link.
   Worth stating plainly because the widely repeated claim that sandbox approves any domain
   automatically is wrong for this endpoint.

### 5.3 How the payment was reached

Refunds act on a **completed** transaction, and there is no API route to one: Paddle Billing
is a merchant of record, so card details only ever enter Paddle's own iframe. The invoice
route that would have avoided a browser is closed by Paddle's account rules —
`collection_mode: manual` was refused with
`transaction_customer_not_suitable_for_collection_mode` even with a customer, address, and
business entity attached.

So the payment was made through Paddle.js on a locally served page, with a sandbox test card
and a UK address. The UK address is the load-bearing part: without VAT a line item's
`subtotal` and `total` are equal, and the units question above cannot be answered at all.
Anyone repeating this verification should use a taxed jurisdiction for the same reason.

**Outstanding before tagging** (see `ReleasePolicy.md` § Packagist publication checklist):

- **One question still open**: whether a hosted checkout link accepts a `transaction_id` for a
  transaction whose `checkout.url` was not pointed at that link. The parameter is documented;
  the interaction is not. The live run is suggestive but not conclusive — `Paddle.Checkout.open`
  opened a checkout for an API-created transaction with no override set, which is the same
  pairing a hosted link would make. If the two do turn out to be exclusive, the hosted mode
  sets the override to the hosted link and the rest of the adapter is unaffected.
- **`RepoMap.md`'s skeleton mirror** — checklist item 8. This release edits the canonical copy
  (the new adapter in the tree and in the structural notes), so the mirror now drifts until it
  is synced. `CrossRepoContracts.md` is untouched, so its §10 procedure is not triggered.
- **Website documentation** — checklist item 7. Paddle is user-facing, so a `monad-www` page
  and its hardcoded nav entry need merging before the tag.

This is a payment integration; mocked coverage is not evidence that it works.
