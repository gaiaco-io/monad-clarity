<?php

declare(strict_types=1);

namespace Gaia\Clarity\Middlewares;

use Gaia\Clarity\Services\Request;
use Gaia\Clarity\Services\Response;

/**
 * Cross-Origin Resource Sharing (ReleaseNotes_26.07.md §30). CORS is a browser-enforced
 * mechanism, not a server-side authorization boundary: a non-preflight request from a
 * disallowed origin is still passed to $next() (some clients — mobile apps, curl,
 * server-to-server calls — send no meaningful Origin at all, and CORS was never meant
 * to gate them) but receives no `Access-Control-*` headers, so a browser blocks
 * client-side JS from reading the response. §30.2.10 "rejection of unauthorised
 * origins" is enforced where it actually matters: a preflight (`OPTIONS`) request whose
 * whole purpose is to ask permission gets an explicit 403 if the origin isn't allowed,
 * rather than ever reaching the controller.
 *
 * §30.2.8 (route-level override) and §30.2.9 (environment-specific configuration) need
 * no special API: since every option is plain constructor config with no global state,
 * a route/group that needs different rules just registers a differently-configured CORS
 * instance as its own middleware, and the app's own environment-aware config loading
 * (dev vs. prod origins) is exactly what constructs each instance in the first place.
 *
 * Not `final` — per CrossRepoContracts.md §5, extended by `app/middlewares/` with a
 * zero-argument constructor supplying the app's actual origins/methods/headers.
 * `isAllowedOrigin()` and `rejectionResponse()` are `protected` extension points.
 *
 * @package Gaia\Clarity\Middlewares
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
class CORS
{
    /**
     * @param list<string> $allowedOrigins `['*']` allows any origin; otherwise exact
     *     `scheme://host[:port]` strings. `*` is ignored (treated as no match) when
     *     $supportsCredentials is true — the CORS spec forbids combining a wildcard
     *     origin with credentialed requests, so this never emits that invalid pair.
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param list<string> $exposedHeaders
     */
    public function __construct(
        private readonly array $allowedOrigins = ['*'],
        private readonly array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private readonly array $allowedHeaders = ['Content-Type', 'Authorization'],
        private readonly array $exposedHeaders = [],
        private readonly bool $supportsCredentials = false,
        private readonly int $preflightCacheSeconds = 86400,
    ) {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $origin = $request->header('Origin');

        if ($origin === null) {
            return $next($request);
        }

        if ($request->method() === 'OPTIONS') {
            $response = $this->isAllowedOrigin($origin) ? $this->preflightResponse($origin) : $this->rejectionResponse();

            return $this->varyByOriginIfNeeded($response);
        }

        $response = $next($request);

        return $this->isAllowedOrigin($origin) ? $this->applyCorsHeaders($response, $origin) : $this->varyByOriginIfNeeded($response);
    }

    protected function isAllowedOrigin(string $origin): bool
    {
        if ($this->supportsCredentials) {
            return in_array($origin, $this->allowedOrigins, true);
        }

        return in_array('*', $this->allowedOrigins, true) || in_array($origin, $this->allowedOrigins, true);
    }

    protected function rejectionResponse(): Response
    {
        return Response::json(['error' => 'Origin not allowed.'], 403);
    }

    private function preflightResponse(string $origin): Response
    {
        return $this->applyCorsHeaders(Response::noContent(), $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withHeader('Access-Control-Max-Age', (string) $this->preflightCacheSeconds);
    }

    private function applyCorsHeaders(Response $response, string $origin): Response
    {
        $allowOrigin = in_array('*', $this->allowedOrigins, true) && !$this->supportsCredentials ? '*' : $origin;

        $response = $this->varyByOriginIfNeeded($response)
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin);

        if ($this->supportsCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        return $response;
    }

    /**
     * A rejected preflight or an unmatched-origin response still needs `Vary: Origin` —
     * without it, a shared cache that stored the response for one origin (no ACAO
     * header) could serve that same cached response to a different, allowed origin
     * (§30.2.11). Skipped only when the config is a static allow-all: `*` with
     * credentials off always emits the same `Access-Control-Allow-Origin: *` regardless
     * of the request's Origin, so there is nothing for a cache to vary on.
     */
    private function varyByOriginIfNeeded(Response $response): Response
    {
        if (in_array('*', $this->allowedOrigins, true) && !$this->supportsCredentials) {
            return $response;
        }

        return $response->withHeader('Vary', $this->mergedVaryHeader($response));
    }

    /**
     * §30.2.11 — add `Origin` to any existing `Vary` header rather than overwriting it,
     * since a response varying on `Accept-Encoding` or similar must keep varying on
     * that too once CORS adds its own reason to vary.
     */
    private function mergedVaryHeader(Response $response): string
    {
        $existing = $response->header('Vary');

        if ($existing === null || $existing === '') {
            return 'Origin';
        }

        $values = array_map('trim', explode(',', $existing));

        return in_array('Origin', $values, true) ? $existing : $existing . ', Origin';
    }
}
