# Monad Clarity 1.6.0 Release Notes

**Status:** FROZEN — canonical specification for the 1.6.0 release.
This document is the source of truth for WHAT ships in 1.6.0. It does not restate 1.0.0,
1.2.0, 1.3.0, 1.4.0 or 1.5.0; those remain frozen for what they specified.

> **Scope note.** 1.5.0 was the first release to add a service the frozen 1.0.0 spec never
> contemplated. 1.6.0 is the second, and §2.1 records that expansion rather than assuming it —
> the scope was approved by Marshal on 2026-08-31, together with the SES decision in §2.14.
> Nothing existing changes: no signature moved, no table definition moved, no command was
> renamed, and an application that never constructs a mailer is byte-for-byte unaffected.

## 1. What ships in 1.6.0

### 1.1 `Services\Mail`

The abstraction seven mailers implement — one operation, `send()`, taking a
provider-agnostic `Mail\Message` and returning a `Mail\SentMessage`.

```php
// One mailer. The ordinary case.
$mail = new MailAdapters\Postmark($serverToken, new HttpClient());
$sent = $mail->send($message);

// Several, in priority order. Postmark first; Resend only if Postmark cannot take it.
$mail = new Mail\MailerPool([$postmark, $resend, $smtp]);
$sent = $mail->send($message);

$sent->mailer;        // 'resend' — who actually took it
$sent->failedOver();  // true — worth alerting on
$sent->attempts;      // every mailer tried, ending with the one that succeeded
```

`MailerPool` extends `Mail`, so a pool *is* a mailer: application code holds one type
whether it was handed one adapter or seven.

### 1.2 The seven adapters

| Adapter | Endpoint | Auth | Body |
|---|---|---|---|
| `MailAdapters\Mailtrap` | `send.api.mailtrap.io/api/send` | `Api-Token` header | JSON |
| `MailAdapters\Postmark` | `api.postmarkapp.com/email` | `X-Postmark-Server-Token` header | JSON |
| `MailAdapters\Mailgun` | `api.mailgun.net/v3/{domain}/messages` | HTTP Basic `api:{key}` | multipart/form-data |
| `MailAdapters\AmazonSes` | injected `SesV2Client`-shaped object (§2.14) | the client's own | `sendEmail()` array — `Simple`, or `Raw` MIME when there are attachments **or custom headers** |
| `MailAdapters\Resend` | `api.resend.com/emails` | `Authorization: Bearer` | JSON |
| `MailAdapters\SendGrid` | `api.sendgrid.com/v3/mail/send` | `Authorization: Bearer` | JSON |
| `MailAdapters\Smtp` | any host, 587/465/25 | AUTH PLAIN / LOGIN | RFC 5322 over a socket |

An unbuilt adapter is an **absent file, never a stub** — the rule the eight remaining
`CheckoutAdapters` follow.

### 1.3 The supporting classes

`Mail\Address`, `Mail\Attachment`, `Mail\Message`, `Mail\SentMessage`, `Mail\Attempt`,
`Mail\FailureScope`, `Mail\MailException`, `Mail\Header`, `Mail\MimeMessage`,
`Mail\SmtpTransport`, `Mail\SocketTransport`, `Mail\SmtpEncryption`, `Mail\MailerPool`.

Most are value objects; `MailerPool`, `SmtpTransport` and `SocketTransport` are behavioural.
Two are additions to the plan's inventory, recorded here so it stays true:

- **`Mail\Header`**, extracted during Phase 1 once the injection guard of §2.13 turned out to
  be needed by three classes rather than one. It owns both header-safety rules: what may not
  go into a header at all, and what must be RFC 2047 encoded before it can.
- **`Mail\SmtpEncryption`**, added during Phase 3. §2.11 requires the no-encryption case to be
  *named at the call site*, and an enum is how a name is spelled. `encryption:
  SmtpEncryption::None` cannot be typed by accident; a `bool $secure = true` left false in a
  config file sends a password in the clear and looks like nothing at all.

