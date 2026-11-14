# Monad Clarity 1.9.0 Release Notes

**Status:** DRAFT, one step from frozen. `generateImage()` is verified live, end to end,
against `gemini-nano-banana-2.1` (§3). The single remaining box is mechanical: this branch
must take 1.8.2 from `main` and re-run the Gemini suite fully green, since 1.8.0 §3 set the
rule that **live verification gates the tag rather than trailing it**. This document does
not restate 1.0.0 through 1.8.2; those remain frozen for what they specified.

> **Scope note.** 1.9.0 adds no service, no abstraction and no adapter. It adds one public
> method to `LLMAdapters\Gemini`, `generateImage()`, for Google's image models (Nano Banana 2.1,
> `gemini-nano-banana-2.1`, generally available since 2026-10-06), and four value objects for
> it to take and return. `LLM`, `LLMRequest`, `LLMResponse` and `Gemini::complete()` are
> unchanged: an application that does not call `generateImage()` sends byte-for-byte the
> requests 1.8.1 sent.
>
> Image generation is a capability the frozen 1.0.0 spec (§11) never named. It enters through
> the door Checkout already uses: a capability one provider has is a public method on that
> provider's adapter (§2.1). It is not a new service, so 1.5.0 §2.1's scope-expansion question
> does not arise.

## 1. What ships in 1.9.0

### 1.1 `Gemini::generateImage(ImageRequest): ImageResponse`

```php
$gemini = new Gemini($apiKey, $http);

$response = $gemini->generateImage(new ImageRequest(
    model: 'gemini-nano-banana-2.1',
    prompt: 'Put the cat on the sofa',
    referenceImages: [new ReferenceImage('image/png', file_get_contents($catPath))],
    aspectRatio: '16:9',
    imageSize: '2K',
));

foreach ($response->images as $i => $image) {
    file_put_contents("out-{$i}." . explode('/', $image->mimeType)[1], $image->bytes);
}
```

A single synchronous call to `POST {baseUri}/interactions`, honouring the adapter's existing
`$baseUri`. No reference images means text-to-image; one or more means an edit, or generation
from references. The wire format:

| Request field | Value |
|---|---|
| `model` | `ImageRequest::$model` |
| `input` | a `text` part with the prompt, then one `{type: image, mime_type, data}` part per reference, base64-encoded by the adapter |
| `response_format` | `{type: image}`, plus `mime_type`, `aspect_ratio` and `image_size` only when set. No `delivery` field: Google refuses one for image models, and returns the bytes inline anyway (§2.12) |
| `store` | `false` (§2.6) |
| headers | `x-goog-api-key` (§2.3), `Api-Revision: 2026-05-20` (§2.8) |

### 1.2 `Services\LLM\ImageRequest` and `Services\LLM\ReferenceImage`

`ImageRequest` takes `model`, `prompt`, `referenceImages` (a `list<ReferenceImage>`, default
empty), and nullable `aspectRatio`, `imageSize` and `outputMimeType`. `timeoutSeconds` defaults
to **120**, not `LLMRequest`'s 30, because a 4K render can run past a minute. `ReferenceImage`
holds a media type and **raw bytes**; base64 is the adapter's concern, never the caller's.

Construction rejects the following, each with a message that names the problem:
- an empty model, or an empty or whitespace-only prompt
- a keyed array, or anything other than a `ReferenceImage`, in `referenceImages`
- an aspect ratio that is not `width:height` in positive whole numbers
- an empty image size
- an output or reference type that is not `image/…`
- a timeout below 1 second
- an empty reference image

Nothing is checked against a provider's list (§2.5).

### 1.3 `Services\LLM\ImageResponse` and `Services\LLM\GeneratedImage`

`ImageResponse` carries the same provenance `LLMResponse` does: `provider`, `model`, `usage`
(the same `{inputTokens, outputTokens}` shape, read from Interactions'
`usage.total_input_tokens` and `usage.total_output_tokens`), `providerRequestId` (the
interaction `id`), and `raw`. **`providerRequestId` is null in practice:** verified live,
an interaction sent with `store: false` comes back with no `id` at all, because there is
nothing to look it up by. The field is kept, rather than dropped, for the provenance shape
`LLMResponse` shares, and because a future stored mode would fill it. In place of `content` it carries `images`, a non-empty
`list<GeneratedImage>`, and `text`, any prose the model interleaved, joined, or null.

