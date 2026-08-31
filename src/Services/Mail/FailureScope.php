<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

/**
 * Whose fault a send failure was — the only question `MailerPool` asks before deciding
 * whether to try the next mailer (ReleaseNotes_1.6.0.md §2.4).
 *
 * Deliberately not a list of HTTP status codes, which is wrong in both directions. A `401`
 * is `Mailer`: bad credentials on one provider are precisely when a second provider,
 * holding a different credential, should take the message — a pool that gave up there would
 * fail exactly when it was configured to help. A malformed recipient is `Message`: it is
 * invalid at all seven providers, so failing it over buys seven round trips, seven
 * timeouts' worth of latency, and a final error naming the last mailer tried rather than
 * the actual fault.
 *
 * Each adapter classifies its own provider's errors, because only it knows what that
 * provider's error bodies mean.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum FailureScope
{
    /**
     * This mailer could not take the message, but another might: authentication rejected,
     * a 5xx, rate limiting, a suspended account, a refused connection, a timeout.
     *
     * Also the classification for anything unrecognised. Guessing `Mailer` wrongly costs one
     * wasted round trip on the next mailer; guessing `Message` wrongly costs a message that
     * is never sent because a provider returned something nobody anticipated.
     */
    case Mailer;

    /**
     * The message itself is the problem, and every other mailer would reject it the same
     * way: a malformed sender, an invalid recipient, no body, an oversized attachment.
     */
    case Message;
}
