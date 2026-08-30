<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\CheckoutAdapters;

use ArrayObject;
use Closure;
use Monad\Clarity\Services\HttpClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for the real HttpClient in every Checkout adapter test — no live gateway calls
 * are ever made. $responder receives the exact PSR-7 request the adapter built and returns
 * a canned response, so a test can assert on both the outgoing wire format and the
 * adapter's parsing of what comes back.
 *
 * Kept separate from the LLM suite's fake of the same name rather than shared: coupling two
 * suites' test doubles together to save a few lines would make either one harder to change.
 * Gateways do not agree on a wire format either — Stripe is form-encoded, Paddle is JSON —
 * so both decoders live here, each named for what it decodes.
 *
 * The request log lives in an ArrayObject for the reason HttpClient's own docblock
 * explains: adapters call withTimeoutSeconds(), which returns a clone, and PHP's shallow
 * clone would fork a plain array — leaving this instance's log empty while the clone that
 * actually sent the request kept its own. Object properties are copied by reference, so
 * both keep appending to one log.
 *
 * @package Monad\Clarity\Tests\Services\CheckoutAdapters
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
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return array_values($this->requestLog->getArrayCopy());
    }

    public function requestCount(): int
    {
        return count($this->requestLog);
    }

    /**
     * The last request's form-encoded body, decoded back into the nested array the adapter
     * built — the shape Stripe's own documentation describes.
     *
     * @return array<string, mixed>
     */
    public function decodedLastRequestForm(): array
    {
        $request = $this->lastRequest();

        if ($request === null) {
            return [];
        }

        parse_str((string) $request->getBody(), $parsed);

        return $parsed;
    }

    /**
     * The last request's JSON body, decoded. Distinct from decodedLastRequestForm() because
     * parse_str does not fail on JSON — it returns one nonsense key, so a JSON body run
     * through the form decoder would produce assertions that are quietly wrong rather than
     * red.
     *
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
