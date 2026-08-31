<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

/**
 * The byte-level half of an SMTP conversation, kept behind an interface so
 * `MailAdapters\Smtp` can be tested against a scripted server rather than a real socket.
 *
 * Without this seam the SMTP adapter would be the one part of Mail with no way to assert
 * its own protocol handling — and the protocol handling is the entire adapter. A test drives
 * canned replies through a fake and asserts the exact command sequence; `SocketTransport` is
 * the thin `stream_socket_client` shim that does it for real.
 *
 * **Every method throws `MailException` scoped `Mailer` on failure**, never a raw PHP
 * warning or a `false` return. A connection that is refused, times out, or is closed by the
 * peer mid-conversation is precisely the situation a second mailer exists to survive, and it
 * is never the message's fault.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
interface SmtpTransport
{
    /**
     * Connect. $implicitTls wraps the socket in TLS before the greeting, as port 465 expects;
     * STARTTLS on 587 connects in the clear and upgrades later through startTls().
     *
     * @throws MailException scoped Mailer if the connection cannot be established.
     */
    public function open(string $host, int $port, int $timeoutSeconds, bool $implicitTls): void;

    /**
     * Read one CRLF-terminated reply line, without its terminator.
     *
     * @throws MailException scoped Mailer on timeout, or if the peer closed the connection —
     *     an empty read must never be returned as an empty string, because a caller reading
     *     a multi-line reply would loop on it forever.
     */
    public function readLine(): string;

    /**
     * @throws MailException scoped Mailer if the bytes cannot be written.
     */
    public function write(string $data): void;

    /**
     * Upgrade an established plaintext connection to TLS, after a `220` to `STARTTLS`.
     *
     * @throws MailException scoped Mailer if the handshake fails.
     */
    public function startTls(): void;

    /**
     * Release the connection. Must be safe to call on an already-closed or never-opened
     * transport, because the adapter calls it from a `finally` on every path.
     */
    public function close(): void;
}
