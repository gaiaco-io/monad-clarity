# Monad Clarity 1.6.0 — `Services\Mail` Gap Analysis & Sequenced Build Plan

**Status:** DRAFT FOR REVIEW — not frozen, not approved.
This document proposes a scope expansion and records the reasoning behind every structural
choice it makes. It becomes `ReleaseNotes_1.6.0.md` only after Marshal approves §2.1 and
resolves the four open forks in §6. Nothing here is built until then.

**Scope basis:** `ReleaseNotes_1.0.0.md` (frozen), `ReleaseNotes_1.5.0.md` §2.1 (the
scope-expansion precedent), `Architecture.md` §7 (facade/adapter rationale),
`CLAUDE.md` (hard rules), the shipped `Services\LLM` and `Services\Checkout` trees.

---

## 1. What this proposes

An abstract `Services\Mail` with seven adapters under `Services\MailAdapters\*`, the
provider-agnostic value objects they exchange under `Services\Mail\*`, and an optional
failover pool that lets an application list mailers in priority order and have the first
healthy one take the message.

```php
// One mailer. The ordinary case.
$mail = new MailAdapters\Postmark(serverToken: $token, httpClient: new HttpClient());
$sent = $mail->send($message);

// Several, in priority order. Postmark first; Resend only if Postmark cannot take it.
$mail = new Mail\MailerPool([
    new MailAdapters\Postmark($token, $http),
    new MailAdapters\Resend($resendKey, $http),
    new MailAdapters\Smtp($host, $port, $user, $pass),
]);
$sent = $mail->send($message);

$sent->mailer;   // 'resend' — who actually took it
$sent->attempts; // what the two before it did, and why they were passed over
```

`MailerPool` extends `Mail`, so it *is* a mailer: application code holds one type whether
it was handed one adapter or seven, and a pool can be nested inside a pool if an
application ever wants tiers.

### 1.1 The seven adapters

| Adapter | Endpoint | Auth | Body |
|---|---|---|---|
| `MailAdapters\Mailtrap` | `send.api.mailtrap.io/api/send` | `Api-Token` header | JSON |
| `MailAdapters\Postmark` | `api.postmarkapp.com/email` | `X-Postmark-Server-Token` header | JSON |
| `MailAdapters\Mailgun` | `api.mailgun.net/v3/{domain}/messages` | HTTP Basic `api:{key}` | **multipart/form-data** |
| `MailAdapters\AmazonSes` | `email.{region}.amazonaws.com/v2/email/outbound-emails` | **AWS SigV4** | JSON |
| `MailAdapters\Resend` | `api.resend.com/emails` | `Authorization: Bearer` | JSON |
| `MailAdapters\SendGrid` | `api.sendgrid.com/v3/mail/send` | `Authorization: Bearer` | JSON |
| `MailAdapters\Smtp` | any host, 587/465/25 | AUTH PLAIN / LOGIN | **RFC 5322 over a socket** |

Three of those seven do not fit the shape the other four share. That is the single most
important fact about this release, and §2.2 is about it.

### 1.2 Per-provider behaviour that will otherwise be discovered the hard way

These are the places a provider does something the other six do not. Each one is a bug
waiting for whoever writes that adapter without knowing:

- **SendGrid returns `202` with an empty body.** There is no JSON to decode; the provider's
  message id arrives in the `X-Message-Id` *response header*. An adapter that calls a shared
  `decodeJsonBody()` helper on a successful SendGrid send fails on every successful send.
- **SendGrid's `content[]` array is order-significant.** `text/plain` must precede
  `text/html` or the API rejects the payload.
- **Postmark can return HTTP `200` carrying a non-zero `ErrorCode`.** Status code alone is
  not success; the body's `ErrorCode` must be `0`.
- **Mailgun is not a JSON API.** It takes `multipart/form-data` (or url-encoded when there
  are no attachments), and repeats the `to` field once per recipient rather than sending an
  array. `HttpClient` sends a raw string body, so the multipart document — boundary,
  per-part headers, CRLF discipline — is built by hand in the adapter.
- **Mailgun's EU region is a different host** (`api.eu.mailgun.net`), and the sending domain
  is part of the path, not the body. Both belong in that adapter's constructor.
- **SES v2 `Simple` content and attachments do not mix cleanly.** A message carrying
  attachments is sent as `Content.Raw.Data` — base64 of a full MIME document. This is why
  `Mail\MimeMessage` (§3) is built in Phase 1 and not left to the SMTP phase: SES needs it too.
