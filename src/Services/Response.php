<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services;

use Closure;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Outgoing HTTP response. Every static constructor returns a value object — nothing is
 * echoed or exits here. Route (or application code) calls send() exactly once, at the
 * end of the request, after middleware and the controller have all had their say.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
        private readonly ?Closure $streamCallback = null,
    ) {
    }

    /**
     * Encode $data as-is: no HTML-escaping. JSON encoding is already the correct
     * escaping for the JSON context — HTML-escaping first (a legacy bug here) would
     * corrupt the data and is meaningless outside an HTML context anyway.
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($status, ['Content-Type' => 'application/json'], $body);
    }

    public static function htm(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $text);
    }

    public static function download(string $path, ?string $name = null): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Cannot download: file "%s" does not exist.', $path));
        }

        $filename = $name ?? basename($path);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';

        return new self(200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . addcslashes($filename, '"\\') . '"',
        ], (string) file_get_contents($path));
    }

    public static function redirect(string $to, int $status = 302): self
    {
        if ($status < 300 || $status > 308) {
            throw new InvalidArgumentException(sprintf('%d is not a valid redirect status code.', $status));
        }

        return new self($status, ['Location' => $to], '');
    }

    public static function noContent(): self
    {
        return new self(204, [], '');
    }

    /**
     * $callback is invoked at send() time and is expected to echo/flush its own output.
     */
    public static function stream(callable $callback): self
    {
        return new self(200, [], '', $callback(...));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->status, $headers, $this->body, $this->streamCallback);
    }

    /**
     * The response body. For a stream() response this is always '' — content is produced
     * by the stream callback at send() time, not held in memory beforehand.
     */
    public function content(): string
    {
        return $this->body;
    }

    /**
     * Emit status, headers, and body. Does not exit — the script simply continues (and,
     * under normal request handling, ends) after this returns.
     */
    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->streamCallback !== null) {
            ($this->streamCallback)();

            return;
        }

        echo $this->body;
    }

    public function toPsr7(): ResponseInterface
    {
        $factory = new Psr17Factory();
        $psrResponse = $factory->createResponse($this->status);

        foreach ($this->headers as $name => $value) {
            $psrResponse = $psrResponse->withHeader($name, $value);
        }

        return $psrResponse->withBody($factory->createStream($this->body));
    }
}
