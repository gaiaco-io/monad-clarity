# TestingStrategy.md — monad/clarity

## Tooling

PHPUnit (bundled dev dependency) for all tests; FakerPHP (bundled dev dependency) for
generating test fixtures — user records, request payloads, file uploads, etc. Tests live in
`resources/tests` (excluded from the Packagist dist per `Architecture.md` §10; present in the
git repo for contributors and CI).

## Core rule: tests are written alongside implementation, never after

Per `CLAUDE.md` and the phase discipline in `GapAnalysis_BuildPlan_1.0.0.md`: no phase or
sub-phase is considered complete until its PHPUnit tests are green. "Tests later" is treated
the same as a placeholder — not permitted per the no-partial-implementation rule.

## Priority tiers

**Tier 1 — security-critical, highest rigor, adversarial test cases required:**
`Middlewares\Csrf`, `Middlewares\Authentication`, `Middlewares\RateLimiter`,
`Middlewares\RBAC`, `Utils\ConstantTime`, `Utils\Encryption`, `Utils\Hash`, `Utils\HMAC`,
`Utils\CryptographicToken`, `Utils\SignedURL`. Tests must include failure/attack-shaped
inputs, not just happy-path: token replay, timing-attack resistance (statistical, not just
functional, for `ConstantTime`), expired/tampered signed URLs, brute-force throttling
thresholds, rehash-on-verify behaviour for `Hash`.

Also Tier 1, from 1.6.0: **`Services\Mail\Header` and the header-bound validation in
`Address`, `Message` and `Attachment`**, plus **`Mail\MimeMessage`'s Bcc guarantee**. Mail
sends on the password-reset path and is assembled from user-supplied data, so the adversarial
cases are required — CR, LF and NUL in a display name, a subject, a custom header name and
value, and an attachment filename, each refused rather than sanitised; `Bcc` and every other
structural header refused as an application-supplied extra; and no `Bcc` header emitted in any
of the five MIME shapes. A header split by an injected newline forges a `Bcc` the sender never
wrote, and a `Bcc` header written into the document discloses every blind recipient to every
other one — both silent, and the second unrecoverable once sent. `MailAdapters\Smtp`'s
credential handling belongs here too: a test asserts the password reaches neither an exception
message nor a stack trace.

**Tier 2 — data integrity:** `Services\Schema`, `Services\Migration`, `Services\Session`,
`Services\Cache` (all three drivers), `Services\DB`, `Services\Scheduler\JobLedger`.
The ledger's claim is a lock, and a lock test is worthless unless it races two connections to
one database: `sqlite::memory:` gives two *separate* databases, so both claims succeed and the
test proves nothing. Use a temp file database and two registered contexts, and assert the loser
is distinguished by the SQLSTATE integrity class specifically — a dropped table must not read
as "another node got there first". Tests must cover the DDL in `DDL.sql`
directly — round-trip a session with `user_id = NULL`, verify the Cache DB driver rejects a
`cache_key` collision at the same `key_hash` (per `Architecture.md` §9), verify migration
rollback restores prior schema state exactly.

**Tier 3 — HTTP core:** `Services\Request`, `Services\Response`, `Services\Route`,
`Services\View`, `Services\Mediator`, `Middlewares\Jsonify`, `Middlewares\CORS`,
`Middlewares\MetaTag`. Cover the full accessor surface in `API_Contracts.md`, the 404-vs-405
distinction, the Jsonify↔Request contract from `CrossRepoContracts.md` §6 explicitly (both
branches: middleware ran / middleware absent), and Mediator's dev vs prod renderer outputs.

