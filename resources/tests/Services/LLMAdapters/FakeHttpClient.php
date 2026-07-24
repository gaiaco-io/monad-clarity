<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\LLMAdapters;

use ArrayObject;
use Closure;
use Monad\Clarity\Services\HttpClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for the real HttpClient in every LLM adapter test (TestingStrategy.md Tier 4
 * — "LLM adapter tests mock HttpClient, no live provider API calls"). $responder receives
 * the exact PSR-7 request an adapter built and returns a canned PSR-7 response, so a test
 * can assert on both the outgoing wire format and the adapter's parsing of the response.
 *
 * Every LLM adapter calls `withTimeoutSeconds()` before sending, which returns a *clone*
 * (HttpClient.php's own doc explains why: reconstructing via `new static(...)` would
 * silently drop this class's added state). A plain array property would therefore fork
 * into a separate, empty copy on the clone the adapter actually sends through, leaving
 * this original instance's request log empty. Storing the log in an ArrayObject sidesteps
 * that: PHP's default (shallow) clone copies object-typed properties by reference, so the
 * clone and the original keep appending to the exact same log.
 *
 * @package Monad\Clarity\Tests\Services\LLMAdapters
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

    /**
     * @return array<string, mixed>
     */
    public function decodedLastRequestBody(): array
    {
        $request = $this->lastRequest();

        return $request === null ? [] : (array) json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