`GeneratedImage` is a media type and **decoded bytes**. Its `mimeType` is what Google said the
image is, not what the request asked for. A file extension taken from the request could lie;
one taken from here cannot.

### 1.4 Failure

Each of the following raises an `LLMException`; no failure is returned as an empty
`ImageResponse`:

| Condition | Message names |
|---|---|
| Non-2xx | the provider, the status, Google's body (`assertSuccessful()`, unchanged) |
| Body not JSON, or not an object | as `complete()` (`decodeJsonBody()`, unchanged) |
| `status` other than `completed`, or absent | the status, and every `errors[].message`, or "Google gave no reason" |
| Completed with no image | the model's text reply, quoted, or that there was none |
| An image block with `uri` and no `data` | that the image came by URI, which the adapter does not fetch |
| An image block with no data at all, or data that is not base64 | which of the two |

### 1.5 What does not change

`Services\LLM`, `LLMRequest` and `LLMResponse` are unchanged. `Gemini::complete()` is
byte-for-byte unchanged, including its `?key=` query-string auth (§2.3). No other adapter is
touched. `HttpClient` is unchanged: `postJson()` already took headers.

### 1.6 The live smoke step

`resources/smoke/live-llm-smoke.php`'s Gemini suite gains four checks and two observations:

- **Checks:**
  - text-to-image returns an image PHP decodes, whose bytes match its claimed type, with
    non-zero usage. Usage is checked because a wrong field name would otherwise read silently
    as 0/0 (§2.9 item 4).
  - `store: false` leaves the interaction unretrievable. Only a 404, or no interaction `id` at
    all, passes. A 429 or 5xx is reported as inconclusive rather than as proof.
  - editing that image as a reference returns an image
  - a bad key raises
- **Observations:** how a refused prompt surfaces, and what changes without `Api-Revision`. They are recorded, not gated, because Google specifies neither (§2.9).

Every image received is written under the system temp directory and its path printed, so the
last check is a human eye. So is Google's raw response, with base64 cut to a stub, so the
mocked fixtures in `GeminiTest` can be reconciled against what Google actually sent.

## 2. Decisions this release records

### 2.1 A method on the adapter, not on the contract and not a new service

`LLM::complete()` returns text or decoded JSON, and an image fits neither. There were three ways in:

- **Widen `LLM`.** This adds an abstract method that OpenAI, Anthropic and DeepSeek would have to
  implement or stub. Stubs are forbidden, and the change is semver-major.
- **Add a `Services\ImageGeneration` service.** This is a new abstraction with one
  implementation, which is exactly what this repo declines to build. It would also be the
  1.5.0 §2.1 scope expansion for a capability with one provider behind it.
- **Add a public method on `Gemini`.** This is the rule Checkout set for subscriptions
  (`ReleaseNotes_1.4.0.md` §2.2, "The facade gains no fifth method"): a capability only some
  providers have is a public method on the adapter that has it.

This release takes the third. If a second provider's image API is ever added, it gets its own
method, and an abstraction can then be drawn from two real implementations rather than guessed
from one.

### 2.2 The Interactions API, and `complete()` stays where it is

Google's image-generation documentation now leads with the Interactions API
(`POST /v1beta/interactions`) and labels `models/{model}:generateContent` "Legacy". The legacy
path still serves `gemini-nano-banana-2.1` and is what `complete()` already speaks, so reusing
it was the smaller change. Building a new feature on a path its owner has already labelled
legacy buys a migration later, so `generateImage()` is written against the documented one.

`complete()` does not move with it. Migrating it would be an unrequested change to working
code, and an unverifiable one (§3).

### 2.3 Header auth for Interactions, query auth still for `complete()`