### 1.4 No table, no command

Unlike Checkout and Scheduler, Mail owns no persistent state — see §2.8. `DDL.sql` is
untouched, `CrossRepoContracts.md` §3's command list is untouched, and the nineteen built-in
commands stay nineteen.

## 2. Resolved specification decisions

### 2.1 A second service the frozen 1.0.0 spec never named

`ReleaseNotes_1.0.0.md` §1–§31 specifies no mail, mailer, SMTP, or outbound-message
component. The closest thing to a mention is its *"email verification hooks"* and *"password
reset tokens"* lines in Session — hooks with nothing on the other side of them — and a
dangling `config/mail.php` in `RepoMap.md`'s skeleton tree, a file the spec never says what
to put in.

§9's *"the roster is illustrative"* hatch from `ReleaseNotes_1.3.0.md` §2.1 does not reach
this. That hatch operates **inside an abstraction §9 already specified** and permits a
gateway the spec did not name; there is no equivalent for a service the spec never
contemplated. So the expansion was put to Marshal and approved, exactly as 1.5.0's was.

The demand was already in the codebase, as it was for the Scheduler: Clarity could mint a
signed URL for a password reset and had no way to send it anywhere.

**Semver-minor.** Purely additive.

### 2.2 `Mail` fixes no constructor — the deviation from LLM and Checkout

`LLM` and `Checkout` both declare `(string $apiKey, HttpClient $httpClient)` on the abstract
base. That works because every LLM provider and every payment gateway is an HTTP API reached
with one bearer credential. **Mail is the first Clarity abstraction where that is false**:

- `Smtp` has no API key and no `HttpClient` — it has a host, port, credentials and an
  encryption mode, and it speaks to a socket.
- `AmazonSes` has an injected client object, not a key at all (§2.14).
- `Mailgun` has a key **and** a sending domain **and** a region base URI.
- `Mailtrap` forks on whether it addresses the live API or a sandbox inbox.

Forcing these through a two-argument base would mean an SMTP adapter keeping its password in
a property named `$apiKey` and an `$httpClient` it never uses — a lie in the type signature
told to preserve a symmetry that is only skin deep, and exactly the "abstraction for
aesthetics" `CLAUDE.md` forbids.

So `Mail` declares abstract methods and nothing else. The HTTP niceties the API adapters
share live in `MailAdapters\SpeaksHttpApi`, applied by the adapters that speak HTTP and not
by `Smtp`. Precedent: 1.4.0 extracted `CheckoutAdapters\SpeaksPaddle` when two adapters
shared a provider's dialect; this is the same move one level up.

`mailerName()` is **public**, not protected: a pool records it on an `Attempt` for every
member it tries, and that trail is the only record of failover anywhere in Clarity.

**It names the provider and the mode, not the instance.** Two `Postmark` adapters holding
different server tokens both return `'postmark'`, because from the recipient's side and the
operator's they are the same provider having a good or a bad day. `Mailtrap::sandbox()` is
the one place a mode changes the name — to `'mailtrap_sandbox'` — because the difference
there is not a credential but whether the mail reaches a human at all, and "it sent fine in
staging" is a far shorter conversation when the record says which one sent it.

Two consequences, settled here because Phase 5 needs them and because applications will
start logging `$sent->mailer` the day it ships. **`MailerPool` does not refuse duplicate
mailer names** — a primary and a standby account at the same provider is a legitimate pool,
and it is exactly what an application does when one sending domain is rate-limited. And a
name is therefore not a key: the pool identifies its members by position, and `attempts`
is an ordered list rather than a map.

### 2.3 Failover is why `Mail` reverses `LLM` §11.4

`ReleaseNotes_1.0.0.md` §11.4 excludes *"automatic retries across providers"* from the LLM
service. Mail does the opposite, deliberately:

> Two LLM providers are not interchangeable. Anthropic and OpenAI, handed the same prompt,
> return different text — so a silent failover changes the product's output, and an
> application that failed over without knowing shipped an answer it did not choose.
> **Two mailers are interchangeable.** A delivered email is a delivered email; the recipient
> cannot tell which of seven providers carried it. Failover changes the delivery path and
> nothing observable.

And the operational half: an LLM outage degrades a feature, while a mail outage silently
breaks password reset, email verification and receipts — the paths a user cannot route
around, and the ones nobody notices are down until support asks why signups stopped.

### 2.4 Failover keys on *whose fault it is*, not on the status code

The obvious implementation — fail over on 5xx and timeouts, give up on 4xx — is wrong in
both directions, and this is the load-bearing detail of the pool.

Giving up on a `401` is wrong. Bad credentials on Mailgun are **precisely** when Postmark
should take the message: the point of a second mailer is that it holds a *different*
credential. A pool that refuses to fail over on an auth failure fails exactly when it was
configured to help.

Failing over a malformed recipient is also wrong. That message is invalid at all seven
providers, so failing it over buys seven round trips, seven timeouts' worth of latency, and
a final error naming the last mailer tried rather than the real fault.

So the axis is a typed enum, never a status-code list:

```php
enum FailureScope { case Mailer; case Message; }
```

| `FailureScope::Mailer` — try the next | `FailureScope::Message` — stop |
|---|---|
| Authentication rejected, credential expired | `From` malformed or not a valid mailbox |
| `5xx` from the provider | A recipient address is syntactically invalid |
| `429` / rate limited / over quota | No recipient, or no body |
| Connection refused, DNS failure, TLS failure | Attachment over a universal size limit |
| Read or connect timeout | Provider rejects the payload as malformed |
| Account suspended or sending paused | |

Each adapter classifies its own provider's errors, because only it knows what its error
bodies mean. Anything unrecognised is `Mailer`: guessing that way wrongly costs one wasted
round trip, guessing the other way wrongly costs a message that is never sent.

`MailException` therefore takes its scope as a **required** constructor argument with no
default. An adapter author who forgets to classify a failure gets a type error at the throw
site rather than a silently wrong failover decision at 3am.

### 2.5 The guarantee is **at least once, not exactly once**

The honest inverse of the Scheduler's §2.4, stated for the same reason.

If Postmark accepts a message and the connection then times out before its response is read,
the pool cannot distinguish "not sent" from "sent, acknowledgement lost". It fails over, and
the recipient gets the email twice. **No cross-provider idempotency key exists** — each
provider mints its own message id and none will honour another's.

Applications that must not double-send — an invoice, a one-time code that invalidates the
previous one — should send through a single adapter and handle the failure themselves. The
pool is for messages where a duplicate is a mild annoyance and a silent non-delivery is a
real failure, which is most transactional mail and all of it that matters at 3am.

Same species of honesty as 1.4.0 §2.5's weaker-monotonic-guard admission. Overstating a
guarantee is worse than stating a narrow one clearly.

### 2.6 "Whether to enable multi-mailer" is a composition choice, not a flag

No toggle inside Clarity, no `enabled` key, no configuration schema. It is answered by which
object `config/mail.php` constructs — a single adapter, or a `MailerPool`. Both have the
type `Mail`; application code is identical either way.

**Priority is array order.** Not an integer field to be sorted — the list reads top to bottom
in the order it will be tried, which is the only ordering a reader could infer anyway. An
integer priority would add a sort, a tie-break rule, and a way to write a configuration whose
intent is ambiguous, to express something the array already says.

This keeps `config/mail.php`'s shape a **skeleton** concern. `CrossRepoContracts.md` says
nothing about `config/llm.php` and for the same reason says nothing about `config/mail.php`:
Clarity's contract is the constructor signatures, not the file that calls them.

