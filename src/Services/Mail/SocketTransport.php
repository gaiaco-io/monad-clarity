<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

/**
 * The real socket behind `SmtpTransport` — `stream_socket_client`, and nothing more.
 *
 * Deliberately thin. Everything with a decision in it lives in `MailAdapters\Smtp`, so that
 * the protocol is tested against a scripted fake and this class holds only the parts no test
 * can meaningfully assert without a live server.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class SocketTransport implements SmtpTransport
{
    /** @var resource|null */
    private $stream = null;

    private string $description = 'the SMTP server';

    public function open(string $host, int $port, int $timeoutSeconds, bool $implicitTls): void
    {
        $this->description = sprintf('%s:%d', $host, $port);

        $errorCode = 0;
        $errorMessage = '';

        $stream = @stream_socket_client(
            sprintf('%s://%s:%d', $implicitTls ? 'ssl' : 'tcp', $host, $port),
            $errorCode,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]])
        );

        if ($stream === false) {
            throw MailException::mailer(sprintf(
                'Could not connect to %s: %s',
                $this->description,
                $errorMessage === '' ? sprintf('error %d', $errorCode) : $errorMessage
            ));
        }

        $this->stream = $stream;

        stream_set_timeout($stream, $timeoutSeconds);
    }

    public function readLine(): string
    {
        $stream = $this->stream();
        $line = fgets($stream);

        // fgets returns false at EOF and an empty-ish value on a timeout; both mean the
        // conversation is over. The interface forbids returning it, because a caller reading
        // a multi-line reply would never see its terminator and would loop forever.
        if ($line === false) {
            $timedOut = stream_get_meta_data($stream)['timed_out'] ?? false;

            throw MailException::mailer(sprintf(
                $timedOut
                    ? '%s stopped responding while a reply was expected.'
                    : '%s closed the connection while a reply was expected.',
                $this->description
            ));
        }

        return rtrim($line, "\r\n");
    }

    public function write(string $data): void
    {
        $stream = $this->stream();
        $written = @fwrite($stream, $data);

        if ($written === false || $written < strlen($data)) {
            throw MailException::mailer(
                sprintf('Writing to %s failed part way through.', $this->description)
            );
        }
    }

    public function startTls(): void
    {
        $enabled = @stream_socket_enable_crypto(
            $this->stream(),
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($enabled !== true) {
            throw MailException::mailer(
                sprintf('The TLS handshake with %s failed.', $this->description)
            );
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * @return resource
     */
    private function stream()
    {
        if ($this->stream === null) {
            throw MailException::mailer(
                sprintf('The connection to %s is not open.', $this->description)
            );
        }

        return $this->stream;
    }
}
