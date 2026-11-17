# Monad Clarity 1.12.0 Release Notes

**Status:** DRAFT. It becomes FROZEN when `v1.12.0` is tagged. The live verification (§5.1) is
already done; §5.3, the release checklist, is ticked at tagging.

This document is the source of truth for WHAT ships in 1.12.0. It does not restate 1.0.0 through
1.11.1; those remain frozen for what they specified.

> **Scope note.** 1.11.0 taught `StripeSubscription::changePlan()` to recognise Stripe's refusal
> of a reused idempotency key and explain it. But it threw a plain `CheckoutException`, so a
> caller could tell that refusal apart only by reading the message, which is prose. It also
> recorded that a typed exception would need `CheckoutException` to stop being `final`. 1.12.0
> does that:
> - **`Services\Checkout\IdempotencyKeyReusedException`** is a final subclass of
>   `CheckoutException` that carries the key.
> - **Every Stripe call that sends a key throws it**, not only `changePlan()`.
>
> Nothing else changes: no signature, no table, no command, no dependency.

## 1. What ships in 1.12.0

### 1.1 `IdempotencyKeyReusedException`

```php
final class IdempotencyKeyReusedException extends CheckoutException
{
    public function __construct(string $message, public readonly string $idempotencyKey, ?Throwable $previous = null);
}
```

A gateway refused a request because its idempotency key had already been used on a different
request: different parameters, or a different endpoint. The refused request was not carried out,
so nothing was created, charged or refunded by it. `$idempotencyKey` is the key as the caller
knows it (§2.6).

```php
try {
    $session = $stripe->createCheckout($request);
} catch (IdempotencyKeyReusedException $e) {
    // $e->idempotencyKey, and a message saying what is safe to do next
} catch (CheckoutException $e) {
    // every other failure, exactly as before
}
```

### 1.2 Where it is thrown

| Call | Key sent | What the refusal means | Safe next step, as the message says |
|---|---|---|---|
| `StripeCheckout::createCheckout()` | `CheckoutRequest::idempotencyKey()`, the order reference unless one is passed | the reference was already used for a different session, usually after the cart changed | a new key, which charges nothing; but the earlier session stays payable until it expires, so the order must accept only one payment |
| `StripeCheckout::refund()` | `RefundRequest::idempotencyKey()`, `refund:{reference}:{amount or "full"}` unless one is passed | a refund of that amount on that payment was already requested with other details | check the refunds already made first; a new key can refund twice |
| `StripeSubscription::createCheckout()` | as `StripeCheckout` | as `StripeCheckout` | as `StripeCheckout` |
| `StripeSubscription::refund()` | as `StripeCheckout` | as `StripeCheckout` | as `StripeCheckout` |
| `StripeSubscription::changePlan()` | `$idempotencyKey`, when given, and keys derived from it for the products it creates | an earlier attempt under the key already went through | `retrieveSubscription()` first; never a new key unless that shows the change was not made |

`changePlan()`'s message is the one 1.11.0 wrote (`ReleaseNotes_1.11.0.md` §2.13 item 7). Only
its class changes.

### 1.3 What does not change

- **`CheckoutException`'s catch scope.** Every `catch (CheckoutException $e)` catches the new
  type, so code written for 1.11.1 behaves the same.
- **The Paddle adapters.** Paddle accepts no idempotency keys (`ReleaseNotes_1.3.0.md` §2.3), so
  they never throw it.
- **Every other Stripe failure.** It is still a plain `CheckoutException`, with the 1.11.0
  redacted message.

## 2. Decisions this release records

### 2.1 A subclass, not a property or a code

Three shapes could let a caller tell this refusal apart:
- **A subclass.**
- **A reason property on `CheckoutException`,** as `MailException` carries `FailureScope`.
- **An exception code.**

A code is an integer with no name. A reason property suits a classification that every failure
has: every mail failure is either the mailer's fault or the message's, and failover needs to
know which. This refusal is one specific condition, and it carries a datum the others don't, the
key. A subclass names the condition where the caller catches it. Its `$idempotencyKey` is typed
and never null.