`generateImage()` sends the key as `x-goog-api-key`. That is the only form the Interactions
reference shows, and it keeps the key out of the URL, where `HttpClient`'s PSR-7 exception
could carry it into an error renderer. So one class now authenticates two ways. The docblock
says so, and it is temporary rather than designed.

This **does not close 1.8.0 §2.7 item 3**, which proposes the same move for `complete()` and
declined it because no Gemini credential worked to verify it. That reasoning still holds for
`complete()`. It does not stop `generateImage()`, which is new: it changes no existing auth
path, and its endpoint documents no other form.

### 2.4 Every image, not the last

A model may interleave several images with prose across several `model_output` steps, and
Google warns that its own convenience accessor (`output_image`) keeps only the final image. The
adapter walks every `model_output` step's content in order and returns all of them. `thought`
steps are skipped.

### 2.5 Shape is validated, a provider's list is not

The documented aspect ratios grew with Nano Banana 2.1 (`1:8`, `8:1`, `1:4`, `4:1`). Sizes
include `512` for one model and not others, and reference-image limits differ per model (14
total for 2.1, other figures for Pro). A list kept in this repo would refuse what Google
accepts the day it fell behind. `ImageRequest` therefore checks only shape, and Google's 400,
which `assertSuccessful()` surfaces with its body, is the authority. This is the rule 1.8.0
§2.4 set for schemas, applied again.

Two departures from the approved plan follow from this, both found by reading the Interactions
reference after the plan was written:
- **There is no cap on reference images.** The plan said at most 14, but that figure belongs
  to one model, and `ImageRequest` serves any model.
- **`outputMimeType` is nullable and unvalidated beyond shape.** The plan said PNG or JPEG.
  The reference lists only `image/jpeg` as an output type, and Google's REST, Python, Java and
  CLI examples all use it, but one JavaScript example on the same image-generation page
  requests `image/png`. The documents disagree, so the request omits the field unless the
  caller sets it, and `GeneratedImage::$mimeType` reports what actually came back.

### 2.6 `store: false`

The Interactions API keeps interactions server-side. `GET` and `DELETE /interactions/{id}`
exist, and the create body takes `store`, documented as *"Whether to store the response and
request for later retrieval."* The reference states **no default and no retention period**.
A one-shot `generateImage()` has no use for retrieval, and a stored request would include
every reference image the caller sent, which may be a user's photograph. The adapter
therefore sends `store: false` explicitly rather than trusting a default nobody has written
down. The smoke step checks that the claim holds.

This is a privacy decision on the caller's behalf. Any caller who wants stored interactions
for `previous_interaction_id` chaining has no way to get them through this method. That is
deliberate: multi-turn image sessions are not in this release (§2.10).

### 2.7 Failure is what Google documents, and no more

An Interaction has no `finishReason`, no `promptFeedback` and no safety verdict field. The only
failure surfaces the reference documents are `status` (`failed`, `incomplete`, `cancelled`,
and so on) and `errors[]`, with `code` and `message`. The adapter names those and nothing else,
and does not invent `IMAGE_SAFETY`-style codes Google does not document for this API. A
completed interaction with no image is also a failure, and its message quotes the model's text,
because a model that declines usually says why in prose. A missing `status` is treated as
not completed, because a response that does not claim success has not shown it.

### 2.8 `Api-Revision` is pinned

Google's own image-generation REST examples send `Api-Revision: 2026-05-20`. The Interactions
reference does not mention the header. Pinning it to the revision this adapter was written
against is the conservative reading: an unpinned request takes whatever shape Google ships
next. Measured live (§2.9 item 2): the header is **not required**, and today a request without
it gets the identical response shape. It stays pinned, because "identical today" is exactly
what a pin protects against changing tomorrow.

### 2.9 Open items, answered by the live run

Each of these could only be settled live. All five are now answered.

