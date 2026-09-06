# Monad Clarity 1.8.0 Release Notes

**Status:** FROZEN — canonical specification for the 1.8.0 release.
This document is the source of truth for WHAT ships in 1.8.0. It does not restate 1.0.0, 1.2.0,
1.3.0, 1.4.0, 1.5.0, 1.6.0 or 1.7.0; those remain frozen for what they specified.

> **Scope note.** 1.8.0 adds no service, no abstraction and no adapter. It makes two additions
> to `LLMAdapters\Anthropic`, both optional constructor arguments. The first gives a second wire
> mechanism for a capability the adapter has had since 1.0.0 — `ReleaseNotes_1.0.0.md` §11.3.8,
> "structured JSON response" — because Anthropic now has two and neither one reaches every
> model. The second lets a request name a workspace, without which an organisation holding
> unscoped API keys cannot use the adapter at all. Nothing existing changes: an application that
> names neither argument sends byte-for-byte the request 1.7.2 sent.

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

### 1.4 What does not change

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
weaker constraint than the caller wrote. Whatever Anthropic does with an unsupported keyword —
refuse, ignore, or something else — arrives through `assertSuccessful()`, which already carries
the provider's own response body into the exception message. That is better evidence than a
guess made here, and §3 names it as a live-smoke-test item rather than asserting behaviour
nobody has observed.

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
2. **The live smoke test.** Still the top open item from 1.0.0's Phase 6. First attempted during
   this release and blocked before it could verify anything — see §3.

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

      So what a live key must still confirm, once re-run with `workspaceId` set: that a
      plain-text call succeeds with `temperature` omitted (1.7.2's premise, still untested
      against a live model); that `ForcedTool` and `NativeSchema` are each accepted on a model
      that supports them; that the forced tool is *refused* on a model that rejects it, with the
      refusal surfacing through `assertSuccessful()`; what actually happens to a schema carrying
      a keyword native mode documents as unsupported (§2.4); and then the same for the other
      three adapters, `OpenAI`'s `max_tokens` first — the standing doubt from 1.0.0's Phase 6.

      The lesson is already worth recording: **the very first live call found a gap that had
      been in shipped code since 1.0.0 and that no mocked test could have found**, because the
      fixture and the adapter shared an assumption about what a complete request looks like.
      Checkout's rule — a mocked suite is not sufficient evidence to tag — should be adopted for
      LLM, and this box should gate the tag rather than trail it.
