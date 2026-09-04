# Monad Clarity 1.7.0 Release Notes

**Status:** FROZEN — canonical specification for the 1.7.0 release.
This document is the source of truth for WHAT ships in 1.7.0. It does not restate 1.0.0, 1.2.0,
1.3.0, 1.4.0, 1.5.0 or 1.6.0; those remain frozen for what they specified.

> **Scope note.** 1.7.0 adds no service and no abstraction. It closes a gap inside
> `Services\Checkout` that 1.2.0 through 1.4.0 left open without noticing: `createCheckout()`
> could describe a sale inline but could not name a price the merchant already publishes in the
> gateway's own catalogue. Nothing existing changes — every 1.6.0 call site compiles and behaves
> identically, and an application that never passes a catalogue price is byte-for-byte
> unaffected.

## 1. What ships in 1.7.0

### 1.1 A catalogue-price route into `createCheckout()`

Both Paddle adapters gain a `catalogPriceId` constructor argument. Given one, `createCheckout()`
names that price and says nothing else about it:

```php
$adapter = PaddleSubscription::forCatalogPrice(
    $apiKey, $http, 'pri_01hxxxxxxxxxxxxxxxxxxxxxxx',
    webhookSecret: $secret, paymentPageUrl: 'https://app.example/subscribe',
);

$session = $adapter->createCheckout(new CheckoutRequest(
    reference: 'order-9',
    amount: new Money(0, 'USD'),          // the catalogue owns the price — see §2.3
    successUrl: 'https://app.example/welcome',
    cancelUrl: 'https://app.example/pricing',
));
```

`PaddleCheckout` takes the same argument for one-time sales, as a named argument:
`new PaddleCheckout($key, $http, $secret, paymentPageUrl: $url, catalogPriceId: 'pri_…')`.

The wire body becomes `items: [{price_id, quantity: 1}]` with **no** `currency_code` — the two
differences from inline mode, and §2.2 and §2.3 are why.

### 1.2 `PaddleSubscription::forCatalogPrice()`

A named constructor taking no billing cycle, no trial period and no tax category, because a
catalogue price states all three. It exists because the primary constructor's `$billingCycle` is
positional and required, so catalogue mode would otherwise be spelled with a leading `null`.

### 1.3 What does not change

`Services\Checkout`'s four abstract methods, `CheckoutRequest`, `LineItem`, `Money`,
`CheckoutSession`, `TransactionLedger`, `SubscriptionLedger`, `StripeCheckout`, and every
callback and refund path. No table, no command, no migration.

## 2. Decisions this release records

### 2.1 The gap was real, and it was ours

Reported from a downstream application (Flow, Q-015): `createCheckout()` could not consume a
catalogue price id. Verified in this repo before anything was built — `price_id` appeared in
exactly one place framework-wide, `PaddleSubscription::planItemParams()`, on the `changePlan()`
path. `itemParams()` unconditionally built an inline non-catalog price, and neither
`CheckoutRequest` nor `LineItem` carried an id.

So `SubscriptionItem::catalogPrice()` had, since 1.4.0, let an application **move** a
subscription onto a published plan while giving it no way to **start** one there. An application
billing published plans had to restate every price in its own code — two sources of truth that
disagree the first time a price changes, which is precisely what a catalogue exists to prevent.

Recorded here because `ReleaseNotes_1.0.0.md` §9 is frozen — the route 1.2.0 through 1.6.0 took.

### 2.2 The plan is adapter configuration, which is 1.4.0 §2.4 applied unchanged

`ReleaseNotes_1.4.0.md` §2.4 put the billing cycle on the constructor because `createCheckout()`'s
signature is fixed and `CheckoutRequest` is `final readonly` and semver-frozen, and concluded
that one adapter instance meaning one plan's terms is the honest reading anyway. A catalogue
price id is the same kind of fact about the same thing, so it goes to the same place. A merchant
with three tiers on two cycles constructs six adapters — the shape §2.4 already blessed when it
said a merchant with monthly and annual tiers constructs two.

**`CheckoutRequest` was therefore not reopened.** The alternative — a `catalogPrice()` route on
`LineItem` or a new item list on the request — would have thawed a frozen value object, put a
second mutually-exclusive item list on it, and obliged `StripeCheckout` to ship the same
capability the same day under the no-partial-implementations rule. None of that was needed to
close the gap.

