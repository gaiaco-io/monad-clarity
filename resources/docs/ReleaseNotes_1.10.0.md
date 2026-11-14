# Monad Clarity 1.10.0 Release Notes

**Status:** DRAFT. Not frozen until §3's live gate is met. The code is complete, the mocked
suite is green, and the Gemini half of the gate is met in full, the checklist measurement
included. OpenAI, Anthropic and DeepSeek have not yet been reached live: all three smoke
credentials are refused with HTTP 401, the 1.8.0 text checks included, so the failure is the
credentials, not this release. 1.8.0 §3's rule holds the tag until they are replaced and the
suite runs green. This document does not restate 1.0.0 through 1.9.1; those remain frozen for
what they specified.

> **Scope note.** 1.10.0 lets `complete()` look at images. A user message in `LLMRequest`
> may now carry an `images` key: a list of the `ReferenceImage` objects 1.9.0 introduced for
> image generation. All four adapters send them in their provider's own format. Nothing else
> changes:
> - `complete()`'s signature, `LLMResponse` and `LLMRequest`'s constructor are unchanged.
> - No method is added to the `LLM` contract.
> - A request without images is byte-for-byte what 1.9.1 sent. No existing test was modified
>   to make this release pass, and those tests assert each provider's exact request body.
>
> Image *input* to `complete()` was out of scope in 1.9.0 (`ReleaseNotes_1.9.0.md` §2.10).
> It arrives here because of a measurement, not a guess. Asked to check its own edits against
> an app's checklist, an image model answered in only 5 of 9 edits. An independent vision
> model, given the before and after images and a schema built from the checklist, answered
> all 36 items correctly. That second arrangement needs exactly what this release adds (§3).

## 1. What ships in 1.10.0

### 1.1 `images` on a user message

```php
$response = $gemini->complete(new LLMRequest(
    model: 'gemini-3.5-flash',
    messages: [[
        'role' => 'user',
        'content' => 'Image 1 is BEFORE, image 2 is AFTER. What changed?',
        'images' => [new ReferenceImage('image/jpeg', $before), new ReferenceImage('image/jpeg', $after)],
    ]],
));
```

`LLMRequest` validates the key at construction:
- It must be a non-empty list of `ReferenceImage`.
- It is allowed on `user` messages only.

Each failure names the offending message by its index. A message carrying images may have
empty `content`. Leaving the key out is the text-only message every earlier release accepted.

### 1.2 Each adapter's wire format

The new format applies only to a message that carries images; every other message is built
exactly as before. Images go first, then the text, and the text part is left out when
`content` is empty.

| Adapter | An image message becomes |
|---|---|
| `OpenAI` | `content`: `image_url` parts with base64 data URLs, then a `text` part. `detail` is omitted, so OpenAI applies its default. |
| `Anthropic` | `content`: `image` blocks with a `base64` source, then a `text` block |
| `Gemini` | `parts`: `inline_data` parts, then a `text` part |
| `DeepSeek` | the OpenAI-compatible shape: `image_url` parts with data URLs, then `text` |

### 1.3 What does not change

The following are unchanged:
- `complete()`'s signature, `LLMResponse`, and `LLMRequest`'s constructor
- the `LLM` base class
- `Gemini::generateImage()`
- each adapter's text-only path

`ReferenceImage` changes only its docblock, which now covers both uses. No file is added
under `src/`, so `RepoMap.md`, `CrossRepoContracts.md` and the skeleton's mirrors are
unaffected.

### 1.4 The live smoke script

- **A new check in every suite:** each adapter is sent a 64×64 solid red PNG, drawn in the
  script itself, and must name the colour.
- **DeepSeek's default model** is now `deepseek-flash`. DeepSeek discontinued `deepseek-chat`
  on 2026-07-24 (§2.11).

## 2. Decisions this release records

### 2.1 A key beside `content`, not a wider `content`

Every provider's native format puts text and images together in one array, so the obvious
design was to widen `content` from `string` to "string or a list of parts". It was rejected
because it breaks on the *read* side. Code that reads `$request->messages[$i]['content']` as a
string, such as logging, auditing or middleware, would meet an array the first time any part
of the application sent an image. A separate `images` key leaves `content` a string forever.

The cost is expressiveness: images cannot be interleaved between text segments. They come
first, in list order, and the text can refer to them by position ("image 1 is BEFORE"). The
live gate used exactly that phrasing.

### 2.2 Per message, not a top-level field

Images belong to a turn, and a multi-turn history may carry different images in different user
messages. A top-level `images` field could not say which turn an image belongs to.

### 2.3 `ReferenceImage` is reused

1.9.0's `ReferenceImage` is already a media type and raw bytes, validated, with base64 left to
the adapter. That is everything an input image needs. A second class with the same two
fields would be duplication. Only its docblock changed.

### 2.4 No refusal helper; a non-vision model is the provider's to refuse

The plan considered a non-abstract `LLM::assertNoImages()` for adapters that cannot send
images. Every built-in provider accepts images (OpenAI, Anthropic, Gemini, and DeepSeek on
`deepseek-flash`), so nothing in this repository would call it, and it was not built. A model
that lacks vision, such as `deepseek-v4-pro`, is refused by its own provider with a 400, which
`assertSuccessful()` already surfaces with the provider's body.