1. **How a refused prompt surfaces: unanswered, because nothing was refused.** A prompt asking
   for graphic gore was **not** refused. Nano Banana 2.1 drew a toned-down image and said
   so in prose: *"…avoiding any explicit violence, gore, or weapons to ensure a safe yet
   impactful scene."* The model softens rather than refuses, so a caller gets an image and an
   explanation in `ImageResponse::$text`, not an exception. §2.7's handling of a hard refusal
   (a non-completed status, or an imageless completion) is therefore still unmeasured. The
   handling is correct either way, but the shape of a hard refusal has not been seen.
   **Applications that care whether the model altered the request should read `$text`.**
2. **`Api-Revision` is not required.** The same request without it returned HTTP 200, with the
   identical top-level shape (`status`, `usage`, `created`, `updated`, `service_tier`,
   `steps`, `object`, `model`). It stays pinned (§2.8).
3. **`store: false` is honoured, in the strongest form:** the response carries **no `id`**,
   so there is nothing to retrieve. This is why `providerRequestId` is null (§1.3).
4. **Usage field names and `mime_type` are confirmed** exactly as read from the reference:
   `usage.total_input_tokens` (13 for a short prompt) and `usage.total_output_tokens` (1446,
   of which 1120 were image tokens), plus `mime_type: image/jpeg` on every image block. A
   `thought` step arrives first, carrying a `signature` of about 1.3 MB, and is skipped as
   designed. It does reach `$raw`, so an application that logs `$raw` wholesale will log that
   too.
5. **The Gemini credential** now works (§3).

### 2.10 Not in this release

- Multi-turn editing (`previous_interaction_id`), streaming, `background: true` with polling,
  Google Search grounding (`tools`), `safety_settings`, and thinking configuration. Each is
  additive, and none was asked for.
- Image *input* to `complete()`, which means vision in chat. That is a change to `LLMRequest`'s
  message shape, a separate decision.
- Watermarking. Google applies a SynthID watermark to every generated image. Clarity neither
  adds nor removes anything.

### 2.11 Why the value objects live in `Services\LLM\`, not beside the adapter