### 2.3 `CheckoutRequest::$amount` is inert in catalogue mode, and the idiom is `Money(0, …)`

`$amount` is required and cannot be made optional. In catalogue mode it is used for nothing: it
is not sent, and `amountOf()`'s fallback never fires because Paddle prices the draft server-side.
So the documented idiom is `new Money(0, $currency)` — read as "the catalogue owns this" — and
the real figure is read back off `CheckoutSession::$amount`.

Passing the true price instead would compile and work, and is exactly the duplication this
release removes. It is called out here so a caller falls into the honest default rather than the
convenient one.

### 2.4 Catalogue mode omits `currency_code`, because Paddle converts rather than refuses

The single most load-bearing finding of the live run, and not what was expected.

Paddle does **not** reject a `currency_code` that disagrees with a catalogue price's own
currency. It silently converts: a USD price of `4900` sent with `currency_code: EUR` was accepted
and came back as `4218`, and as `7668` with `JPY`. Passing `CheckoutRequest::$amount`'s currency
through would therefore let a caller re-denominate the merchant's published price at Paddle's
rate — surfacing as a wrong charge rather than as an error, days later, in a settlement report.

So the field is **omitted entirely** in catalogue mode. With it absent Paddle bills the price
exactly as published, confirmed the same way. Inline mode still sends it, and a test asserts each
half explicitly.

### 2.5 Quantity is fixed at 1, and that is the feature's stated boundary

Quantity is per-checkout data and `CheckoutRequest` has nowhere to carry it (§2.2). Paddle
honours a quantity on a catalogue item — confirmed live, `quantity: 3` billed `14700` against a
`4900` price — so this is a boundary, not a gateway limit.

Reading it off `$lineItems` would be worse than not supporting it: a `LineItem` exists to state a
unit price, so a caller would have to restate the catalogue's price in order to communicate a
number unrelated to it. A seat count is set after the fact through `changePlan()` with
`SubscriptionItem::catalogPrice($priceId, $quantity)`, which has carried a quantity since 1.4.0.

### 2.6 Contradictions are refused at construction, not resolved

A `$billingCycle` **and** a `$catalogPriceId` is two answers to "how often does this charge", and
Paddle would honour the catalogue's while the constructor argument said otherwise. Neither is an
adapter that cannot say what it bills. Both throw, as does a `$trialPeriod` passed alongside a
catalogue price — that one is refused rather than ignored because a silently dropped trial is
discovered as a customer charged on day one of a fourteen-day trial.

A `CheckoutRequest` carrying `lineItems` in catalogue mode is refused at `createCheckout()` for
the same reason: honouring either answer would silently discard the other.

A `pro_` id gets its own message. The Paddle dashboard shows a product's id beside its prices,
and the API's own error for one names an entity the merchant can see plainly exists.

### 2.7 Live sandbox verification

Run against a real sandbox catalogue of three plans × two cycles, through the adapter rather than
by hand:

- **All six prices started a subscription transaction.** Each returned a `txn_` at `pending`,
  Paddle resolving name, amount, cycle, trial and tax category from the catalogue — e.g. the
  annual Pro plan came back `99000 USD`, `{year, 1}`, 14-day trial, none of which the caller sent.
- **The currency guard holds.** Every request passed `Money(0, 'EUR')`; every transaction came
  back `USD`, the catalogue's own.
- **`grand_total: "0"` on all six is correct, not a defect** — every plan carries a 14-day trial,
  which is `ReleaseNotes_1.4.0.md` §326's zero-amount-trial finding reproduced. Confirmed by
  building a trial-free price, which priced correctly at `4900` (and `14700` at quantity 3) and
  was archived afterwards.
- **`custom_data` is unchanged** — reference, idempotency key and both URLs travel as in 1.3.0.

## 3. Acceptance gate

- [x] `createCheckout()` accepts a catalogue price id on both Paddle adapters.
- [x] Catalogue mode sends `price_id` and no inline price, cycle, trial or currency.
- [x] Contradictory construction and contradictory requests are refused with named messages.
- [x] Every 1.6.0 call site is unaffected — the full suite passes unchanged.
- [x] Tests written in the same phase, including both halves of the currency rule.
- [x] Verified against the live Paddle sandbox on all six catalogue prices.