### 2.7 `SentMessage` carries the whole attempt trail

With no table (§2.8), the return value is the **only** record that failover happened. A pool
quietly falling through to its third mailer for a week has converted an outage into an
invisible degradation, and the bill arrives when the third one fails too.

`$attempts` **includes the successful final attempt**, always as its last element — so
`count($sent->attempts)` is the number of mailers tried and a single-adapter send returns
exactly one. A trail that excluded the winner would make the common case an empty array and
the count off by one in every reading of it. `failedOver()` and `failures()` are the two
questions worth asking of it.

### 2.8 No table, no `mail:install`, no new command

Checkout and Scheduler each ship an opt-in `*:install` because each owns load-bearing
persistent state: a ledger that must survive a callback arriving twice, a run ledger that
*is* the cluster mutex.

Mail owns no such state. Sending is a request/response operation fully described by its
return value, and a delivery log is the application's own table and concern in exactly the
way `Files` metadata is. Adding a `mail_deliveries` table would be Clarity deciding what an
application must record about its own mail.

So: no command, no `DDL.sql` change, no `CrossRepoContracts.md` §3 change.

### 2.9 Mail does not render templates

`Services\View` renders templates; `Mail\Message` takes an HTML string and a text string,
and the application passes the result of rendering. Coupling the two would make every
adapter's test depend on a view path and a filesystem, and would give Clarity an opinion
about where an application keeps its email templates — which `CLAUDE.md`'s *Freedom* says it
does not get.

### 2.10 Instance-based, like LLM and Checkout

`Route`, `Console` and `Scheduler` are static because registrations arrive from a routes file
at boot. Mail has no registration phase: it is constructed with credentials and used, which
is the `LLM`/`Checkout`/`Files` shape. `MailerPool` is the composition root, built once in
`config/mail.php`; a static facade would add a global credential registry to hold exactly
one object.

### 2.11 SMTP is deliberately narrow

- **`AUTH PLAIN` and `AUTH LOGIN` only.** Every provider offering SMTP supports one over TLS.
  `CRAM-MD5` exists to protect a password on an unencrypted link — a worse answer to a
  problem TLS already solved, and implementing it invites use of the plaintext link it
  implies is acceptable.
- **`STARTTLS` required by default**, with an explicit named opt-out for a local relay such
  as Mailpit. Never a silent downgrade: a server failing to offer `STARTTLS` when it was
  expected is an error, because that is what an interception looks like.