- **Mailtrap's sandbox is a different host and path** (`sandbox.api.mailtrap.io/api/send/{inboxId}`).
  Sandbox is where a developer will spend most of their time, so it is a first-class
  constructor option, not an afterthought.
- **Resend accepts attachment content as base64 *or* a byte array**, and names the field
  `reply_to` where Postmark names it `ReplyTo` as a plain string and SendGrid names it an
  object. Nothing is shared across these; each adapter translates.

---

## 2. Proposed specification decisions

### 2.1 A second service the frozen 1.0.0 spec never named

`ReleaseNotes_1.0.0.md` §1–§31 specifies no mail, mailer, SMTP, or outbound-message
component. The closest thing to a mention is its *"email verification hooks"* line in
Session — a hook with nothing on the other side of it — and a dangling `config/mail.php`
in `RepoMap.md`'s skeleton tree, a file the spec never says what to put in.

This is the same situation `ReleaseNotes_1.5.0.md` §2.1 faced with the Scheduler, and it
takes the same answer: §9's *"the roster is illustrative"* hatch from
`ReleaseNotes_1.3.0.md` §2.1 permits an unnamed **gateway inside an abstraction §9 already
specified**. It does not reach a service the spec never contemplated. So the expansion is
recorded here for approval rather than assumed.

The demand is already in the codebase, exactly as it was for the Scheduler. Session carries
email-verification and password-reset token machinery whose tokens have no way to reach a
human. `RepoMap.md` promises the skeleton a `config/mail.php` that configures nothing.
Clarity can mint a signed URL and cannot send it anywhere.

**This is semver-minor.** Nothing existing changes: no signature moves, no table definition
moves, no command is renamed, and an application that never constructs a mailer is
byte-for-byte unaffected.

### 2.2 `Mail` fixes no constructor — the deviation from LLM and Checkout

`Services\LLM` and `Services\Checkout` both declare a concrete constructor on the abstract
base:

```php
public function __construct(
    protected readonly string $apiKey,
    protected readonly HttpClient $httpClient,
) {}
```

That works because every LLM provider and every payment gateway is an HTTP API reached with
a single bearer credential. **Mail is the first Clarity abstraction where that is false**:

- `Smtp` has no API key and no `HttpClient` at all — it has a host, port, username,
  password, and encryption mode, and it speaks to a socket.
- `AmazonSes` has an access key **and** a secret **and** a region; the secret is not a
  bearer token and is never transmitted.
- `Mailgun` has a key **and** a sending domain **and** a region base URI.
- `Mailtrap` forks on whether it is addressing the live sending API or a sandbox inbox.

Forcing these through a two-argument base constructor would mean an `Smtp` adapter storing
its password in a property called `$apiKey` and its `$httpClient` as a null it never uses.
That is a lie in the type signature told to preserve a superficial symmetry, and it is
exactly the "abstraction for aesthetics" `CLAUDE.md` forbids.

**So `Mail` declares abstract methods and nothing else.** Each adapter declares the
constructor its provider actually requires. The HTTP niceties the six API adapters share —
`assertSuccessful()`, `decodeJsonBody()`, JSON POST construction — move into a
`MailAdapters\SpeaksHttpApi` trait, applied by the six adapters that speak HTTP and not by
`Smtp`.

This has direct precedent in this repo: 1.4.0 extracted `CheckoutAdapters\SpeaksPaddle`
when two adapters shared a provider's dialect. `SpeaksHttpApi` is the same move one level
up — six adapters sharing a *transport*, where the seventh does not.

`Architecture.md` §7 is amended to record that a Clarity abstraction defines *the contract*,
and defines a shared constructor only when every implementation genuinely shares it.

### 2.3 Failover is why `Mail` reverses `LLM` §11.4

`ReleaseNotes_1.0.0.md` §11.4 explicitly excludes **"automatic retries across providers"**
from the LLM service. This release does the opposite thing for mail, deliberately, and the
distinction is not a change of mind:

> Two LLM providers are not interchangeable. Anthropic and OpenAI, handed the same prompt,
> return different text — so a silent failover changes the product's output, and an
> application that failed over without knowing shipped an answer it did not choose.
> **Two mailers are interchangeable.** A delivered email is a delivered email; the recipient
> cannot tell which of seven providers carried it, and the application's behaviour is
> identical either way. Failover changes the delivery path and nothing observable.

