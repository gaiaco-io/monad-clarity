<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\MailAdapters;

use JsonException;
use Monad\Clarity\Services\HttpClientException;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Nyholm\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Everything the HTTP mailers do identically: build a request, send it without letting a
 * transport failure escape untyped, and turn a non-2xx into a `MailException` carrying the
 * right `FailureScope`.
 *
 * A trait rather than a base class because `Services\Mail` deliberately declares no
 * constructor (ReleaseNotes_1.6.0.md §2.2) and `Smtp` must not inherit any of this — it
 * speaks to a socket and has no `HttpClient` at all. `CheckoutAdapters\SpeaksPaddle` is the
 * precedent for sharing between siblings this way; this is the same move one level up, since
 * what is shared here is a *transport* rather than one provider's dialect.
 *
 * **Usable only inside a `Services\Mail` subclass** that declares a `protected readonly
 * HttpClient $httpClient` and an `int $timeoutSeconds`, and implements `mailerName()`.
 *
 * @package Monad\Clarity\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
trait SpeaksHttpApi
{
    /**
     * Statuses that mean *this message* is wrong rather than *this mailer* — the only two a
     * provider reliably uses for validation. Everything else, including `401`, is the
     * mailer's fault: bad credentials here are precisely when a mailer holding a different
     * credential should get its turn (§2.4).
     */
    private const MESSAGE_FAULT_STATUSES = [400, 422];

    /**
     * Send a request, translating a transport failure into a MailException a pool can act on.
     *
     * Only the send is wrapped. Widening the `try` to cover payload building or response
     * parsing would report a bug in this adapter's own JSON encoding as a provider timeout,
     * and a pool would dutifully fail that bug over to six more mailers.
     *
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $uri, array $headers, string $body): ResponseInterface
    {
        $request = new Request($method, $uri, $headers, $body);

        try {
            return $this->httpClient->withTimeoutSeconds($this->timeoutSeconds)->sendRequest($request);
        } catch (HttpClientException $e) {
            // DNS failure, refused connection, TLS failure, timeout. Never the message's
            // fault, and exactly what a second mailer exists to survive.
            throw MailException::mailer(
                sprintf('%s could not be reached: %s', $this->mailerName(), $e->getMessage()),
                $e
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function postJson(string $uri, array $payload, array $headers): ResponseInterface
    {
        return $this->dispatch('POST', $uri, ['Content-Type' => 'application/json'] + $headers, self::encodeJson($payload));
    }

    /**
     * @throws MailException if $response is not 2xx.
     */
    private function assertSuccessful(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = (string) $response->getBody();

        throw new MailException(
            sprintf(
                '%s rejected the message with HTTP %d: %s',
                $this->mailerName(),
                $status,
                self::summarise($body)
            ),
            $this->scopeForFailure($status, $body)
        );
    }

    /**
     * The §2.4 policy, in one place. An adapter refines it through `scopeFromErrorBody()`
     * rather than by overriding this, so the default cannot drift apart across seven files.
     */
    private function scopeForFailure(int $status, string $body): FailureScope
    {
        return $this->scopeFromErrorBody($status, $body)
            ?? (in_array($status, self::MESSAGE_FAULT_STATUSES, true) ? FailureScope::Message : FailureScope::Mailer);
    }

    /**
     * This provider's own reading of its error body, or null for "no opinion — use the
     * status-code default".
     *
     * Overridden by an adapter whose provider distinguishes cases the status code cannot:
     * a `422` that means "this recipient is unroutable" (the message's fault) against one
     * that means "your account may not send to this domain" (the mailer's).
     */
    private function scopeFromErrorBody(int $status, string $body): ?FailureScope
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     * @throws MailException if the body is not a JSON object.
     */
    private function decodeJsonBody(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw MailException::mailer(
                sprintf('%s returned a response that was not valid JSON: %s', $this->mailerName(), $e->getMessage()),
                $e
            );
        }

        if (!is_array($decoded)) {
            throw MailException::mailer(
                sprintf('%s returned a JSON response whose top level was not an object.', $this->mailerName())
            );
        }

        return $decoded;
    }

    /**
     * Refuse a message carrying more tags than this provider can express.
     *
     * Postmark takes one `Tag`, Mailtrap one `category`. Sending the first and dropping the
     * rest is the tempting move and the wrong one: a tag that vanished is a reporting result
     * nobody can explain six months later, and it fails differently depending on which mailer
     * a pool happened to reach. Shared here so the two adapters that have this limit give the
     * same answer to the same input.
     *
     * @throws MailException scoped Message — every mailer with this limit would say the same.
     */
    private function assertAtMostOneTag(Message $message): void
    {
        if (count($message->tags) <= 1) {
            return;
        }

        throw MailException::message(sprintf(
            '%s carries a single tag and this message has %d (%s). Send one, or move the rest '
            . 'into headers — silently keeping the first would make a tag disappear.',
            $this->mailerName(),
            count($message->tags),
            implode(', ', $message->tags)
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Keep an error message readable when a provider answers with an HTML error page or a
     * kilobyte of JSON. The full body is not lost — it is what the adapter was handed — but
     * an exception message is read by a human in a log, not parsed.
     */
    private static function summarise(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        if ($body === '') {
            return '(empty response body)';
        }

        return strlen($body) <= 300 ? $body : substr($body, 0, 297) . '...';
    }
}