**Tier 4 — pure utilities and integrations:** `Utils\Redactor`, `Services\Event`,
`Services\Scheduler` and `Services\Scheduler\CronExpression` (the parser is pure and carries the
bulk of the scheduler's cases: every field form, both name vocabularies, every macro, the
Vixie day-of-month/day-of-week OR rule on all four of its combinations, and each malformed
expression it refuses — a cron field that parses but matches the wrong days fails silently,
which is why the rejections are tested as thoroughly as the matches),
`Services\HttpClient`, `Services\LLM` + adapters, `Services\Checkout` adapters. LLM adapter
tests mock HttpClient — no live provider API calls in the automated suite (cost, flakiness,
and secrets exposure). Checkout adapters follow the same rule, with two additions the money
makes non-negotiable: their callback parsing is **Tier 1** and carries the adversarial cases
demanded there, and a mocked suite is not sufficient evidence to tag a release — every adapter
is also driven live against its gateway's test or sandbox environment before the tag, because
a suite that signs with the same helper it verifies with cannot prove signature verification
works. `StripeCheckout`, `PaddleCheckout` and `PaddleSubscription` all ship under that bar.

**`Services\Mail` adapters and `Mail\MailerPool`** sit here too (their security-critical parts
being Tier 1, above). Mail adapters mock `HttpClient` exactly as the LLM ones do; `Smtp` drives
a scripted `SmtpTransport` so the whole conversation is asserted without a socket, and
`AmazonSes` a plain `sendEmail()`-shaped fake, so no live credential appears in the suite.
Every adapter carries at minimum the two classification cases the pool depends on — its
provider's auth failure yielding `FailureScope::Mailer`, and a rejected recipient yielding
`FailureScope::Message` — because a scope decided wrongly is invisible until an outage.
`MailerPool`'s own tests are the release's centre of gravity: a healthy primary ends the
search, a `Mailer` fault advances and records both attempts, a `Message` fault stops with the
next member **never called**, every member failing raises naming all of them, an empty pool is
refused at construction, and anything that is not a `MailException` propagates rather than
being failed over. `Tests\Integration\MailFailoverTest` then drives the real adapters through a
real pool, since three units can each pass and still disagree with one another.

Unlike Checkout, a mocked suite **is** sufficient to tag Mail. Nothing here verifies an
inbound signature, so the objection that a suite signing with the helper it verifies with
proves nothing does not apply. The live sandbox run remains in the release gate for
confidence, not as a correctness argument.

Where an adapter shares code with a sibling, the sibling's existing suite passing **unmodified**
is the acceptance test for the extraction — 1.4.0 carved `SpeaksPaddle` out of `PaddleCheckout`
and required its 51 tests to stay byte-identical. A test file edited to accommodate a refactor
has stopped being evidence that the refactor changed nothing.

**Tier 5 — Console:** kernel dispatch (`Services\Console::run()`), each of the 19 command
classes individually, using a temp filesystem/SQLite fixture rather than touching a real
project. `make:*` commands are tested by asserting generated file content and location; `db:*`
and `migrate*` commands are tested against a throwaway test database.

## Coverage philosophy

No arbitrary percentage target. The bar is: no security-relevant code path (Tier 1) ships
untested, and every public method listed in `API_Contracts.md` has at least one passing test
exercising its documented contract. Coverage tooling (if added) reports gaps; it does not set
policy by itself.

## Integration testing against the skeleton

Because Clarity has no runnable application of its own, end-to-end verification happens by
`composer create-project`-ing (or path-repository-installing during development) the skeleton
against a local working copy of Clarity, then running `php mitosis setup && php mitosis health
&& php mitosis test`. This is the closest thing to a smoke test and should be run at the end
of every build-plan phase that touches a skeleton-visible contract (per `CrossRepoContracts.md`).

## CI requirements

Every pull request runs the full PHPUnit suite against the PHP version floor (`>=8.2`, plus
at least one newer minor, e.g. 8.3) before merge — `composer validate --strict`, lint, then
test, matching `.github/workflows/ci.yml`'s matrix exactly. No merge to `main` with a red
test suite, regardless of urgency. `CHANGELOG.md` entry presence is a review-time discipline
per `ReleasePolicy.md`, not a CI-enforced gate — nothing in the workflow currently checks it.