The second half of the argument is the operational one. An LLM outage degrades a feature. A
mail outage silently breaks password reset, email verification, and receipts — the paths a
user cannot route around, and the ones nobody notices are down until support asks why
signups stopped. Mail is the one Clarity dependency where "the provider is down" and "the
product is broken" are the same sentence.

### 2.4 Failover keys on *whose fault it is*, not on the status code

The obvious implementation — fail over on 5xx and timeouts, give up on 4xx — is wrong in
both directions, and this is the single load-bearing detail of the pool.

It is wrong to give up on a `401`. Bad or expired credentials on Mailgun are **precisely**
when Postmark should take the message: the whole point of configuring a second mailer is
that it holds a *different* credential. A pool that refuses to fail over on an auth failure
fails exactly when it was configured to help.

It is wrong to fail over on a malformed recipient address. That message is invalid on all
seven providers; failing it over means seven network round trips, seven timeouts' worth of
latency, and a final error message naming the last mailer tried rather than the real fault.

So the axis is **fault of this mailer** vs **fault of this message**, expressed as a typed
enum the pool reads — never a list of status codes:

```php
enum FailureScope { case Mailer; case Message; }
```

| `FailureScope::Mailer` — try the next one | `FailureScope::Message` — stop, the next fails identically |
|---|---|
| Authentication rejected, credential expired | `From` address malformed or not a valid mailbox |
| `5xx` from the provider | A recipient address is syntactically invalid |
| `429` / rate limited / over quota | No recipient at all, or no body at all |
| Connection refused, DNS failure, TLS failure | Attachment exceeds a limit every provider shares |
| Read or connect timeout | Provider rejects the payload as malformed |
| Account suspended or sending paused | |

Each adapter classifies its own provider's errors, because only it knows what its provider's
error bodies mean. `MailException` carries the scope; `MailerPool` reads it and nothing else.

An ambiguous or unrecognised provider error is classified `Mailer`. The cost of guessing
wrong that way is one wasted round trip on the next mailer; the cost of guessing wrong the
other way is a message that is never sent because a provider returned something unexpected.

### 2.5 The guarantee is **at least once, not exactly once**

This is the honest inverse of the Scheduler's §2.4, and it is stated plainly for the same
reason that one was.

If Postmark accepts a message and then the connection times out before its response is read,
the pool cannot distinguish "not sent" from "sent, acknowledgement lost". It fails over, and
the recipient gets the email twice. **No cross-provider idempotency key exists** — each
provider mints its own message id, and none will honour another's.

Applications that must not double-send — an invoice, a one-time code that invalidates the
previous one — should send through a single adapter and handle the failure themselves. The
pool is for messages where a duplicate is a mild annoyance and a silent non-delivery is a
real failure, which is most transactional mail and all of it that matters at 3am.

This is the same species of honesty as 1.4.0 §2.5's admission that `checkout_subscriptions`
offers a weaker monotonic guard than `TransactionLedger`'s unique index. Overstating a
guarantee is worse than stating a narrow one clearly.

### 2.6 "Whether to enable multi-mailer" is a composition choice, not a flag

The requirement — *let the developer choose whether multi-mailer is on, and set priorities
when it is* — needs no toggle inside Clarity, no `enabled` key, and no configuration
schema. It is already answered by which object `config/mail.php` constructs:

```php
// multi-mailer off
return new MailAdapters\Postmark($token, $http);

// multi-mailer on; priority is the order they appear in
return new Mail\MailerPool([$postmark, $resend, $smtp]);
```

Both expressions have the type `Mail`. Application code is identical either way, and
switching between them is a one-line change in a config file the skeleton owns.

**Priority is array order.** Not an integer field to be sorted, not a `priority: 10` key —
the list reads top to bottom in the order it will be tried, which is the only ordering a
reader could infer anyway. An integer priority adds a sort, a tie-break rule, and a way to
write a configuration whose intent is ambiguous, to express something the array already says.

This also keeps `config/mail.php`'s shape a **skeleton** concern.
`CrossRepoContracts.md` says nothing about `config/llm.php`, and for the same reason it will
say nothing about `config/mail.php`: Clarity's contract is the constructor signatures, not
the file that calls them.

### 2.7 `SentMessage` carries the whole attempt trail

