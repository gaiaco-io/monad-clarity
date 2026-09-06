# Monad Clarity 1.8.0 Release Notes

**Status:** FROZEN — canonical specification for the 1.8.0 release.
This document is the source of truth for WHAT ships in 1.8.0. It does not restate 1.0.0, 1.2.0,
1.3.0, 1.4.0, 1.5.0, 1.6.0 or 1.7.0; those remain frozen for what they specified.

> **Scope note.** 1.8.0 adds no service, no abstraction and no adapter. It makes two additions
> to `LLMAdapters\Anthropic`, both optional constructor arguments. The first gives a second wire
> mechanism for a capability the adapter has had since 1.0.0 — `ReleaseNotes_1.0.0.md` §11.3.8,
> "structured JSON response" — because Anthropic now has two and neither one reaches every
> model. The second lets a request name a workspace, without which an organisation holding
> unscoped API keys cannot use the adapter at all. An application that names neither sends
> byte-for-byte the request 1.7.2 sent.
>
> It also carries **one fix that is not optional and not Anthropic's** (§1.4):
> `LLMAdapters\OpenAI` sent a parameter every current OpenAI model rejects, and so could reach
> only legacy ones. Both that and the two additions above exist because this is the release in
> which Clarity's LLM adapters were driven against live providers for the first time (§3).

## 1. What ships in 1.8.0

### 1.1 `LLMAdapters\AnthropicStructuredOutput`

A two-case enum naming the mechanism:

- `ForcedTool` — a synthetic tool whose `input_schema` is the caller's schema, `tool_choice`
  forced to it, the answer read out of the resulting `tool_use` block. Anthropic's documented
  pattern, and what every Clarity release before this one used.
- `NativeSchema` — `output_config.format`, Anthropic's native schema-constrained decoding. The
  answer arrives as ordinary JSON text.

It sits in `Services\LLMAdapters\` rather than `Services\LLM\` because it is Anthropic's problem
alone; `OpenAI`, `Gemini` and `DeepSeek` each have exactly one mechanism and gain nothing.

### 1.2 The `Anthropic` constructor takes it, last and defaulted

```php
$adapter = new Anthropic(
    $apiKey, $http,
    structuredOutput: AnthropicStructuredOutput::NativeSchema,
);
```

Last in the signature, after `$endpoint`, because `$endpoint` shipped in 1.0.0 and reordering a
positional caller's arguments to make room would break them — the rule 1.7.1 set for
`forCatalogPrice()`'s `$taxCategory`, applied again. (1.7.1 and 1.7.2 are patches and so have no
frozen document of their own, per `ReleasePolicy.md`; their record is the `CHANGELOG.md` entry.) Defaulted to `ForcedTool`, so the argument
is invisible to every caller who does not want it.

The wire difference, and the whole of it:

| | `ForcedTool` | `NativeSchema` |
|---|---|---|
| Request | `tools` + `tool_choice` | `output_config.format` |
| Response | `tool_use` block's `input` | JSON in the `text` blocks |

The two are mutually exclusive — a request carries one or the other, never both — and both are
asserted.

### 1.3 `Anthropic` takes an optional `workspaceId`

```php
$adapter = new Anthropic($apiKey, $http, workspaceId: 'wrkspc_01abc');
```

Sent as the `anthropic-workspace-id` header when set, absent entirely when null — which stays
the default and the common case, because most API keys are themselves scoped to a workspace and
say so without being asked.

Appended after `$structuredOutput`, for the reason §1.2 gives. An empty string is refused at
construction: an empty header is a misconfiguration, and `LLMRequest`'s own rule is that a
malformed request fails before it costs a network round trip.

**Found by the first live smoke test ever run against these adapters** (§3). An organisation
whose keys are not workspace-scoped had no way to use this adapter at all: Anthropic refuses
such a key with a 400 naming the missing header, and every header the adapter sent was
hardcoded. The gate below is why this shipped in the same release as the structured-output work
rather than waiting for a defect report — the smoke test that was supposed to *verify* 1.8.0
found a hole in 1.0.0 instead.

### 1.4 `OpenAI` sends `max_completion_tokens`, not `max_tokens`

A one-word substitution, and the most consequential change in this release.

`LLMAdapters\OpenAI` sent `max_tokens` from 1.0.0 until now. **Every current OpenAI chat model
rejects that parameter outright:**

```
HTTP 400  Unsupported parameter: 'max_tokens' is not supported with this model.
          Use 'max_completion_tokens' instead.