### 2.2 `CheckoutException` is no longer `final`, and that is one-way

Every exception in Clarity was `final`. `CheckoutException` is now the one exception that isn't,
because a subclass needs it open. Removing `final` adds a capability and breaks nothing.
Restoring it would break any subclass written since, so it is never restored outside a major
release.

**Subclassing it is supported.** For example, a custom adapter can refine it for a condition of
its own gateway, as Clarity does here. The classes Clarity throws are `CheckoutException` and
`IdempotencyKeyReusedException`, and only the latter is final.

### 2.3 The subclass is `final`

Nothing in Clarity refines it further, and a final class can be opened later in a minor release,
just as `CheckoutException` was here. An open class can never be closed again before a major.

### 2.4 Every keyed Stripe call throws it, not only `changePlan()`

A type that only `changePlan()` throws would be a trap: a caller catching it around `refund()`
would never see it, and would not know they hadn't. The most common occasion is in fact
`createCheckout()`. `CheckoutRequest`'s key defaults to the order reference (1.2.0), so asking
for a session again for an order whose cart changed reuses the key with different parameters.

The keyed calls on both Stripe adapters now go through the `SpeaksStripe` trait's
`sendKeyed()`. `changePlan()` keeps its own `sendUnderKey()`, because its key is optional and a
key it derives for a product is reported as the caller's (§2.6). Both recognise the refusal.

`send()` no longer takes a key, so a keyed call has to choose one of those two. PHP drops an
extra argument silently, though, so a key handed to `send()` would never be sent rather than
being refused. What guards that is a test asserting the `Idempotency-Key` header, which every
keyed call already has.

### 2.5 Each call's message gives the advice that is safe for that call

Stripe's own message says "Try using a key other than '…' if you meant to execute a different
request." How safe that advice is depends on the call:

- **`createCheckout()`:** a new key only creates another session, which charges nothing, so the
  advice mostly holds. But the earlier session stays open and payable until it expires, so a
  customer holding both links could pay twice. The message says a new key is the way forward and
  says the order must accept only one payment; it does not call the new key safe. The adapter
  cannot expire the earlier session for the caller: `Services\Checkout` has no such operation,
  and adding one is not this release's business.
- **`refund()`:** a new key is a new refund, and it can refund the customer twice. The message
  says to check the refunds already made on that payment first.
- **`changePlan()`:** a new key can repeat the change and its charge. The message says to read
  the subscription first (1.11.0).

So the message is the adapter's, never Stripe's. The two texts `sendKeyed()` uses are trait
constants, so `StripeCheckout` and `StripeSubscription` cannot word the same situation
differently.

### 2.6 The key reported is the one the caller can act on

- **When the caller passed a key:** `$idempotencyKey` is that key.
- **When they didn't:** it is the key the request derived, because that is the key Stripe saw.
  For a checkout it is the order reference, and for a refund `refund:{reference}:{amount}`. The
  property's docblock says so, and each message says how the key was chosen, so a caller who
  never passed a key is not left wondering where it came from.
- **A product `changePlan()` creates:** it is sent under `{key}:product:{index}`, but a refusal
  there reports `{key}`, the one the caller passed and can retry or investigate with.

### 2.7 Recognised by structure, and a 409 is not this refusal

Recognition is HTTP 400 with an `error.type` of `idempotency_error`, read from the body's
structure and never from its prose, unchanged from 1.11.0. A 409 means another request under
the same key is still in flight. That is a different situation: retry the same key later, and
don't switch keys. So a 409 stays a plain `CheckoutException` whatever its `error.type`. Stripe's
409 body was not reproduced live (§5.1), so this is pinned by a mocked test only.

## 3. Explicitly NOT in 1.12.0

- **Typed exceptions for other failures.** A card decline, a network failure and an invalid
  request each remain a `CheckoutException`. Each would be its own decision, taken when a real
  call site needs one; 2.1's reasoning does not make them free.
- **A gateway-agnostic retry hint** such as `isRetryable()`. It is the same kind of
  classification §2.1 declined, and no caller has asked for it.
