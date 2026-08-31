<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\SentMessage;

/**
 * Shared contract every mailer (`Services\MailAdapters\*`) implements: one send() that
 * translates a provider-agnostic Message into a provider's own dialect and back into a
 * SentMessage.
 *
 * **This class declares no constructor**, and that is a deliberate departure from `LLM` and
 * `Checkout`, which both fix `(string $apiKey, HttpClient $httpClient)` on the base
 * (ReleaseNotes_1.6.0.md §2.2). Mail is the first Clarity abstraction where that shape is
 * false for its own implementations: SMTP has no API key and no HttpClient at all — it has
 * a host, a port, credentials and an encryption mode, and it speaks to a socket; SES has an
 * access key *and* a secret *and* a region; Mailgun has a key *and* a sending domain *and* a
 * region. Forcing those through a two-argument base would mean an SMTP adapter keeping its
 * password in a property named $apiKey and an $httpClient it never uses — a lie in the type
 * signature told to preserve a symmetry that is only skin deep. So each adapter declares the
 * constructor its provider actually requires, and the six that speak HTTP share
 * `MailAdapters\SpeaksHttpApi` instead.
 *
 * There is exactly one abstract operation, and adding a second later is **semver-major** —
 * the rule `ReleaseNotes_1.3.0.md` §2.4 set for Checkout's four, which binds harder here
 * because there is only one to break. A capability only some providers have — Postmark's
 * message streams, SendGrid's templates, SES's configuration sets — is a public method on
 * the adapter that has it, never a widening of this contract.
 *
 * `Mail\MailerPool` extends this class, so a pool *is* a mailer: application code holds one
 * type whether it was handed one adapter or seven, and switching between them is a one-line
 * change in the configuration that built it.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class Mail
{
    /**
     * Hand the message to this mailer.
     *
     * @throws MailException always carrying a FailureScope saying whether the fault was this
     *     mailer's (try another) or the message's (every mailer would say the same).
     */
    abstract public function send(Message $message): SentMessage;

    /**
     * The identifier stamped onto every SentMessage and Attempt this mailer produces (e.g.
     * 'postmark'), and used in its own error messages.
     *
     * Public rather than protected: a pool records it on an Attempt for every member it
     * tries, including the ones that failed, and that trail is the only record of failover
     * anywhere in Clarity.
     */
    abstract public function mailerName(): string;
}
