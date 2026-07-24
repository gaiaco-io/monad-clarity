<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Middlewares;

use Monad\Clarity\Middlewares\CORS;
use Monad\Clarity\Services\Request;
use Monad\Clarity\Services\Response;
use PHPUnit\Framework\TestCase;

final class CORSTest extends TestCase
{
    private function next(?bool &$called = null): callable
    {
        return static function (Request $request) use (&$called): Response {
            $called = true;

            return Response::text('ok');
        };
    }

    private static function request(string $method = 'GET', ?string $origin = null, array $extraServer = []): Request
    {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/api/widgets', ...$extraServer];

        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }

        return Request::fromArrays(server: $server);
    }

    public function testNonCrossOriginRequestPassesThroughWithoutCorsHeaders(): void
    {
        $cors = new CORS();
        $response = $cors(self::request(), $this->next());

        self::assertSame('ok', $response->content());
        self::assertNull($response->header('Access-Control-Allow-Origin'));
    }

    public function testWildcardOriginIsReflectedAsStarWhenCredentialsAreOff(): void
    {
        $cors = new CORS(allowedOrigins: ['*']);
        $response = $cors(self::request(origin: 'https://app.example.com'), $this->next());

        self::assertSame('*', $response->header('Access-Control-Allow-Origin'));
    }

    public function testExactAllowedOriginIsReflectedVerbatim(): void
    {
        $cors = new CORS(allowedOrigins: ['https://app.example.com']);
        $response = $cors(self::request(origin: 'https://app.example.com'), $this->next());

        self::assertSame('https://app.example.com', $response->header('Access-Control-Allow-Origin'));
    }

    public function testDisallowedOriginOnANormalRequestStillReachesTheControllerButGetsNoCorsHeaders(): void
    {
        $called = false;
        $cors = new CORS(allowedOrigins: ['https://app.example.com']);
        $response = $cors(self::request(origin: 'https://evil.example'), $this->next($called));

        self::assertTrue($called);
        self::assertSame('ok', $response->content());
        self::assertNull($response->header('Access-Control-Allow-Origin'));
    }

    public function testPreflightWithAllowedOriginReturnsAllowHeadersWithoutReachingController(): void
    {
        $called = false;
        $cors = new CORS(
            allowedOrigins: ['https://app.example.com'],
            allowedMethods: ['GET', 'POST'],
            allowedHeaders: ['Content-Type', 'X-Custom'],
            preflightCacheSeconds: 3600,
        );

        $response = $cors(self::request('OPTIONS', 'https://app.example.com'), $this->next($called));

        self::assertFalse($called);
        self::assertSame(204, $response->status());
        self::assertSame('https://app.example.com', $response->header('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST', $response->header('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, X-Custom', $response->header('Access-Control-Allow-Headers'));
        self::assertSame('3600', $response->header('Access-Control-Max-Age'));
    }

    public function testPreflightWithDisallowedOriginIsRejectedWithoutReachingController(): void
    {
        $called = false;
        $cors = new CORS(allowedOrigins: ['https://app.example.com']);

        $response = $cors(self::request('OPTIONS', 'https://evil.example'), $this->next($called));

        self::assertFalse($called);
        self::assertSame(403, $response->status());
    }

    public function testCredentialsAddsAllowCredentialsHeader(): void
    {
        $cors = new CORS(allowedOrigins: ['https://app.example.com'], supportsCredentials: true);
        $response = $cors(self::request(origin: 'https://app.example.com'), $this->next());

        self::assertSame('true', $response->header('Access-Control-Allow-Credentials'));
        self::assertSame('https://app.example.com', $response->header('Access-Control-Allow-Origin'));
    }

    public function testWildcardOriginNeverCombinesWithCredentials(): void
    {
        // The CORS spec forbids `Access-Control-Allow-Origin: *` alongside
        // `Access-Control-Allow-Credentials: true` — with credentials on, a '*' entry
        // in $allowedOrigins can never satisfy the exact-match check, so every origin is
        // rejected unless explicitly listed. A deliberate, spec-correct trap, not a bug.
        $cors = new CORS(allowedOrigins: ['*'], supportsCredentials: true);
        $response = $cors(self::request(origin: 'https://app.example.com'), $this->next());

        self::assertNull($response->header('Access-Control-Allow-Origin'));
        self::assertNull($response->header('Access-Control-Allow-Credentials'));
    }

    public function testExposedHeadersAreOnlySetWhenConfigured(): void
    {
        $withExposed = new CORS(allowedOrigins: ['https://app.example.com'], exposedHeaders: ['X-Total-Count']);
        $withoutExposed = new CORS(allowedOrigins: ['https://app.example.com']);

        $responseWith = $withExposed(self::request(origin: 'https://app.example.com'), $this->next());
        $responseWithout = $withoutExposed(self::request(origin: 'https://app.example.com'), $this->next());

        self::assertSame('X-Total-Count', $responseWith->header('Access-Control-Expose-Headers'));
        self::assertNull($responseWithout->header('Access-Control-Expose-Headers'));
    }

    public function testVaryHeaderIsSetToOriginWhenAbsent(): void
    {
        $cors = new CORS(allowedOrigins: ['https://app.example.com']);
        $response = $cors(self::request(origin: 'https://app.example.com'), $this->next());

        self::assertSame('Origin', $response->header('Vary'));
    }

    public function testVaryHeaderAppendsOriginToAnExistingValueWithoutOverwritingIt(): void
    {
        $cors = new CORS(allowedOrigins: ['https://app.example.com']);
        $next = static fn (Request $r): Response => Response::text('ok')->withHeader('Vary', 'Accept-Encoding');

        $response = $cors(self::request(origin: 'https://app.example.com'), $next);

        self::assertSame('Accept-Encoding, Origin', $response->header('Vary'));
    }

    public function testVaryHeaderIsNotDuplicatedIfOriginIsAlreadyPresent(): void
    {
        $cors = new CORS(allowedOrigins: ['https://app.example.com']);
        $next = static fn (Request $r): Response => Response::text('ok')->withHeader('Vary', 'Origin');

        $response = $cors(self::request(origin: 'https://app.example.com'), $next);

        self::assertSame('Origin', $response->header('Vary'));
    }

    /**
     * Without this, a shared cache could store the no-ACAO response served to a
     * disallowed origin and replay it to a different, actually-allowed origin —
     * cache poisoning (§30.2.11).
     */
    public function testVaryHeaderIsSetOnADisallowedOriginNormalRequestToPreventCachePoisoning(): void
    {
        $cors = new CORS(allowedOrigins: ['https://app.example.com']);
        $response = $cors(self::request(origin: 'https://evil.example'), $this->next());

        self::assertSame('Origin', $response->header('Vary'));
    }

    public function testVaryHeaderIsSetOnARejectedPreflight(): void
    {
        $cors = new CORS(allowedOrigins: ['https://app.example.com']);
        $response = $cors(self::request('OPTIONS', 'https://evil.example'), $this->next());

        self::assertSame('Origin', $response->header('Vary'));
    }

    public function testVaryHeaderIsOmittedForAStaticAllowAllWildcardBecauseTheResponseNeverVaries(): void
    {
        $cors = new CORS(allowedOrigins: ['*']);
        $response = $cors(self::request(origin: 'https://app.example.com'), $this->next());

        self::assertNull($response->header('Vary'));
    }

    public function testIsAllowedOriginAndRejectionResponseAreOverridableViaSubclass(): void
    {
        $cors = new class (allowedOrigins: []) extends CORS {
            protected function isAllowedOrigin(string $origin): bool
            {
                return str_ends_with($origin, '.example.com');
            }

            protected function rejectionResponse(): Response
            {
                return Response::text('nope', 451);
            }
        };

        $allowed = $cors(self::request(origin: 'https://anything.example.com'), $this->next());
        $rejected = $cors(self::request('OPTIONS', 'https://evil.test'), $this->next());

        self::assertSame('https://anything.example.com', $allowed->header('Access-Control-Allow-Origin'));
        self::assertSame(451, $rejected->status());
    }
}