With no database table (§2.8), the return value is the **only** record that failover
happened. A pool that quietly falls through to its third mailer for a week, with the
application none the wiser, has converted an outage into an invisible degradation — and the
bill arrives when the third one fails too.

So `SentMessage` carries not just the provider's message id but *which mailer produced it*
and *every attempt that preceded it*, each with the exception that ended it. An application
that wants alerting logs `$sent->attempts`; one that does not, ignores a property. Neither
pays for a table.

### 2.8 No table, no `mail:install`, no new command

`Checkout` and `Scheduler` each ship an opt-in `*:install` command because each owns
persistent state that is load-bearing: a transaction ledger that must survive a callback
arriving twice, a run ledger that *is* the cluster mutex.

Mail owns no such state. Sending is a request/response operation whose result is fully
described by its return value, and a delivery log is the application's own table and
concern in exactly the way `Files` metadata is — `Files`' own docblock already makes this
argument. Adding a `mail_deliveries` table would be Clarity deciding what an application
must record about its own mail.

Consequently this release adds **no command**, touches **no** `DDL.sql`, and requires **no**
change to `CrossRepoContracts.md` §3's stable command list. The eighteen built-in commands
stay eighteen.

### 2.9 Mail does not render templates

`Services\View` renders templates. `Mail\Message` takes an HTML string and a text string.
The application renders and passes the result:

```php
$message = new Mail\Message(
    from: new Mail\Address('hello@app.test', 'App'),
    to: [new Mail\Address($user->email)],
    subject: 'Reset your password',
    html: View::render('Emails/PasswordReset', ['url' => $url]),
    text: "Reset your password: {$url}",
);
```

Coupling Mail to View would make every adapter's test depend on a view path and a filesystem,
and would give Clarity an opinion about where an application keeps its email templates —
`Freedom` in `CLAUDE.md` says it does not get one. The two services compose without knowing
about each other.

### 2.10 Instance-based, like LLM and Checkout

`Route`, `Console` and `Scheduler` are static because registrations arrive from a routes file
at boot and are read by machinery instantiated with no arguments. Mail has no registration
phase: it is constructed with credentials and used, which is the `LLM`/`Checkout`/`Files`
shape. `MailerPool` is the composition root, built once in `config/mail.php` and handed
around — a static facade would add a global credential registry to hold exactly one object.

### 2.11 SMTP is deliberately narrow

`MailAdapters\Smtp` implements what a transactional sender needs and refuses to grow into a
general-purpose mail client:

- **`AUTH PLAIN` and `AUTH LOGIN` only.** Every provider offering SMTP supports one of them
  over TLS. `CRAM-MD5` exists to protect a password on an unencrypted link — it is a worse
  answer to a problem TLS already solved, and implementing it invites use of the plaintext
  link it implies is acceptable.
- **`STARTTLS` required by default**, with an explicit named opt-out for a local
  development relay such as Mailpit. Not a silent downgrade: a server that fails to offer
  `STARTTLS` when it was expected is an error, because that is what an interception looks like.
- **Implicit TLS on 465** supported alongside `STARTTLS` on 587.
- **`EHLO` with `HELO` fallback**, because a handful of relays still answer only the latter.
- **CRLF line endings and dot-stuffing** applied on the way out, per RFC 5321 §4.5.2. A body
  line beginning with `.` that is not stuffed truncates the message at that line — silently,
  and only for the messages unlucky enough to contain one.
- **No pipelining, no `BDAT`/CHUNKING, no DSN, no connection pooling.** Command-response in
  lockstep is simpler to reason about, trivial to test, and irrelevant to the throughput of
  a service sending transactional mail.

### 2.12 One `Message` is one email, and `MimeMessage` never emits a `Bcc:` header

Recipients are where the seven providers diverge most, and where divergence is most
expensive — so the contract is fixed here rather than decided five different ways across
Phase 2. Postmark takes `To`/`Cc`/`Bcc` as comma-separated **strings**; SendGrid nests them
inside `personalizations[]`; SES splits them into `Destination.{To,Cc,Bcc}Addresses`; SMTP
names every recipient in `RCPT TO` regardless of which field it came from.

**A `Message` with three `to` addresses is one email whose three recipients can see each
other** — not three separate sends. SendGrid's `personalizations[]` array makes the other
reading expressible, so its adapter sends exactly **one** personalization containing all
recipients. An application wanting three private emails constructs three `Message` objects,
which is also the only way to give each one its own body.