### 2.5 Images before text

- **Anthropic:** "Claude works best when images come before text."
- **Gemini:** Google's `generateContent` guide places the text prompt after the image.
- **OpenAI and DeepSeek:** no stated preference.

So all four adapters use one order. The checklist gate (§3) ran in this order and scored
36/36, the same as the raw-API measurement that preceded it, which put the text first.

### 2.6 Limits are passed through

The providers' limits all differ, and they change between models:
- **Count per request:** 1,500 for OpenAI, 100 or 600 for Anthropic depending on the model,
  3,600 for Gemini, and 600 for DeepSeek.
- **Size:** for example, 10 MB per image for Anthropic, 32 MiB per image for DeepSeek, and a
  20 MB inline request for Gemini.
- **Media types:** each provider accepts a different set.

`LLMRequest` checks shape only, and the provider's 400 is the authority. This is 1.9.0 §2.5's
rule, applied again.

### 2.7 User messages only

No provider accepts images in an assistant turn. OpenAI and Anthropic also allow only text in
the system prompt, which in Clarity is the separate `$systemInstruction` field anyway.
`LLMRequest` refuses an image on an assistant message at construction, rather than sending a
request every provider would reject.

### 2.8 Inline base64 only

URL and file-ID sources, OpenAI's `detail`, Anthropic's `cache_control`, and Gemini's Files
API are all left out. Each is a later additive option. Inline base64 is the one form every
provider accepts, and it needs no hosting or upload step.

### 2.9 Checking specific objects is a documented pattern, not an API

An application that wants to know whether particular objects changed builds a
`responseSchema` from its own checklist array:
- each object as a required property with a `changed` boolean and a description
- `additionalProperties: false`, so nothing outside the list is reported
- a prompt that names the objects

It is a docs example rather than a `compareImages()` helper. A helper would freeze one prompt
and one schema that every application would need to override, and `ReleaseNotes_1.0.0.md`
§11.4 excludes prompt pipelines from this service. The pattern needs 1.8.2's fix whenever its
schema uses `additionalProperties`.

### 2.10 The opt-in caveat for custom adapters

An `LLM` subclass written outside this repository before 1.10.0 does not know about `images`.
It will send such a message's text and silently drop the images. This is not guarded, because
the only guard would be an abstract method, which breaks every such subclass and is
semver-major. Only applications that use both images and their own adapter are affected, and
this paragraph is that warning.

### 2.11 `deepseek-chat` is retired

DeepSeek discontinued `deepseek-chat` and `deepseek-reasoner` on 2026-07-24. The current
models are `deepseek-flash`, which has vision, and `deepseek-v4-pro`, which does not. The
adapter takes its model from each request, so nothing in `src/` named the old model; only the
smoke script's default did, and it is now `deepseek-flash`. 1.8.0 verified DeepSeek live on the
retired model, so DeepSeek's text and structured checks need re-verifying on the new one,
which is part of §3.

### 2.12 Named open item: a provider body can carry key fragments

OpenAI's 401 body echoes a masked fragment of the rejected key: its first eight and last four
characters. `assertSuccessful()` includes the provider's body in the `LLMException` message,
as it has since 1.0.0, so an application that logs exceptions logs that fragment. This is not
new in 1.10.0 and is not changed here. Redacting provider bodies would be a separate decision,
because the body is also what makes these errors useful.

## 3. Acceptance gate

- [x] `LLMRequest` accepts `images` on user messages and refuses each malformed form, naming
      the message index. Every path is tested with its message asserted.
- [x] All four adapters send images in their provider's format, images first, with no text
      part for empty content. Mocked tests cover mixed history, where only the image message
      changes shape, as well as multiple images in order and the encoding.
- [x] **No existing test was modified.** Every changed test file has zero deleted lines.
      Those tests assert each provider's exact text-only request body, so a request without
      images is byte-for-byte what 1.9.1 sent.
- [x] Suite green at 1181 tests / 2519 assertions.
- [x] **Gemini, live, 9/9 green on 2026-11-15,** including the new image-input check.
- [x] **Checklist gate met: 36/36**, through `Gemini::complete()` with the new `images` key.
      This was the 9 saved before/after Nano Banana edits from the measurement × 4 checklist
      items, on `gemini-3.5-flash`. The schema was built from the checklist array exactly as
      §2.9 describes. The gate required at least 35/36. The ground truth is pixel
      measurement, with one value (run 6's saucer) corrected by eye: the measurement missed a
      dark-blue-to-dark-green change that the model caught.
- [ ] **Not met: OpenAI, Anthropic and DeepSeek live.** Every authenticated call returned
      HTTP 401 ("Incorrect API key", "invalid x-api-key", "Authentication Fails"). That
      includes the 1.8.0 text checks those adapters passed on 2026-09-07, so these are
      credentials that have since been revoked or expired, not a code fault. Each suite's
      bad-key check passes. **Before tagging:** replace the three keys in
      `~/.monad-llm-smoke.env` and run the suite to green, which includes DeepSeek's first
      run on `deepseek-flash` (§2.11).
