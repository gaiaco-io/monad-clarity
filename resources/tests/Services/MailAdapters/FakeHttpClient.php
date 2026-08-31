<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\MailAdapters;

use ArrayObject;
use Closure;
use Monad\Clarity\Services\HttpClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for the real HttpClient in every mail adapter test — no live provider API calls.
 * $responder receives the exact PSR-7 request an adapter built and returns a canned response,
 * so a test asserts on both the outgoing wire format and the adapter's parsing of the reply.
 *
 * A per-suite copy, matching `Tests\Services\CheckoutAdapters\FakeHttpClient` rather than
 * importing the LLM one across namespaces.
 *
 * Every adapter calls `withTimeoutSeconds()` before sending, which returns a *clone*
 * (HttpClient's own docblock explains why it cannot reconstruct). A plain array property
 * would fork into a separate, empty copy on that clone, leaving this instance's log empty —
 * an ArrayObject is copied by reference, so clone and original append to the same log.
 *
 * @package Monad\Clarity\Tests\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class FakeHttpClient extends HttpClient
{
    private readonly Closure $responder;
    private readonly ArrayObject $requestLog;

    public function __construct(Closure $responder)
    {
        parent::__construct();

        $this->responder = $responder;
        $this->requestLog = new ArrayObject();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requestLog[] = $request;

        return ($this->responder)($request);
    }

    public function lastRequest(): ?RequestInterface
    {
        $count = count($this->requestLog);

        return $count === 0 ? null : $this->requestLog[$count - 1];
    }

    public function requestCount(): int
    {
        return count($this->requestLog);
    }

    public function lastBody(): string
    {
        return (string) $this->lastRequest()?->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedLastRequestBody(): array
    {
        $request = $this->lastRequest();

        return $request === null
            ? []
            : (array) json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
