<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use CurlHandle;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * cURL-backed, PSR-18 compliant HTTP client. Used internally by the LLM adapters and
 * Authentication's Google SSO; also available directly to application code.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
class HttpClient implements ClientInterface
{
    public function __construct(
        private int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 10,
        private readonly bool $verifySsl = true,
        private readonly int $maxRedirects = 5,
    ) {
    }

    /**
     * A copy of this client with a different request timeout — for a caller (e.g. an LLM
     * adapter) that receives its timeout per-call rather than at construction, since
     * $timeoutSeconds above is otherwise the only fixed-at-construction property that
     * ever needs adjusting after the fact.
     *
     * Clones and mutates the one non-readonly property rather than reconstructing via
     * `new static(...)`: reconstruction would only ever pass HttpClient's own four
     * constructor parameters, silently dropping any state a subclass adds (e.g. a test
     * fake's injected response) — `clone` copies every property, subclass-added ones
     * included, so this works correctly no matter what a subclass carries.
     */
    public function withTimeoutSeconds(int $timeoutSeconds): static
    {
        $clone = clone $this;
        $clone->timeoutSeconds = $timeoutSeconds;

        return $clone;
    }

    /**
     * Send a PSR-7 request over cURL, returning a PSR-7 response.
     *
     * @throws HttpClientException on DNS failure, connection refusal, or timeout.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $handle = curl_init();

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => self::flattenRequestHeaders($request),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $this->maxRedirects > 0,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HEADERFUNCTION => self::captureResponseHeaders($responseHeaders),
        ]);

        $body = (string) $request->getBody();

        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);

            throw new HttpClientException($request, sprintf('cURL error %d: %s', $errno, $error), $errno);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        return new Response($statusCode, $responseHeaders, (string) $responseBody);
    }

    /**
     * Build and send a request in one call.
     */
    public function request(
        string $method,
        string $uri,
        array $headers = [],
        string|StreamInterface $body = ''
    ): ResponseInterface {
        return $this->sendRequest(new Request($method, $uri, $headers, $body));
    }

    public function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $uri, $headers);
    }

    public function post(string $uri, string|StreamInterface $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('POST', $uri, $headers, $body);
    }

    public function put(string $uri, string|StreamInterface $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('PUT', $uri, $headers, $body);
    }

    public function patch(string $uri, string|StreamInterface $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('PATCH', $uri, $headers, $body);
    }

    public function delete(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('DELETE', $uri, $headers);
    }

    /**
     * POST a PHP value as a JSON body, setting Content-Type automatically.
     */
    public function postJson(string $uri, mixed $data, array $headers = []): ResponseInterface
    {
        $headers['Content-Type'] ??= 'application/json';

        return $this->post($uri, json_encode($data, JSON_THROW_ON_ERROR), $headers);
    }

    /**
     * @return list<string> "Name: value" lines, one per header value.
     */
    private static function flattenRequestHeaders(RequestInterface $request): array
    {
        $lines = [];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }

        return $lines;
    }

    /**
     * Build a CURLOPT_HEADERFUNCTION callback that fills $headers with the final hop's
     * response headers only — the header buffer is reset on each new status line, so a
     * followed redirect doesn't leave earlier hops' headers mixed in.
     *
     * @param array<string, list<string>> $headers
     */
    private static function captureResponseHeaders(array &$headers): callable
    {
        return static function (CurlHandle $handle, string $headerLine) use (&$headers): int {
            $trimmed = trim($headerLine, "\r\n");

            if ($trimmed === '') {
                return strlen($headerLine);
            }

            if (str_starts_with($trimmed, 'HTTP/')) {
                $headers = [];

                return strlen($headerLine);
            }

            [$name, $value] = array_pad(explode(':', $trimmed, 2), 2, '');
            $headers[trim($name)][] = trim($value);

            return strlen($headerLine);
        };
    }
}
