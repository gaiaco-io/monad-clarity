<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

/**
 * How `MailAdapters\Smtp` protects the connection.
 *
 * An enum rather than a boolean because the third case must be *named at the call site*
 * (ReleaseNotes_1.6.0.md §2.11). `encryption: SmtpEncryption::None` cannot be typed by
 * accident and cannot be misread in review; a `bool $secure = true` left false in a config
 * file sends a password across the network in the clear and looks like nothing at all.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum SmtpEncryption
{
    /**
     * Connect in the clear on 587, then upgrade with `STARTTLS`. The default, and required:
     * a server that does not advertise STARTTLS is an error rather than a silent downgrade,
     * because a stripped advertisement is exactly what an interception looks like.
     */
    case StartTls;

    /** TLS from the first byte, as port 465 expects. */
    case ImplicitTls;

    /**
     * No encryption at all. For a local development relay — Mailpit, MailHog, a container on
     * localhost — and nothing else. Credentials and message bodies travel in plaintext.
     */
    case None;
}