**`MimeMessage` never writes a `Bcc:` header.** Blind recipients go in the SMTP envelope
(`RCPT TO`) and nowhere else. This is not a formatting nicety: a `Bcc:` header in the
transmitted document discloses every blind recipient to every other recipient, which is the
one failure mode of this service that is both silent and unrecoverable — the disclosure has
already happened by the time anyone notices. The same applies to SES's `Content.Raw.Data`
path, which transmits `MimeMessage` output verbatim.

Phase 1 carries an explicit test asserting that a message with a Bcc produces MIME containing
no `Bcc` header, and Phase 3 one asserting that recipient reaches `RCPT TO`.

### 2.13 No new Composer dependency

1.4.0 added recurring billing without one and 1.5.0 wrote a cron parser in-house rather than
take one. Every adapter here is a JSON or form POST over the existing `HttpClient`, and the
SMTP adapter is a socket conversation over `ext-openssl`, which is already required. The MIME
builder is roughly a hundred lines of RFC 5322 that would otherwise pull in a dependency
tracked and audited for the life of a major version.

**`ext-openssl` is already in `composer.json`'s `require`.** No `require` entry changes.

---

## 3. Component inventory

An unbuilt adapter is an **absent file, never a stub** — the rule the eight remaining
`CheckoutAdapters` follow. A phase that has not run leaves no trace in `src/`.

```
src/Services/
├── Mail.php                          # abstract: send() + mailerName(). No constructor. (§2.2)
├── Mail/
│   ├── Address.php                   # email + optional display name, validated at construction
│   ├── Attachment.php                # filename, contentType, raw bytes, disposition, contentId
│   ├── Message.php                   # from/to/cc/bcc/replyTo/subject/text/html/headers/
│   │                                 #   attachments/tags — immutable, validated
│   ├── SentMessage.php               # mailer, providerMessageId, attempts[], raw (§2.7)
│   ├── Attempt.php                   # mailer, outcome, MailException|null — the failover trail
│   ├── FailureScope.php              # enum Mailer|Message (§2.4)
│   ├── MailException.php             # carries FailureScope
│   ├── MimeMessage.php               # RFC 5322/2045 builder — used by Smtp AND AmazonSes
│   ├── MailerPool.php                # extends Mail; ordered failover (§2.3–2.7)
│   ├── SmtpTransport.php             # interface: the socket seam that makes Phase 3 testable
│   └── SocketTransport.php           # stream_socket_client implementation of the above
└── MailAdapters/
    ├── SpeaksHttpApi.php             # trait: assertSuccessful, decodeJsonBody, JSON POST (§2.2)
    ├── Mailtrap.php
    ├── Postmark.php
    ├── Mailgun.php
    ├── AmazonSes.php
    ├── Resend.php
    ├── SendGrid.php
    └── Smtp.php
```

Tests mirror this exactly under `resources/tests/Services/Mail/` and
`resources/tests/Services/MailAdapters/`, matching the existing `LLM`/`LLMAdapters` and
`Checkout`/`CheckoutAdapters` split.

### 3.1 The contract

```php
abstract class Mail
{
    /** @throws MailException carrying a FailureScope. */
    abstract public function send(Message $message): SentMessage;

    /** Identifier stamped onto SentMessage and used in this adapter's error messages. */
    abstract protected function mailerName(): string;
}
```

One abstract method, as `LLM` has one. **Adding a second abstract method later is
semver-major** and breaks every downstream adapter — the rule `ReleaseNotes_1.3.0.md` §2.4
established for `Checkout`'s four, and it applies here with more force because there is only
one. A capability only some providers have — Postmark's message streams, SendGrid's
templates, SES's configuration sets, Mailgun's scheduled delivery — is a **public method on
the adapter that has it**, never a widening of the shared contract.

---

## 4. Sequenced build plan

One phase per session, per `CLAUDE.md`. **Tests are written inside the phase that creates
the code they cover** — never a trailing test phase. Each phase ends with `vendor/bin/phpunit`
green, `CHANGELOG.md` updated, and no stray files in `src/`.

### Phase 1 — Contract and value objects
`Mail.php`, `Address`, `Attachment`, `Message`, `SentMessage`, `Attempt`, `FailureScope`,
`MailException`, `MimeMessage`.