- **Everything `ReleaseNotes_1.11.0.md` §3 left open,** except its two patch defects, which
  shipped in 1.11.1.

## 4. Compatibility

Semver **minor**. Two kinds of change are visible.

- **The class of one failure.** Stripe's reused-key refusal is now an
  `IdempotencyKeyReusedException`. It is still a `CheckoutException`, so no catch changes what it
  catches. Only code that compares an exception's exact class, for example
  `$e::class === CheckoutException::class`, sees a difference.
- **The message text of one failure.** On `createCheckout()` and `refund()` of both Stripe
  adapters, the refusal used to read as `assertSuccessful()`'s generic
  `'<adapter> request failed with HTTP 400: …'` summary of Stripe's error. It now reads as
  §1.2's adapter-written explanation. Exception text was never a documented contract (1.11.0
  §4), and the old text advised a fresh key, which for a refund was unsafe.

Otherwise:

- **`CheckoutException`:** no longer `final` (§2.2). Its constructor and its catch scope are
  unchanged.
- **Signatures:** no public method signature changed. The `SpeaksStripe` trait's `send()` lost
  its private key parameter and `sendKeyed()` was added. Both are private, which
  `ReleasePolicy.md` exempts from compatibility.
- **Untouched:** no new table, `mitosis` command, Composer dependency or enum case; no
  entry-point or `Console::run()` change. `CrossRepoContracts.md` is untouched, so its skeleton
  mirror does not drift.
- **`RepoMap.md` drifts.** It gains `IdempotencyKeyReusedException.php`, so its skeleton mirror
  needs `ReleasePolicy.md` checklist item 8's sync.

## 5. Verification

### 5.1 What the live probe established

Run against Stripe test mode on 2026-11-17 with the Stripe CLI, twice: once at each adapter's
pinned API version. Only selected fields were printed, never a secret.
- **`2026-09-30.endive`** (`StripeSubscription`): run id `probe112-1794914214`.
- **`2024-06-20`** (`StripeCheckout`): run id `probe112-1794914875`.

Both runs gave the same results, below.

| | Request | Result |
|---|---|---|
| A | create a checkout session under key K, then again under K with a different amount | first HTTP 200; second **HTTP 400, `error.type` `idempotency_error`**. Only one session carries the run's metadata, so the refused request created nothing. |
| A2 | the first session's request again under K, unchanged | HTTP 200 with the first session's id, a replay rather than a refusal |
| B | refund 100 of a paid PaymentIntent under key K, then 200 under K | first HTTP 200; second **HTTP 400, `idempotency_error`**. The PaymentIntent holds one refund of 100. |
| C | B's key K sent to `POST /v1/customers` | **HTTP 400, `idempotency_error`**, its message naming the other endpoint |

C is why the type and every message say "a different request" rather than "different
parameters". A was the endpoint 1.11.0's gate never exercised; `changePlan()`'s refusal was
established there (`ReleaseNotes_1.11.0.md` §5.3).

Not reproduced: Stripe's 409 for a request still in flight under a key (§2.7), and how long Stripe
keeps a key, which nothing here depends on.

### 5.2 Tests

- **New tests, 8.** They cover:
  - the exact class, the key and the full message for `StripeCheckout`'s `createCheckout()`
    (default and explicit key) and `refund()`;
  - that `StripeCheckout::refund()` sends its derived key, the one keyed call that had no header
    assertion (§2.4);
  - both of `StripeSubscription`'s keyed calls;
  - three failures that must stay exactly a `CheckoutException`: an ordinary 400, a 409
    `idempotency_error`, and a non-JSON 400.
- **Existing tests gained assertions.** The four `changePlan()` reused-key and not-a-reused-key
  tests now assert the exact class, and the key, including that a product's derived key reports
  the caller's. Before this, `expectException(CheckoutException::class)` passed for either class.
  No existing assertion was removed or loosened.

### 5.3 The release checklist

`ReleasePolicy.md`'s Packagist publication checklist, ticked at tagging.
