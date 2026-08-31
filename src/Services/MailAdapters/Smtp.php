<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\MailAdapters;

use InvalidArgumentException;
use Monad\Clarity\Services\Mail;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\MimeMessage;
use Monad\Clarity\Services\Mail\SentMessage;
use Monad\Clarity\Services\Mail\SmtpEncryption;
use Monad\Clarity\Services\Mail\SmtpTransport;
use Monad\Clarity\Services\Mail\SocketTransport;
use Throwable;

/**
 * Any SMTP relay, spoken directly.
 *
 * The one adapter with no `HttpClient` and no API key — the reason `Services\Mail` declares
 * no constructor at all (ReleaseNotes_1.6.0.md §2.2). It sends `MimeMessage`'s bytes after
 * `DATA`, which is also why that class was built in phase 1 rather than here.
 *
 * Deliberately narrow, per §2.11: `AUTH PLAIN` and `AUTH LOGIN` only, `STARTTLS` required by
 * default with an explicitly *named* opt-out, `EHLO` with a `HELO` fallback, and no
 * pipelining, `BDAT`, DSN or connection pooling. Command and response run in lockstep, one
 * connection per message.
 *
 * **Blind recipients reach the envelope and never a header.** `Message::recipients()` names
 * every address in `RCPT TO`, while `MimeMessage` writes no `Bcc:` — that split is the whole
 * mechanism behind §2.12, and this is the adapter where both halves are visible at once.
 *
 * @package Monad\Clarity\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Smtp extends Mail
{
    private const CRLF = "\r\n";

    /**
     * A reply is at most this many lines. A server answering `250-` forever — broken, or
     * hostile — would otherwise hold the process open until the request timed out.
     */
    private const MAX_REPLY_LINES = 128;

    /** @var array<string, list<string>> Extension keyword => its parameters. */
    private array $extensions = [];

    /**
     * @param string $ehloDomain The name this client announces. A bare hostname many relays
     *     will not accept; the sending domain is the safe answer and is what the From address
     *     already implies, so it defaults to that per-message rather than to gethostname().
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly SmtpEncryption $encryption = SmtpEncryption::StartTls,
        private readonly ?SmtpTransport $transport = null,
        private readonly int $timeoutSeconds = 30,
        private readonly ?string $ehloDomain = null,
    ) {
        if (trim($host) === '') {
            throw new InvalidArgumentException('Smtp requires a host.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(sprintf('Smtp port must be 1-65535, got %d.', $port));
        }

        if (($username === null) !== ($password === null)) {
            throw new InvalidArgumentException(
                'Smtp needs both a username and a password, or neither. One without the other is a '
                . 'half-written configuration that would connect and then fail to authenticate.'
            );
        }
    }

    public function mailerName(): string
    {
        return 'smtp';
    }

    public function send(Message $message): SentMessage
    {
        $transport = $this->transport ?? new SocketTransport();
        $this->extensions = [];

        $transport->open(
            $this->host,
            $this->port,
            $this->timeoutSeconds,
            $this->encryption === SmtpEncryption::ImplicitTls
        );

        try {
            $queueId = $this->converse($transport, $message);
        } finally {
            // Best-effort courtesy, then release the socket on every path. A connection leaked
            // on each failed send is invisible until the day it is not.
            $this->tryQuit($transport);
            $transport->close();
        }

        return SentMessage::delivered($this->mailerName(), $queueId);
    }

    /**
     * The whole conversation, in the order it happens.
     *
     * @return ?string The relay's queue id, when its final reply names one.
     */
    private function converse(SmtpTransport $transport, Message $message): ?string
    {
        $this->expect($transport, [220], 'greeting');

        $domain = $this->ehloDomain ?? $message->from->domain();

        $this->greet($transport, $domain);

        if ($this->encryption === SmtpEncryption::StartTls) {
            $this->upgrade($transport, $domain);
        }

        if ($this->username !== null) {
            $this->authenticate($transport);
        }

        $this->command($transport, sprintf('MAIL FROM:<%s>', $message->from->email));
        $this->expect($transport, [250], 'sender');

        foreach ($message->recipients() as $recipient) {
            $this->command($transport, sprintf('RCPT TO:<%s>', $recipient->email));

            // 251 is "not local, will forward" — an acceptance, not a warning.
            $this->expectRecipient($transport, $recipient);
        }

        $this->command($transport, 'DATA');
        $this->expect($transport, [354], 'data');

        $transport->write(self::stuff(MimeMessage::build($message)) . self::CRLF . '.' . self::CRLF);

        [, $text] = $this->expect($transport, [250], 'body');

        return self::queueIdIn($text);
    }

    /**
     * EHLO, falling back to HELO for the few relays that still answer only the latter.
     */
    private function greet(SmtpTransport $transport, string $domain): void
    {
        $this->command($transport, 'EHLO ' . $domain);

        [$code, , $lines] = $this->reply($transport);

        if ($code === 250) {
            $this->extensions = self::parseExtensions($lines);

            return;
        }

        $this->command($transport, 'HELO ' . $domain);
        $this->expect($transport, [250], 'greeting');

        // HELO advertises nothing, so nothing is assumed.
        $this->extensions = [];
    }

    /**
     * STARTTLS, then a second EHLO.
     *
     * The re-issue is mandatory and its result *replaces* the extension list rather than
     * merging into it: a relay legitimately advertises different capabilities once the
     * connection is private, and AUTH is commonly absent beforehand precisely so that a
     * client will not offer credentials in the clear. Merging would let a pre-TLS
     * advertisement decide a post-TLS action.
     */
    private function upgrade(SmtpTransport $transport, string $domain): void
    {
        if (!isset($this->extensions['STARTTLS'])) {
            throw MailException::mailer(sprintf(
                '%s did not offer STARTTLS, which this mailer requires. A relay that should support '
                . 'it and does not is indistinguishable from one whose advertisement was stripped in '
                . 'transit, so the connection is refused rather than continued in the clear. Pass '
                . 'SmtpEncryption::None if this really is a local relay.',
                $this->host
            ));
        }

        $this->command($transport, 'STARTTLS');
        $this->expect($transport, [220], 'STARTTLS');

        $transport->startTls();

        $this->extensions = [];
        $this->greet($transport, $domain);
    }

    /**
     * AUTH PLAIN, or AUTH LOGIN where that is all the relay offers.
     *
     * No credential — not the password, not its base64, not the command carrying it — is ever
     * placed in an exception message or passed to an exception constructor. `AUTH PLAIN`
     * transmits base64 of the password, so an adapter that reported "the command X failed"
     * would write that password into every log that caught the failure.
     */
    private function authenticate(SmtpTransport $transport): void
    {
        $mechanisms = array_map(strtoupper(...), $this->extensions['AUTH'] ?? []);

        if ($mechanisms === []) {
            throw MailException::mailer(sprintf(
                '%s offered no AUTH mechanism, but a username was configured.',
                $this->host
            ));
        }

        if (in_array('PLAIN', $mechanisms, true)) {
            $transport->write(
                'AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password) . self::CRLF
            );
            $this->expectAuth($transport);

            return;
        }

        if (in_array('LOGIN', $mechanisms, true)) {
            $this->command($transport, 'AUTH LOGIN');
            $this->expect($transport, [334], 'authentication');

            $transport->write(base64_encode((string) $this->username) . self::CRLF);
            $this->expect($transport, [334], 'authentication');

            $transport->write(base64_encode((string) $this->password) . self::CRLF);
            $this->expectAuth($transport);

            return;
        }

        throw MailException::mailer(sprintf(
            '%s offers only %s. This mailer speaks PLAIN and LOGIN over TLS, and does not implement '
            . 'CRAM-MD5 — it protects a password on an unencrypted link, which is a worse answer to a '
            . 'problem TLS has already solved.',
            $this->host,
            implode(', ', $mechanisms)
        ));
    }

    /**
     * A failed authentication is the *mailer's* fault, not the message's (§2.4): it is
     * precisely when a second mailer, holding a different credential, should take over.
     */
    private function expectAuth(SmtpTransport $transport): void
    {
        [$code, $text] = $this->reply($transport);

        if ($code === 235) {
            return;
        }

        throw MailException::mailer(sprintf(
            '%s rejected the credentials (%d %s).',
            $this->host,
            $code,
            $text
        ));
    }

    /**
     * A recipient the relay refuses is the message's fault, and **the whole message is
     * abandoned** rather than delivered to the addresses that were accepted.
     *
     * Proceeding with a partial envelope looks kinder and is worse: a pool that later fails
     * over would send the message a second time to everyone who *did* accept. §2.5's honesty
     * about duplicates covers transport uncertainty — a timeout after an unread acknowledgement
     * — not a duplicate this adapter chose to create. One bad address is a bug in the caller's
     * data, and it is better reported than half-honoured.
     */
    private function expectRecipient(SmtpTransport $transport, Address $recipient): void
    {
        [$code, $text] = $this->reply($transport);

        if ($code === 250 || $code === 251) {
            return;
        }

        throw new MailException(
            sprintf(
                '%s refused the recipient %s (%d %s). No part of the message was sent: delivering to '
                . 'the recipients that were accepted would mean sending it to them twice if a pool '
                . 'later fails this message over.',
                $this->host,
                $recipient->email,
                $code,
                $text
            ),
            self::scopeFor($code, 'recipient')
        );
    }

    /**
     * @param list<int> $accepted
     * @return array{0: int, 1: string, 2: list<string>}
     */
    private function expect(SmtpTransport $transport, array $accepted, string $stage): array
    {
        $reply = $this->reply($transport);

        if (in_array($reply[0], $accepted, true)) {
            return $reply;
        }

        throw new MailException(
            sprintf('%s refused at the %s stage (%d %s).', $this->host, $stage, $reply[0], $reply[1]),
            self::scopeFor($reply[0], $stage)
        );
    }

    /**
     * §2.4, for SMTP reply codes.
     *
     * `4xx` is transient and always the mailer's. `552` is the message exceeding a size limit,
     * which is near-universal, so every other relay would refuse it too. A `5xx` on the
     * envelope commands names an address the message carries. Everything else — including a
     * content rejection after `DATA`, whose meaning varies by relay — takes the §2.4 default
     * for the unrecognised, which is `Mailer`.
     */
    private static function scopeFor(int $code, string $stage): FailureScope
    {
        if ($code === 552) {
            return FailureScope::Message;
        }

        if ($code >= 500 && in_array($stage, ['sender', 'recipient'], true)) {
            return FailureScope::Message;
        }

        return FailureScope::Mailer;
    }

    private function command(SmtpTransport $transport, string $command): void
    {
        $transport->write($command . self::CRLF);
    }

    /**
     * Read one reply: its code, the lines joined for a human-readable message, and the lines
     * themselves — which `parseExtensions()` needs, since only the line structure says where
     * one advertised capability ends and the next begins.
     *
     * @return array{0: int, 1: string, 2: list<string>}
     */
    private function reply(SmtpTransport $transport): array
    {
        $lines = [];
        $code = 0;

        for ($read = 0; $read < self::MAX_REPLY_LINES; $read++) {
            $line = $transport->readLine();

            if (preg_match('/^(\d{3})([ -]?)(.*)$/', $line, $matches) !== 1) {
                throw MailException::mailer(sprintf(
                    '%s sent a reply this mailer could not parse: %s',
                    $this->host,
                    $line
                ));
            }

            $code = (int) $matches[1];
            $lines[] = trim($matches[3]);

            // A hyphen after the code means another line follows; a space (or nothing) ends it.
            if ($matches[2] !== '-') {
                return [$code, trim(implode(' ', $lines)), $lines];
            }
        }

        throw MailException::mailer(sprintf(
            '%s sent more than %d continuation lines in one reply.',
            $this->host,
            self::MAX_REPLY_LINES
        ));
    }

    /**
     * `Throwable`, not `MailException`, and deliberately so. This runs inside a `finally`, so
     * anything escaping here *replaces* the real failure with a write error — the caller
     * would be told the socket could not be written to, having never learned that the relay
     * refused the recipient. A transport is also free to fail in ways this adapter does not
     * define: `Services\Mediator` promotes PHP warnings to `ErrorException`, so an `fwrite`
     * to a peer that has already hung up arrives here as neither a `MailException` nor a
     * return value. Nothing this can throw is worth the error it would hide.
     */
    private function tryQuit(SmtpTransport $transport): void
    {
        try {
            $transport->write('QUIT' . self::CRLF);
        } catch (Throwable) {
            // The connection is already gone; close() is what actually matters.
        }
    }

    /**
     * One extension per reply line, keyword first and its parameters after — `AUTH PLAIN
     * LOGIN` is one capability with two mechanisms, and `SIZE 35882577` is one capability
     * with a limit, not two capabilities named `SIZE` and `35882577`.
     *
     * Parsed per line rather than from the flattened reply text, because only the line
     * structure says where one capability ends and the next begins. The first line is the
     * relay's own greeting and never a capability.
     *
     * @param list<string> $lines
     * @return array<string, list<string>>
     */
    private static function parseExtensions(array $lines): array
    {
        $extensions = [];

        foreach (array_slice($lines, 1) as $line) {
            $words = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($words === []) {
                continue;
            }

            $keyword = (string) array_shift($words);

            // Older relays advertise `AUTH=PLAIN LOGIN` rather than `AUTH PLAIN LOGIN`.
            if (str_contains($keyword, '=')) {
                [$keyword, $first] = explode('=', $keyword, 2);
                array_unshift($words, $first);
            }

            $extensions[strtoupper($keyword)] = array_values(array_filter(
                $words,
                static fn (string $word): bool => $word !== ''
            ));
        }

        return $extensions;
    }

    /**
     * Dot-stuffing, RFC 5321 §4.5.2. A body line beginning with `.` would otherwise be read
     * as the end-of-data marker, truncating the message there — silently, and only for the
     * messages unlucky enough to contain one.
     *
     * `MimeMessage` base64-encodes every body, so no line it produces can begin with a dot
     * and this can never fire in practice today. It is implemented regardless: "cannot
     * happen" is a property of the current encoder, not of SMTP.
     */
    private static function stuff(string $data): string
    {
        if (str_starts_with($data, '.')) {
            $data = '.' . $data;
        }

        return str_replace(self::CRLF . '.', self::CRLF . '..', $data);
    }

    /**
     * Most relays name their queue id in the reply to the final dot — "Ok: queued as 4B2C3D".
     * Opportunistic: SMTP does not require one, and null is a legitimate answer.
     */
    private static function queueIdIn(string $text): ?string
    {
        return preg_match('/queued as ([A-Za-z0-9._-]+)/i', $text, $matches) === 1 ? $matches[1] : null;
    }
}