No network, no provider. Everything here is pure and fully testable: address validation,
message invariants (at least one recipient, at least one body part, a valid `from`), and the
MIME builder's output checked byte for byte against RFC 5322 — headers folded correctly,
`multipart/alternative` nested inside `multipart/mixed` when both bodies and attachments are
present and inside `multipart/related` when an attachment is an inline `contentId` image,
base64 wrapped at 76 columns, CRLF throughout, and no `Bcc` header ever emitted (§2.12).

`MimeMessage` is built here, before anything needs it, because two later phases do: SMTP
transmits it and SES base64-encodes it into `Content.Raw.Data`. Discovering that in Phase 4
would mean either duplicating it or refactoring Phase 3.

**Gate:** the contract is fixed. Every later phase is additive against it.

### Phase 2 — The six HTTP adapters
`SpeaksHttpApi`, then `Postmark`, `Resend`, `SendGrid`, `Mailtrap`, `Mailgun`.

Ordered by decreasing similarity. Postmark and Resend are the plainest JSON-with-a-token
adapters and establish the pattern; SendGrid adds the empty-202 and content-ordering
handling; Mailtrap adds the sandbox fork; Mailgun is last because it is the one that is not
JSON — HTTP Basic, a hand-built multipart body, repeated `to` fields, region-dependent host.

Each is tested against the existing `FakeHttpClient` seam
(`resources/tests/Services/LLMAdapters/FakeHttpClient.php` is the model), asserting the
outbound request byte for byte and the parsed `SentMessage`, plus one error-classification
test per adapter proving a `401` yields `FailureScope::Mailer` and a rejected recipient
yields `FailureScope::Message`. That classification is what Phase 5 depends on.

**Gate:** five providers send. `AmazonSes` and `Smtp` are still absent files.

### Phase 3 — SMTP
`SmtpTransport` (interface), `SocketTransport`, `MailAdapters\Smtp`.

The transport interface exists so that this phase is testable at all: the adapter's tests
drive a scripted fake transport that returns canned server responses and records the exact
command sequence, so the whole conversation — `EHLO`, `STARTTLS`, `AUTH`, `MAIL FROM`,
`RCPT TO` per recipient, `DATA`, dot-terminated body, `QUIT` — is asserted without opening a
socket. `SocketTransport` is the thin `stream_socket_client` shim behind it.

Covered by tests: `HELO` fallback, both auth mechanisms, a `STARTTLS`-refusing server
raising rather than downgrading, dot-stuffing, multi-recipient envelopes, and each server
response class mapping to the right `FailureScope` — `421`/`45x` to `Mailer`, `550` on a
recipient to `Message`.

### Phase 4 — Amazon SES
`MailAdapters\AmazonSes`, shaped by whichever of §6's SES forks Marshal picks.

Attachment-bearing messages route through `Content.Raw.Data` using Phase 1's `MimeMessage`;
plain ones use `Content.Simple`. Both paths tested, plus SES's `__type`-carrying error bodies
mapped to scopes.

### Phase 5 — `MailerPool`
The failover composite, and the only phase that touches multi-mailer at all.

Tests are the centre of gravity for this release, because this is where a wrong decision is
invisible in production: first mailer succeeds and the rest are never called; a
`FailureScope::Mailer` failure advances and the second's result is returned with both
attempts recorded; a `FailureScope::Message` failure stops immediately and the second is
**never called**; every mailer failing raises an exception naming all of them and carrying
each cause; an empty pool is refused at construction, not at send.

**Release gate:** all five phases green, `CHANGELOG.md` complete, docs in §5 updated, and a
`monad/skeleton` `create-project` against this working copy sending one real message through
a Mailtrap sandbox inbox and one through the pool with a deliberately broken primary.

---

## 5. Documents to update on approval