1.8.0 put `AnthropicStructuredOutput` in `Services\LLMAdapters\` because it is Anthropic's
problem alone. The four image classes are the opposite: nothing in them is Gemini's. They
describe a prompt, some images and a result in terms any image provider would recognise, so
they sit with `LLMRequest` and `LLMResponse`. If §2.1's second provider ever arrives, it
takes these unchanged.

### 2.12 No `delivery` field, found by the live run

The first build sent `response_format.delivery: "inline"`. The Interactions reference lists
the field, with `inline` and `uri` values, and naming the mode seemed safer than trusting a
default. Google refuses it for image models: `400 Image delivery mode is not supported.`.
Without it, the bytes come back inline, which is exactly the shape the adapter reads. The
field is therefore gone. The guard for an image block that carries a `uri` and no `data` stays,
because a URI-only block is still a documented shape, and the adapter refuses it by name
rather than returning an image with no bytes.

This is 1.8.0 §3's lesson, repeated in miniature. The mocked tests asserted `delivery` was
sent, because the fixture was written to the same reading of the reference the adapter held,
and they passed. Only the provider could say the reading was wrong.

## 3. Acceptance gate

- [x] `Gemini::generateImage()` ships, and `complete()` is byte-for-byte unchanged (its tests
      are untouched and green).
- [x] `ImageRequest`, `ReferenceImage`, `ImageResponse` and `GeneratedImage` ship in
      `Services\LLM\`. Every validation path is tested with its message asserted.
- [x] Mocked tests cover:
  - the request: URL, header auth, the key absent from the URL, the `Api-Revision` header,
    the body with no optional fields, and the body with all of them
  - the response: multiple images in order, text joined, thoughts skipped, a missing
    `mime_type`, and every §1.4 failure
- [x] Suite green at 1163 tests / 2493 assertions. `CHANGELOG.md`, `API_Contracts.md`,
      `RepoMap.md` and `CLAUDE.md` are updated.
- [x] The smoke script has the §1.6 image step. It was run on 2026-11-14, and both bad-key
      checks pass against the live endpoint. Google's error metadata names
      `InteractionsService.CreateInteractionHttp`, which confirms that the URL and method
      reach the Interactions service. Google returned its error body as a JSON *array*
      (`[{"error": …}]`). `assertSuccessful()` raises on status before decoding, so the
      shape is harmless, but it is worth knowing.
- [ ] **Not met: live verification.** The same run's authenticated checks all returned
      `401 ACCESS_TOKEN_TYPE_UNSUPPORTED`. These include the 1.8.1 `complete()` checks, which
      this release does not touch, so the failure is the credential, not this code. It is the
      `AQ.`-format key problem 1.8.0 §3 recorded, unchanged. **Do not tag until the Gemini
      suite runs green with a working key** and §2.9 items 1 to 4 are answered here.

      **Re-run later on 2026-11-14 with a credential Google accepts.** The 401 is gone, and
      the run produced three findings:

      1. **`complete()` is verified live for the first time since 1.0.0**, against
         `gemini-3.5-flash-lite`: plain text, multi-turn with the `model` role, and an
         invalid key raising. The smoke script's old default model, `gemini-2.0-flash`, has
         been retired by Google (HTTP 404), so the default is now `gemini-3.5-flash-lite`.
      2. **Confirmed defect in `complete()`, present since 1.0.0.** Structured output returns
         HTTP 400: `Unknown name "additionalProperties" at 'generation_config.response_schema'`.
         `responseSchema` takes Google's OpenAPI-style subset, not JSON Schema. Any schema
         containing `additionalProperties` is refused, and OpenAI's strict mode requires
         that keyword, so a schema written once for every adapter cannot reach Gemini.
         Google's `responseJsonSchema` field takes the same schema: HTTP 200, with the object
         decoded correctly. It also still takes a Google OpenAPI-style schema (`"STRING"`,
         `nullable`, `propertyOrdering`), so switching fields breaks no schema that works
         today. Both styles were verified on `gemini-3.5-flash-lite` and `gemini-3.5-flash`;
         `gemini-3.8-flash` was overloaded (503) for the whole run. **The fix ships separately
         as patch 1.8.2** (PR #24), Marshal's decision: it is a bug fix, it is verified live,
         and it should not wait on this release's billing blocker. With it applied, the Gemini
         suite runs 4/4 green on `gemini-3.5-flash-lite` and `gemini-3.5-flash`. This branch
         takes the fix by updating from `main` once 1.8.2 merges, and this release's notes do
         not restate it.
      3. **`generateImage()` is blocked by the account's billing tier, not by code.**
         Google answered `429 … limit: 0 requests per day on Free Tier` for
         `gemini-nano-banana-2.1`. Google got past authentication to a quota decision, which
         confirms the endpoint, the header auth and the `Api-Revision` header are accepted.
         It does not verify the request body or any response handling. On this path Google's
         error body was a JSON object (`{"error": {"message", "code"}}`), not the array the
         401 used. §2.9 items 1 to 4 remain open until the Google project has a paid tier.

- [x] **`generateImage()` verified live, end to end**, on 2026-11-14, once Marshal had added
      usage credits to the Google project. The first paid run found one defect: Google refuses
      `delivery: "inline"` (§2.12). With that field removed, every image check is green against
      `gemini-nano-banana-2.1`:

      | Check | Result |
      |---|---|
      | text-to-image: decodable, and type matches bytes | **PASS**: 1024×1024 `image/jpeg` |
      | `store: false` leaves nothing retrievable | **PASS**: no `id` returned at all |
      | edit with a reference image | **PASS**: 1024×1024 `image/jpeg` |
      | bad key raises | **PASS** |

      Checked by eye as well as by assertion. The text-to-image result is a red lighthouse on a
      rock at dusk, a flat illustration, as prompted. The edit is the same scene with the
      lighthouse alone turned green. All five §2.9 items are answered.
- [ ] **Gemini suite fully green on this branch.** The only failing Gemini check here is
      structured output. Its fix is 1.8.2 (#24), which this branch does not yet contain, and
      with 1.8.2 applied that check passes on two models. **Before tagging:** merge #24, update
      this branch from `main`, and re-run `php resources/smoke/live-llm-smoke.php --only=gemini`
      to 8/8.