- **Implicit TLS on 465** supported alongside `STARTTLS` on 587.
- **`EHLO` with `HELO` fallback.**
- **CRLF and dot-stuffing** per RFC 5321 §4.5.2. An unstuffed body line beginning with `.`
  truncates the message there — silently, and only for the messages unlucky enough to contain
  one. (Base64 body encoding, §2.12, means this can never fire in practice; it is implemented
  anyway, because "cannot happen" is a property of today's encoder choice, not of SMTP.)
- **No pipelining, no `BDAT`, no DSN, no connection pooling.**

### 2.12 One `Message` is one email, and `MimeMessage` never emits a `Bcc:` header

Recipients are where the seven providers diverge most. Postmark takes `To`/`Cc`/`Bcc` as
comma-separated **strings**; SendGrid nests them in `personalizations[]`; SES splits them
into `Destination.{To,Cc,Bcc}Addresses`; SMTP names every recipient in `RCPT TO` regardless
of which field it came from. The contract is fixed here rather than decided five ways across
the adapter phases.

**A `Message` with three `to` addresses is one email whose three recipients can see each
other** — not three separate sends. SendGrid's `personalizations[]` makes the other reading
expressible, so its adapter sends exactly **one** personalization holding all recipients. An
application wanting three private emails builds three `Message` objects, which is also the
only way to give each its own body.

**`MimeMessage` never writes a `Bcc:` header.** Blind recipients travel in the SMTP envelope
(`Message::recipients()`) and nowhere else. This is not a formatting nicety: a `Bcc:` header
in the transmitted document discloses every blind recipient to every other recipient, and it
is the one failure of this service that is both silent and unrecoverable — by the time anyone
notices, the disclosure has happened. `Message` additionally refuses `Bcc` (and every other
structural header) as an application-supplied extra header, closing the same hole at the
front door.

Every body part is **base64**, not quoted-printable: base64 has no line-length hazards, no
trailing-whitespace rules, and cannot produce a line beginning with `.` for SMTP to mangle.
The whole class of encoding bug disappears for a size cost irrelevant to transactional mail.

Inside `multipart/alternative`, **text precedes HTML** — RFC 2046 §5.1.4 has clients render
the *last* part they understand, so that order is what makes HTML win.

### 2.12a A rejected recipient abandons the whole message

Added during Phase 3; `ReleaseNotes_1.0.0.md` had no occasion to consider it and §2.11 did
not reach it.

If a relay accepts three `RCPT TO` commands and refuses the fourth, `MailAdapters\Smtp`
**abandons the entire message** — it never issues `DATA` — and raises
`FailureScope::Message` naming the address that was refused.

Delivering to the three that were accepted is the kinder-looking option and the wrong one.
A pool that then failed the message over would send it a second time to everyone who had
already received it, and that duplicate is one this library *chose* to create. §2.5's
admission is about transport uncertainty — an acknowledgement lost after the fact — not a
licence to manufacture partial deliveries. One bad address is a defect in the caller's data,
better reported than half-honoured.

The scope is `Message` because a refused mailbox is refused everywhere. This is the only
adapter that can discover the problem one recipient at a time; the API providers reject the
whole payload or accept it.

### 2.13 Header injection is refused at construction, and non-ASCII is encoded

`Message` is assembled from application data: a display name, a subject carrying a username,
a reference the customer typed. Headers are separated by CRLF, so a single unescaped newline
in any of those turns one header into two, and the second is entirely the attacker's — a
`Bcc:` of their choosing, a rewritten `Reply-To`, a forged `From`. That defeats §2.12's
guarantee by a route `MimeMessage` alone cannot close.

So `Mail\Header::assertNoInjection()` refuses CR, LF and NUL in every header-bound value, and
it runs **at construction of the value object** — in `Address` for the email and display
name, in `Message` for the subject and every custom header name and value, in `Attachment`
for the filename and content type. Refused, never sanitised: silently stripping the newline
would send something the caller did not write. NUL is included because it truncates in
C-implemented layers below PHP, where an injected header after it may be invisible to
inspection here and present on the wire.

The second half of the same class: a subject with an em-dash or a display name with an accent
cannot be written literally into a header. `Header::encodeWord()` applies RFC 2047
(`=?UTF-8?B?…?=`) for MIME output, and never folds inside an encoded-word, since a split one
is not decodable. Only MIME needs this — the JSON APIs take UTF-8 natively and receive the
raw string.

`TestingStrategy.md` places these in the security-critical tier.

### 2.14 SES takes an injected `SesV2Client`-shaped object

**Decided by Marshal, 2026-08-31.** `MailAdapters\AmazonSes` accepts any object exposing the
real `Aws\SesV2Client` method shape, rather than signing requests itself — the decision
`Files` already made for S3, in the same repo, against the same vendor, for the same reason.

The alternative was in-house SigV4 using `Utils\HMAC`, on the `CronExpression` precedent of
refusing a dependency. That analogy does not hold: five integer fields either parse or do
not, whereas SigV4 canonicalisation is a security protocol with several ways to be subtly
wrong that surface as an opaque `403` rather than a clear error, and it would have to stay
correct for the life of a major version.

No `aws/aws-sdk-php` dependency is added — the adapter is written against the method shape,
so the real SDK needs no translation and tests use a plain fake.

Two signature consequences, both deliberate. The client is **required and non-nullable**,
unlike `Files`' `?object $s3Client`: Files has a filesystem adapter to fall back to, so null
is a mode there, whereas here it is simply a broken mailer. And this is the only adapter with
**no `$timeoutSeconds`** — the injected client owns the transport and therefore the timeouts,
and accepting a value this class could not enforce would be a lie in the signature. An
application tuning a pool's timeout budget (§2.15) sets it on the client it constructs.

Since the adapter cannot type-hint `AwsException`, it catches `Throwable` around **the
`sendEmail()` call alone** and reads the code through `method_exists($e, 'getAwsErrorCode')`.
Narrowing the `try` that far matters twice over: a `TypeError` from this library's own payload
construction must not be reported to a pool as a provider failure, and a `MailException`
raised by the tag guard below must not be re-wrapped from `Message` to `Mailer` and sent
around six more providers.

One tag rule is SES-specific and enforced here rather than in `Message`: SES `EmailTags`
accept only letters, digits, underscores and dashes, where the other five mailers take any
string. A tag like `welcome email` would otherwise send everywhere except SES and surface as
an opaque `InvalidParameterValue`, so it is refused with a message naming the tag. Unlike the
one-tag limit of Phase 2 — which two adapters shared, and which therefore lives in the trait —
this constraint belongs to one provider.

### 2.15 Three further decisions, resolved by recommendation

Marshal approved the scope and settled §2.14; the remaining three forks took the plan's
recommended answers and are recorded here so they are visible rather than assumed.

- **SES and SMTP are both built**, though SES is reachable through its own SMTP interface.
  Its API returns message ids, configuration sets and typed errors that the SMTP endpoint
  does not.
- **No `mail:test` command.** `CLAUDE.md`'s "if it's not necessary, don't implement it"; it
  remains available as a later semver-minor if the need proves real, which is the cheaper
  direction to be wrong in.
- **No per-mailer retry before advancing.** A bounded backoff inside `send()` would widen the
  double-send window of §2.5 and put a sleep on the request path. Ship failover first and let
  operational evidence decide.

  The timeout budget is the strongest form of that argument. Each adapter takes its own
  `$timeoutSeconds`, defaulting to 30, and a pool tries its members in series — so five
  mailers that all time out is a 150-second worst case on a request path, before any retry
  multiplies it. An application putting more than two or three mailers in a pool should lower
  each one's timeout rather than accept the sum, and that is a constructor argument it
  already has.

### 2.16 No new Composer dependency

1.4.0 added recurring billing without one; 1.5.0 wrote a cron parser in-house rather than
take one. The five HTTP adapters are a JSON or form POST over the existing `HttpClient`;
`AmazonSes` delegates transport entirely to the client object it is handed (§2.14), so it
adds no dependency of its own either; the SMTP adapter is a socket conversation over
`ext-openssl`, already required; `MimeMessage` is the
RFC 5322 that would otherwise pull in a package to be tracked and audited for the life of a
major version. **No `require` entry changes.**

## 3. Explicitly NOT in 1.6.0

- **A queue, or asynchronous sending.** `send()` blocks until the provider answers. Sending
  off the request path is scheduling work, and `Services\Scheduler` already exists to do it.
- **Retry within a single mailer.** §2.15. Failover ships first; a bounded backoff would widen
  §2.5's double-send window and put a sleep on the request path.
- **Inbound mail, bounce handling, or webhook parsing.** Every provider signs and shapes its
  events differently, and none of it is needed to *send*. This is where Checkout's
  `parseCallback()` has no counterpart, deliberately.
- **A delivery log or `mail_deliveries` table.** §2.8. `SentMessage` describes the send fully,
  and what an application records about its own mail is its own concern, as `Files` metadata is.
- **Template rendering.** §2.9. `Services\View` renders; `Mail\Message` takes strings.
- **A `mail:test` command.** §2.15. The nineteen built-in commands stay nineteen.
- **Address verification, list management, suppression lists.** Provider-side concerns reached
  through provider-side APIs, not through a sending abstraction.

## 4. Compatibility

Additive throughout, and semver-minor. No existing signature changed, no value object gained
or lost a property, no shipped table definition moved. `Services\Console::run()` is untouched,
the three frozen skeleton entry points are unaffected, and the nineteen built-in command names
are unchanged — this release adds none.

**`CrossRepoContracts.md` is untouched**, which is unusual for a release of this size and is
the point of §2.8: Mail owns no table and ships no command, so it crosses no boundary the
skeleton is promised. `DDL.sql` and `composer.json`'s `require` block are likewise unchanged.

`Architecture.md` §7 is amended rather than extended — it now records that a facade defines a
shared constructor only when every implementation genuinely shares one (§2.2). That is a
change to written rationale, not to any shipped signature.

### 4.1 Upgrading from 1.5.0

Nothing is required. An application that never constructs a mailer is byte-for-byte unaffected.

To adopt Mail:

1. Construct an adapter in `config/mail.php` — or a `MailerPool` of several, in the order they
   should be tried. Both have the type `Services\Mail`, so nothing downstream changes if you
   later switch between them.
2. Build a `Mail\Message` and call `send()`. Render any HTML through `Services\View` first;
   Mail takes strings (§2.9).
3. If you use SMTP, **check egress on 587 or 465** before trusting it. It is the first Clarity
   component needing a port other than 443, and a blocked port presents as a connect timeout
   classified as a mailer fault — which inside a pool reads as a provider outage rather than a
   firewall (`DeploymentTopology.md` §4).

If you pool, send one message through each member *directly* first. A pool exists to reach its
later members when the earlier ones fail, so a standby whose credentials and egress have never
been exercised is a standby nobody has any evidence about.

## 5. Verification

- Full suite green: **1087 tests, 2333 assertions**, `composer lint` clean.
- Every adapter carries both classification cases the pool depends on — its provider's auth
  failure yielding `FailureScope::Mailer`, a rejected recipient yielding `Message` — because a
  scope decided wrongly is invisible until an outage.
- `MimeMessage` asserted byte-for-byte across all five body shapes, with CRLF discipline proved
  directly (no LF without a preceding CR, no CR without a following LF) rather than inferred.
- Header injection tested against CR, LF and NUL in every header-bound field, and every
  structural header refused as an application-supplied extra.
- `Smtp` driven through a scripted transport for the whole conversation, including `HELO`
  fallback, both auth mechanisms, a `STARTTLS`-refusing server raising rather than downgrading,
  and a test asserting the password reaches neither an exception message nor a stack trace.
- `Tests\Integration\MailFailoverTest` drives the real adapters through a real pool, since
  three units can each pass their own suite and still disagree with one another.

### 5.1 Verified live against a Mailtrap sandbox

Run 2026-08-31 against a real sandbox inbox, from a scratch script outside the repository with
the token supplied through the environment. No credential is committed, and this is
deliberately not an automated test (`TestingStrategy.md` keeps live provider calls out of the
suite).

- A message carrying a text body, an HTML body, an inline `cid:` image, a file attachment, a
  Cc, a Bcc, a Reply-To and a custom header **arrived intact**. The attachment's bytes decoded
  back byte-for-byte, and the delivered document nested `multipart/alternative` inside
  `multipart/related` inside `multipart/mixed`.
- **§2.12 held on the wire**: the delivered message carried no `Bcc` header and no occurrence
  of the blind recipient's address anywhere in the document. `Cc: "Doe, Jane" <cc@example.com>`
  arrived quoted, so the comma in the display name did not split one recipient into two.
- **Failover proved against two genuinely different failures**: a real Postmark `401` from an
  invalid server token, and a real `Connection refused` from `SocketTransport` against a dead
  port. Both were classified `Mailer`, both were passed over, and both messages arrived through
  the standby with the primary's reason recorded in `attempts`.
- **A `Message` fault stopped the pool dead**, and that message never appeared in the inbox —
  the end-to-end proof that §2.4's axis does what it claims rather than merely returning the
  right enum.

One thing the run surfaced that no mocked suite could: the sandbox plan rate-limits sends, and
its `429` was correctly classified `Mailer` — so a pool whose only remaining member is
rate-limited reports every mailer as failed. Correct behaviour, and a reminder that §2.15's
timeout budget is not the only shared resource a pool can exhaust.

### 5.2 Completed at tagging

`v1.6.0` was tagged on 2026-08-31 at `f553e12`. What follows is what was actually verified,
not what was intended — and everything below was exercised against the **published release
resolved from Packagist**, a `create-project` with `^1.0` resolving to `v1.6.0`, rather than a
path repository. A working copy proves the source is right; only the installed artefact proves
the shipped package is.

- **Docs merged before the tag**, which is what `ReleasePolicy.md` item 7 asks for and the
  order that matters: `monad-www` gained `services/mail.php` and a Services count of 18 in
  PR #8, merged before `v1.6.0` existed. Checkout shipped undocumented in 1.2.0 precisely
  because that order was not kept.
- **`export-ignore` confirmed in the installed vendor tree**, not inferred from
  `.gitattributes` — the file being correct and the archive being correct are two claims, and
  1.5.0's §5.2 made the same distinction. `resources/` and `CLAUDE.md` are absent from the
  distributed package; all 22 Mail source files are present.
- **Mail driven end to end from that installed copy.** A pool of a deliberately dead SMTP
  relay followed by a Mailtrap sandbox delivered through the sandbox, with the socket failure
  recorded on `attempts` — so failover is proved from the shipped artefact rather than from
  this working copy.
- **A GitHub Release was published, not only a tag.** `/changelog` on the website keys on
  `release/published`, which is why 1.2.0 never appeared there; 1.5.0's §5.2 recorded the same
  lesson and this release honoured it.
- **Packagist picked the tag up** through the auto-update webhook, with no manual update
  needed — `v1.6.0` was resolvable within minutes of the push.

Two checklist items were **not** completed at the moment of tagging. Both were closed
immediately afterwards, and are recorded here with the difference between what 1.5.0 proved
and what this release proved, rather than flattened into a tick:

- **Item 4, `php mitosis health` against the fresh `create-project` — green, on SQLite.** All
  five checks pass against the Packagist-resolved `v1.6.0`: configuration, database
  connectivity, writable storage, migration status, PHP extensions, exit code `0`. `setup` and
  `migrate` ran first and both succeeded. **1.5.0's §5.2 recorded MySQL and this records
  SQLite**, which is the narrower claim of the two and is stated as such; SQLite is one of the
  three drivers the skeleton supports and is what the skeleton's own development environment
  uses. Nothing in this release touches the database, so the driver is not load-bearing here —
  but the record says which one ran rather than implying the other.
- **Items 8 and 9 in the skeleton repository — done.** `RepoMap.md` is byte-identical to this
  repo's copy again; the drift was exactly 1.6.0's two new trees and nothing else. The
  skeleton's README Status line records that `monad/clarity ^1.0` now resolves to `1.6.0`,
  verified against a real `create-project` rather than assumed. `CrossRepoContracts.md` needed
  nothing, as §2.8 predicted.

One thing item 8's "worth a skim for staleness" clause turned up, which no diff would have
caught: the skeleton's `config/mail.php` still defined a `MAILER` constant that nothing read,
written when Clarity had no Mail service — the same dangling file §2.1 cites as evidence that
the demand for this release was already in the codebase. It now builds a `Services\Mail`, and
`MAIL_MAILERS` carries the §2.6 decision: one name for one mailer, a comma-separated list for
a pool in that order.