| Document | Change |
|---|---|
| `ReleaseNotes_1.6.0.md` | New. §2 of this plan becomes its resolved-decisions section. |
| `CLAUDE.md` | Add 1.6.0 to the source-of-truth list, with the three load-bearing decisions (§2.2 no shared constructor, §2.4 the failover axis, §2.5 at-least-once) called out as the other releases' are. |
| `API_Contracts.md` | New section: `Mail`, the `Mail\*` value objects, seven adapter constructors, `MailerPool`. |
| `Architecture.md` | §7 amended per §2.2 — an abstraction defines the contract; a shared constructor only when every implementation shares it. |
| `DeploymentTopology.md` | **Outbound network dependencies.** SMTP on 587/465 is the first Clarity service needing egress on a port other than 443 — a genuinely new deployment fact for anyone running Clarity behind a restrictive egress policy. Also note that a pool's outbound set is the union of its members'. |
| `RepoMap.md` | The `src/Services/Mail*` trees; `config/mail.php` finally has a defined content. |
| `TestingStrategy.md` | Tier placement. Mail carries credentials and sends on the password-reset path, so adapter auth handling and `MailerPool`'s scope logic belong in the security-critical tier. |
| `CHANGELOG.md` | Per phase, per `ReleasePolicy.md`. |
| `CrossRepoContracts.md` | **No change** — §2.8, no new command and no new table. |
| `DDL.sql` | **No change** — §2.8. |
| `composer.json` | **No change** — §2.13; `ext-openssl` is already required. |

---

## 6. Open forks — Marshal's call

Per `CLAUDE.md`: where two approaches are genuinely defensible, both are presented rather
than one being picked silently.

**Fork A — SES credentials.** *This is the one that most changes the release's size.*
- **(i) Injected client object** — accept any object exposing `sendEmail()`, the real
  `Aws\SesV2Client` method shape, exactly as `Files` accepts an S3-client-shaped object
  without depending on `aws/aws-sdk-php`. Same repo, same vendor, same problem, already
  solved once. The adapter stays small; applications using SES likely have the SDK anyway.
- **(ii) In-house SigV4** — sign requests directly with the existing `Utils\HMAC`. No
  dependency for anyone, matching the `CronExpression` decision.
- **Recommended: (i).** The `CronExpression` analogy is tempting but does not hold. Five
  integer fields either parse or do not; SigV4 canonicalisation is a security protocol with
  several ways to be subtly wrong that fail as an opaque `403` rather than a clear error, and
  it must stay correct for the life of a major version. `Files` already chose this and the
  choice has held.

**Fork B — SMTP as a distinct adapter.** SES can be reached through its SMTP interface, which
would make `AmazonSes` unnecessary. The task lists SES and SMTP as separate requirements, so
this plan builds both — SES's API gives message ids, configuration sets and typed errors that
its SMTP endpoint does not. Flagged only so the redundancy is a choice rather than an oversight.

**Fork C — a `mail:test` command.** One command that sends a fixed message through the
configured mailer and prints what happened, so a developer can verify credentials without
writing a script. Genuine developer empathy; also the nineteenth built-in command and a
`CrossRepoContracts.md` §3 addition, for something four lines of a scratch script would do.
**Recommended: no**, on `CLAUDE.md`'s "if it's not necessary, don't implement it" — and it
stays available as a later semver-minor if the need proves real, which is the cheaper
direction to be wrong in.

**Fork D — retry within a single mailer before advancing.** A pool of one gets no retry at
all under this plan, and a `429` from the only configured mailer fails immediately. Adding a
bounded backoff inside `send()` would help, but it also multiplies the double-send window
in §2.5 and puts a sleep on the request path. **Recommended: not in 1.6.0.** Ship failover
first, and let real operational evidence decide whether per-mailer retry is worth its cost.

---

## 7. Acceptance gate

1. All five phases complete, `vendor/bin/phpunit` green, no skipped tests.
2. Seven adapters present; each sends, and each classifies its provider's auth failure as
   `Mailer` and its malformed-recipient failure as `Message`.
3. `MailerPool` proven to advance on `Mailer`, stop on `Message`, record every attempt on
   `SentMessage`, and refuse an empty pool at construction.
4. `MimeMessage` output verified byte for byte against RFC 5322 for all five shapes: text
   only, HTML only, both (`multipart/alternative`), both with a file attachment
   (`multipart/mixed`), and HTML with an inline `contentId` image (`multipart/related`).
   Plus the §2.12 guarantee: no output, in any shape, contains a `Bcc` header.
5. No new Composer `require` entry. No `DDL.sql` change. No new command. No file in `src/`
   outside the trees in §3.
6. Every document in §5 updated; `CHANGELOG.md` complete under a 1.6.0 heading.
7. Skeleton smoke test: one message through a Mailtrap sandbox inbox, and one through a pool
   whose primary is deliberately misconfigured, landing via the secondary with the primary's
   failure recorded in `$sent->attempts`.
