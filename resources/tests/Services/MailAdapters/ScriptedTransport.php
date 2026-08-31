<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\MailAdapters;

use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\SmtpTransport;

/**
 * A scripted SMTP server. Replies are queued in the order the adapter will read them, and
 * every byte written is recorded, so a test asserts the exact command sequence without
 * opening a socket.
 *
 * @package Monad\Clarity\Tests\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class ScriptedTransport implements SmtpTransport
{
    /** @var list<string> */
    private array $replies;

    /** @var list<string> */
    public array $written = [];

    public bool $opened = false;
    public bool $closed = false;
    public bool $tlsStarted = false;
    public bool $implicitTls = false;
    public ?string $openedHost = null;
    public ?int $openedPort = null;

    /**
     * @param list<string> $replies Each entry is one line, without its CRLF.
     */
    public function __construct(array $replies)
    {
        $this->replies = $replies;
    }

    public function open(string $host, int $port, int $timeoutSeconds, bool $implicitTls): void
    {
        $this->opened = true;
        $this->openedHost = $host;
        $this->openedPort = $port;
        $this->implicitTls = $implicitTls;
    }

    public function readLine(): string
    {
        if ($this->replies === []) {
            throw MailException::mailer('the scripted server closed the connection.');
        }

        return array_shift($this->replies);
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
    }

    public function startTls(): void
    {
        $this->tlsStarted = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * Everything written, as one string — for asserting the message body went out intact.
     */
    public function conversation(): string
    {
        return implode('', $this->written);
    }

    /**
     * The commands the adapter issued, trimmed, excluding the message body written after
     * DATA. Used to assert the sequence.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        $commands = [];

        foreach ($this->written as $written) {
            $line = trim($written);

            // The DATA payload is many lines at once; commands are always single lines.
            if ($line === '' || str_contains(trim($written, "\r\n"), "\r\n")) {
                continue;
            }

            $commands[] = $line;
        }

        return $commands;
    }

    public function repliesRemaining(): int
    {
        return count($this->replies);
    }
}
