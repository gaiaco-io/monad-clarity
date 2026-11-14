# Monad Clarity 1.9.0 Release Notes

**Status:** DRAFT. Not frozen until §3's live gate is met. The code is complete and the
mocked suite is green, but no request has yet reached Google with a working credential.
1.8.0 §3 set the rule that **live verification gates the tag rather than trailing it**, and
this release follows that rule. This document does not restate 1.0.0 through 1.8.0; those
remain frozen for what they specified.

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
| `response_format` | `{type: image, delivery: inline}`, plus `mime_type`, `aspect_ratio` and `image_size` only when set |
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
interaction `id`), and `raw`. In place of `content` it carries `images`, a non-empty
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
| An image block with `uri` and no `data` | that inline delivery was asked for and not honoured |
| An image block with no data at all, or data that is not base64 | which of the two |

### 1.5 What does not change

`Services\LLM`, `LLMRequest` and `LLMResponse` are unchanged. `Gemini::complete()` is
byte-for-byte unchanged, including its `?key=` query-string auth (§2.3). No other adapter is
touched. `HttpClient` is unchanged: `postJson()` already took headers.

### 1.6 The live smoke step

`resources/smoke/live-llm-smoke.php`'s Gemini suite gains four checks and two observations:

- **Checks:**
  - text-to-image returns an image PHP decodes, whose bytes match its claimed type
  - `store: false` leaves the interaction unretrievable
  - editing that image as a reference returns an image
  - a bad key raises
- **Observations:** how a refused prompt surfaces, and what changes without `Api-Revision`. They are recorded, not gated, because Google specifies neither (§2.9).

Every image received is written under the system temp directory and its path printed, so the
last check is a human eye.

## 2. Decisions this release records

### 2.1 A method on the adapter, not on the contract and not a new service

`LLM::complete()` returns text or decoded JSON, and an image fits neither. There were three ways in:

- **Widen `LLM`.** This adds an abstract method that OpenAI, Anthropic and DeepSeek would have to
  implement or stub. Stubs are forbidden, and the change is semver-major.
- **Add a `Services\ImageGeneration` service.** This is a new abstraction with one
  implementation, which is exactly what this repo declines to build. It would also be the
  1.5.0 §2.1 scope expansion for a capability with one provider behind it.
- **Add a public method on `Gemini`.** This is the rule Checkout set for subscriptions
  (`ReleaseNotes_1.3.0.md` §2.4, `1.4.0`): a capability only some providers have is a public
  method on the adapter that has it.

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
  The reference lists only `image/jpeg` as an output type, while Google's own examples request
  `image/png`. The documents disagree, so the request omits the field unless the caller sets
  it, and `GeneratedImage::$mimeType` reports what actually came back.

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
next. Whether it is required, or ignored, is an open item (§2.9).

### 2.9 Named open items

Each of these can only be settled by a live run, and §3 lists them as its unticked boxes.

1. **How a refused prompt surfaces.** §2.7 handles any status other than `completed` and any
   imageless completion, so the adapter raises either way. Which of the two Google actually
   returns, and what its message says, is unmeasured. The smoke script records it.
2. **Whether `Api-Revision` is required, or changes anything.** The smoke script sends the same
   request without it and records the result.
3. **Whether `store: false` is honoured.** This is a gated check, not an observation, because
   §2.6 is a claim made to callers.
4. **Usage field names and `mime_type` on output blocks.** These are read from the reference's
   schema; the reference has no full image-response example.
5. **The Gemini credential itself.** See §3.

### 2.10 Not in this release

- Multi-turn editing (`previous_interaction_id`), streaming, `background: true` with polling,
  `delivery: "uri"`, Google Search grounding (`tools`), `safety_settings`, and thinking
  configuration. Each is additive, and none was asked for.
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