```

Measured against a live key on `chat-latest`, `gpt-6-astra`, `gpt-5.6-sol`, `gpt-5.6-luna` and
`gpt-5.6-terra` — including `chat-latest`, the alias an application is most likely to name. The
adapter could reach **only legacy models**, and had been in that state since 1.0.0.

No configuration is needed, because the substitution is strictly widening: `gpt-4o-mini` and
`gpt-4o` accept `max_completion_tokens` as readily as they accept `max_tokens`, so one name
reaches every model and the other reaches only the old ones. Verified green afterwards on all
five models above plus both legacy ones.

`LLMAdapters\DeepSeek` still sends `max_tokens` and is **deliberately unchanged** — its API is
OpenAI-shaped but separate, it passed its live checks on `max_tokens`, and changing an untested
parameter on a verified adapter to match a different provider's migration would be exactly the
unverified guess this release is otherwise about avoiding.

### 1.5 What does not change

`Services\LLM`'s abstract contract, `LLMRequest`, `LLMResponse`, `LLMException`, and the
`OpenAI`, `Gemini` and `DeepSeek` adapters. No table, no command, no migration.

§11.3.8 is unchanged as a requirement: "structured JSON response" is still `LLMRequest`'s
`$responseSchema` going in and a decoded `array` coming back out on `LLMResponse::$content`. Only
the wire mechanism behind it now has two spellings, and a caller who reads neither this document
nor the adapter's docblock cannot tell which one served them.

## 2. Decisions this release records

### 2.1 Both mechanisms ship, because neither one reaches every model

The forced tool is refused by Anthropic's newest models, which reject `tool_choice` of type
`tool` or `any` outright. The native mode is absent from several older models the forced tool
still serves. Swapping wholesale would have fixed the new models by breaking the old ones, which
is not a fix and would not have been a minor.

**A model id does not say which mechanism it supports, and only Anthropic knows.** The adapter
could not choose per request without a hardcoded model list — the kind of table that is wrong
the week after it is written, and wrong silently. So the choice is the caller's, made once, at
construction, by whoever knows which model the adapter points at. This is the same reasoning
1.7.1 used to *document* rather than refuse a recurring catalogue price on `PaddleCheckout`:
where the framework cannot know, it must not pretend to.

### 2.2 It is adapter configuration, which is 1.7.0 §2.2 applied again

`ReleaseNotes_1.7.0.md` §2.2 put the catalogue price id on the constructor rather than reopen
the frozen `CheckoutRequest`. The same argument holds here, and more strongly: `LLMRequest`'s
seven fields are §11.3's ten-field contract, frozen since 1.0.0 and written against by the
skeleton. An eighth field naming a provider-specific wire mechanism would have leaked one
provider's problem into the object every provider shares — and `OpenAI`, `Gemini` and `DeepSeek`
would have had to ignore it.

One adapter instance means one model's terms. A caller who talks to two Anthropic models with
different mechanisms constructs two adapters, exactly as 1.4.0 §2.4 blessed for billing cycles.

### 2.3 The default is `ForcedTool`, and that is a compatibility decision, not a preference

A minor may add; it may not change what existing code does. Every caller who has passed a
`responseSchema` since 1.0.0 got the forced tool, so that is what they keep getting, silently
and by default. `NativeSchema` is opt-in even though it is the mechanism Anthropic now
recommends — recommending is not the same as being reachable by everyone's model, and the day
that changes is a major's business, not this release's.

### 2.4 The adapter does not validate the schema against native mode's restrictions

The reference lists constraints native mode does not support — recursion, numeric bounds
(`minimum`, `maximum`, `multipleOf`), string bounds (`minLength`, `maxLength`), and
`additionalProperties` set to anything but `false` — and notes that some provider SDKs strip
them client-side and validate locally instead.

**Clarity sends raw JSON and does neither.** Checking would mean walking an arbitrary JSON Schema
to enforce a list this repo cannot keep current, and stripping would mean silently sending a
weaker constraint than the caller wrote. Whatever Anthropic does with an unsupported keyword
arrives through `assertSuccessful()`, which already carries the provider's own response body into
the exception message.

**Answered by the live smoke test, 2026-09-07.** Anthropic *refuses*, precisely, and says why:

```
HTTP 400  output_config.format.schema: For 'number' type, property 'minimum' is not supported
```

That settles the decision in its favour on the evidence rather than the argument. The provider
names the offending type, the offending keyword and the offending path — better than any message
this repo could have produced by walking the schema itself, and it reaches the caller unaltered.
Client-side validation would have duplicated it; client-side stripping would have hidden it and
silently run the caller's request under a weaker constraint than they wrote.

### 2.5 An enum, not a boolean

`structuredOutput: NativeSchema` says at the call site what `useNativeStructuredOutputs: true`
would not, and a third mechanism — Anthropic has shipped two so far, and this release exists
because the second arrived — costs one case rather than a second boolean. The `match` on it is exhaustive, so adding a case fails loudly at
the two places that must handle it instead of silently taking an `else`.

### 2.6 The stop reason is named on all four structured failures

1.7.2 established that a reply arriving without the content it should have carried must raise
and must name its `stop_reason`, because that is what separates a truncated answer from a
declined one. Native mode inherits the rule and extends it: the reference documents that output
"may not match your schema" on a refusal and "may be incomplete" on `max_tokens`, and both reach
this adapter as text that will not parse. So `not valid JSON` and `not a JSON object` name the
stop reason too — `json_decode` cannot tell a refusal from a truncation, and the caller reading
the exception should not have to guess.

### 2.7 Named open items

Neither is in scope here; both are recorded so they are not lost.

1. **`stop_reason` is not on `LLMResponse`.** A reply with `stop_reason: max_tokens` that *did*
   produce parseable content still returns as an ordinary success, and the caller cannot tell a
   complete answer from a truncated one. Surfacing it is additive and belongs in a minor.
2. **The live smoke test.** First run during this release: `Anthropic` is green, the other three
   are blocked on credentials rather than code — see §3.
3. **`Gemini` should pass its key in an `x-goog-api-key` header, not the query string.** That is
   the form Google's own API-key documentation uses, and it would keep the key out of the URL —
   where `HttpClient`'s PSR-7 exception can carry it into an error renderer. Not done here
   because the adapter has no working credential to verify it against (§3), and this release
   would rather leave a known improvement open than ship an unverified change to an auth path.

## 3. Acceptance gate

- [x] `AnthropicStructuredOutput` ships with both cases, in `Services\LLMAdapters\`.
- [x] `Anthropic::__construct` takes it last, defaulted to `ForcedTool`.
- [x] Default path is byte-for-byte unchanged: `tools` + `tool_choice`, no `output_config`.
- [x] Native path sends `output_config.format` and neither `tools` nor `tool_choice`.
- [x] The mode is inert when `$responseSchema` is null — neither key is sent.
- [x] Native mode joins text blocks before decoding, and rejects invalid JSON, a scalar body,
      and a reply with no text block at all — each naming the `stop_reason`.
- [x] `workspaceId` is absent by default, sent as `anthropic-workspace-id` when given, and
      refused as an empty string at construction.
- [x] Suite green; `CHANGELOG.md`, `API_Contracts.md`, `RepoMap.md` and `CLAUDE.md` updated.
- [ ] **Not met: live verification.** Attempted for the first time on 2026-09-07, against a real
      Anthropic key, and it **did not get far enough to verify anything about the wire format.**
      Every call returned HTTP 400 — not an authentication failure, but
      `"This API key is not scoped to a workspace, so this request must include the
      anthropic-workspace-id header"`. The key was valid; the adapter simply could not name a
      workspace. §1.3 exists because of that run, and the run halted there rather than
      continuing to `OpenAI`, `Gemini` and `DeepSeek`.

      **Re-run the same day with `workspaceId` set: the workspace error is gone.** The same key
      that could not get past scoping now reaches the account's billing check and stops there.
      That confirms §1.3 sends a header Anthropic accepts, and confirms nothing whatsoever about
      the request *body*.

      All four adapters were then driven against live credentials:

      | Adapter | Result |
      |---|---|
      | `Anthropic` | **5/5 green** against `claude-opus-5` |
      | `OpenAI` | **4/4 green** on seven models, once §1.4's fix landed |
      | `DeepSeek` | **4/4 green** against `deepseek-chat` |
      | `Gemini` | 401 — blocked by Google, not by this code |

      **`Anthropic` is verified end to end** — plain text with `temperature` omitted, a system
      instruction honoured, structured output through **both** `ForcedTool` and `NativeSchema`
      each returning a correctly decoded object, and an invalid key raising rather than
      returning silence. `workspaceId` is confirmed: the scoping 400 that produced it is gone.
      This is the first Clarity LLM adapter ever confirmed to work against the provider it
      targets.

      Two open questions were answered in the same run, and both are recorded where they were
      asked — §2.4 above for the schema keyword, and the 1.7.2 CHANGELOG entry for
      `temperature`, whose original claim the run proved wrong and which now carries the
      correction. The short version: Anthropic refuses an unsupported schema keyword with a
      precise 400, and refuses a *non-default* temperature while accepting an explicit `1.0`.

      **What this did verify, and it is not nothing.** Four providers returned four different
      failure shapes — three status families, four unrelated JSON bodies — and every adapter
      turned each into an `LLMException` naming its own provider and carrying the provider's
      response body. `assertSuccessful()` and `decodeJsonBody()` are therefore exercised
      end-to-end against reality rather than against a fixture. Three of the four also proved
      their endpoint, auth mechanism and headers correct by getting *past* authentication to a
      billing decision — a 402 or a 429 on balance is a request the provider understood.

      **`OpenAI` is verified across seven models** — `gpt-4o-mini` and `gpt-4o` (legacy),
      `chat-latest`, `gpt-6-astra`, `gpt-5.6-sol`, `gpt-5.6-luna` and `gpt-5.6-terra` (current).
      Phase 6's `max_tokens` doubt is closed, and closed as a **confirmed defect** rather than a
      false alarm: §1.4. **`DeepSeek` is verified** on `deepseek-chat`, including its
      best-effort JSON mode returning a correctly decoded object.

      **Still outstanding**, and why this box stays unticked:

      - `Gemini` entirely — blocked by Google's `AQ.` key problem, not by this code.
      - On Anthropic, the forced tool being *refused* by one of the newest models that reject
        forced `tool_choice`. `claude-opus-5` accepts it, so §2.1's premise is confirmed only on
        the half that works — the half that motivates `NativeSchema` is still argued, not shown.
      - The other three adapters were each verified on **one or two models**, and §1.4 is the
        standing proof that an adapter can pass on one model and be broken on the next. Model
        coverage, not adapter coverage, is what this gate should mean in future.

      `Gemini`'s failure is the one not about money, and it briefly looked like a second gap of
      the `workspaceId` shape. **It is not, and the cause is outside this repo.** The same
      credential was sent three ways — `?key=`, the `x-goog-api-key` header, and
      `Authorization: Bearer` — and all three returned the *identical* 401. A credential the
      endpoint rejects in every auth form it offers is not one the adapter is holding wrongly.

      The credential is 110 characters beginning `AQ.`, Google's newer AI Studio *authorization
      key* format, which has replaced the legacy `AIza…` API key for newly created accounts.
      Google's developer forum carries many reports through mid-2026 of `AQ.` keys returning
      `401 ACCESS_TOKEN_TYPE_UNSUPPORTED` from `generativelanguage.googleapis.com` — via the
      official SDKs as well as raw cURL — on accounts that can no longer issue `AIza` keys at
      all. Our three-way probe is consistent with those reports. **Nothing in Clarity can fix
      this**, and `Gemini` therefore stays unverified rather than being declared broken.

      One real finding survives it, and is deliberately *not* acted on here (see §2.7 item 3):
      Google's own API-key documentation passes the key in an **`x-goog-api-key` header**, while
      this adapter puts it in the query string. That is the legacy form, and it is also why the
      key can reach a URL — the leak hazard `HttpClient`'s PSR-7 exception carries. Worth
      changing, but not on an adapter that currently has no working credential to verify the
      change against. Shipping an unverified auth change is the mistake this whole exercise
      exists to prevent.

      The lesson is already worth recording: **the very first live call found a gap that had
      been in shipped code since 1.0.0 and that no mocked test could have found**, because the
      fixture and the adapter shared an assumption about what a complete request looks like.
      Checkout's rule — a mocked suite is not sufficient evidence to tag — should be adopted for
      LLM, and this box should gate the tag rather than trail it.
