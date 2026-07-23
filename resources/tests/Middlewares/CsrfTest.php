<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Middlewares;

use Gaia\Clarity\Console\Setup;
use Gaia\Clarity\Middlewares\Csrf;
use Gaia\Clarity\Services\DB;
use Gaia\Clarity\Services\Request;
use Gaia\Clarity\Services\Response;
use Gaia\Clarity\Services\Schema;
use Gaia\Clarity\Services\Session;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    private const SECRET = 'test-hmac-secret';

    #[Before]
    public function setUpInMemoryDatabase(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));
        Schema::createTable('sessions', Setup::sessionsBlueprint('sqlite'));
    }

    #[After]
    public function resetState(): void
    {
        DB::reset();
        Session::reset();
    }

    private function csrf(array $excludedPaths = []): Csrf
    {
        return new Csrf(self::SECRET, $excludedPaths);
    }

    private function next(): callable
    {
        return static fn (Request $request): Response => Response::text('ok');
    }

    private function requestWithSessionCookie(string $token, array $overrides = []): Request
    {
        return Request::fromArrays(
            server: [
                'REQUEST_METHOD' => $overrides['method'] ?? 'POST',
                'REQUEST_URI' => $overrides['path'] ?? '/account',
                'HTTP_HOST' => 'example.test',
                ...($overrides['server'] ?? []),
            ],
            cookies: ['mid' => $token],
            input: $overrides['input'] ?? [],
        );
    }

    public function testSafeMethodsPassThroughWithoutValidation(): void
    {
        $csrf = $this->csrf();
        $request = Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account']);

        $response = $csrf($request, $this->next());

        self::assertSame('ok', $response->content());
    }

    public function testExcludedPathBypassesValidation(): void
    {
        $csrf = $this->csrf(['/webhooks']);
        $request = Request::fromArrays(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/webhooks/stripe']);

        $response = $csrf($request, $this->next());

        self::assertSame('ok', $response->content());
    }

    public function testMutatingRequestWithNoTokenIsRejected(): void
    {
        $csrf = $this->csrf();
        $request = Request::fromArrays(server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account']);

        $response = $csrf($request, $this->next());

        self::assertSame(403, $response->status());
    }

    public function testSessionBackedTokenRoundTripsThroughFormField(): void
    {
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');

        $tokenRequest = $this->requestWithSessionCookie($session['token'], ['method' => 'GET']);
        $token = $csrf->tokenFor($tokenRequest);

        $submitRequest = $this->requestWithSessionCookie($session['token'], ['input' => [Csrf::FIELD_NAME => $token]]);

        $response = $csrf($submitRequest, $this->next());

        self::assertSame('ok', $response->content());
    }

    public function testSessionBackedTokenRoundTripsThroughHeader(): void
    {
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        $token = $csrf->tokenFor($this->requestWithSessionCookie($session['token'], ['method' => 'GET']));

        $submitRequest = $this->requestWithSessionCookie($session['token'], [
            'server' => ['HTTP_X_CSRF_TOKEN' => $token],
        ]);

        $response = $csrf($submitRequest, $this->next());

        self::assertSame('ok', $response->content());
    }

    public function testWrongSessionTokenIsRejected(): void
    {
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        $csrf->tokenFor($this->requestWithSessionCookie($session['token'], ['method' => 'GET']));

        $submitRequest = $this->requestWithSessionCookie($session['token'], [
            'input' => [Csrf::FIELD_NAME => 'not-the-real-token'],
        ]);

        self::assertSame(403, $csrf($submitRequest, $this->next())->status());
    }

    public function testTokenForIsIdempotentWithinASession(): void
    {
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        $request = $this->requestWithSessionCookie($session['token'], ['method' => 'GET']);

        self::assertSame($csrf->tokenFor($request), $csrf->tokenFor($request));
    }

    public function testRotateInvalidatesThePreviousSessionToken(): void
    {
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        $tokenRequest = $this->requestWithSessionCookie($session['token'], ['method' => 'GET']);

        $oldToken = $csrf->tokenFor($tokenRequest);
        $newToken = $csrf->rotate($tokenRequest);

        self::assertNotSame($oldToken, $newToken);

        $submitWithOldToken = $this->requestWithSessionCookie($session['token'], ['input' => [Csrf::FIELD_NAME => $oldToken]]);
        self::assertSame(403, $csrf($submitWithOldToken, $this->next())->status());

        $submitWithNewToken = $this->requestWithSessionCookie($session['token'], ['input' => [Csrf::FIELD_NAME => $newToken]]);
        self::assertSame('ok', $csrf($submitWithNewToken, $this->next())->content());
    }

    public function testStatelessTokenRoundTripsWithNoSession(): void
    {
        $csrf = $this->csrf();
        $tokenRequest = Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/contact']);
        $token = $csrf->tokenFor($tokenRequest);

        $submitRequest = Request::fromArrays(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact'],
            input: [Csrf::FIELD_NAME => $token],
        );

        self::assertSame('ok', $csrf($submitRequest, $this->next())->content());
    }

    public function testStatelessTokenSignedWithADifferentSecretIsRejected(): void
    {
        $csrf = $this->csrf();
        $otherCsrf = new Csrf('a-completely-different-secret');
        $token = $otherCsrf->tokenFor(Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/contact']));

        $submitRequest = Request::fromArrays(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact'],
            input: [Csrf::FIELD_NAME => $token],
        );

        self::assertSame(403, $csrf($submitRequest, $this->next())->status());
    }

    public function testExpiredStatelessTokenIsRejected(): void
    {
        $csrf = new Csrf(self::SECRET, statelessTokenTtlSeconds: -1);
        $token = $csrf->tokenFor(Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/contact']));

        $submitRequest = Request::fromArrays(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact'],
            input: [Csrf::FIELD_NAME => $token],
        );

        self::assertSame(403, $csrf($submitRequest, $this->next())->status());
    }

    public function testMalformedStatelessTokenIsRejected(): void
    {
        $csrf = $this->csrf();
        $submitRequest = Request::fromArrays(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact'],
            input: [Csrf::FIELD_NAME => 'garbage-token-with-no-dots'],
        );

        self::assertSame(403, $csrf($submitRequest, $this->next())->status());
    }

    public function testCrossOriginRequestIsRejectedEvenWithAValidToken(): void
    {
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        $token = $csrf->tokenFor($this->requestWithSessionCookie($session['token'], ['method' => 'GET']));

        $submitRequest = $this->requestWithSessionCookie($session['token'], [
            'input' => [Csrf::FIELD_NAME => $token],
            'server' => ['HTTP_ORIGIN' => 'https://evil.test'],
        ]);

        self::assertSame(403, $csrf($submitRequest, $this->next())->status());
    }

    public function testSameOriginRequestWithMatchingOriginHeaderPasses(): void
    {
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        $token = $csrf->tokenFor($this->requestWithSessionCookie($session['token'], ['method' => 'GET']));

        $submitRequest = $this->requestWithSessionCookie($session['token'], [
            'input' => [Csrf::FIELD_NAME => $token],
            'server' => ['HTTP_ORIGIN' => 'https://example.test'],
        ]);

        self::assertSame('ok', $csrf($submitRequest, $this->next())->content());
    }

    public function testSameOriginWithNonDefaultPortIsAccepted(): void
    {
        // The `mitosis serve` default (:8000) — parse_url(..., PHP_URL_HOST) alone would
        // drop the port and wrongly reject this as cross-origin.
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        $tokenRequest = $this->requestWithSessionCookie($session['token'], [
            'method' => 'GET',
            'server' => ['HTTP_HOST' => '127.0.0.1:8000'],
        ]);
        $token = $csrf->tokenFor($tokenRequest);

        $submitRequest = $this->requestWithSessionCookie($session['token'], [
            'input' => [Csrf::FIELD_NAME => $token],
            'server' => ['HTTP_HOST' => '127.0.0.1:8000', 'HTTP_ORIGIN' => 'http://127.0.0.1:8000'],
        ]);

        self::assertSame('ok', $csrf($submitRequest, $this->next())->content());
    }

    public function testMismatchedPortIsRejectedAsCrossOrigin(): void
    {
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        $tokenRequest = $this->requestWithSessionCookie($session['token'], [
            'method' => 'GET',
            'server' => ['HTTP_HOST' => '127.0.0.1:8000'],
        ]);
        $token = $csrf->tokenFor($tokenRequest);

        $submitRequest = $this->requestWithSessionCookie($session['token'], [
            'input' => [Csrf::FIELD_NAME => $token],
            'server' => ['HTTP_HOST' => '127.0.0.1:8000', 'HTTP_ORIGIN' => 'http://127.0.0.1:9999'],
        ]);

        self::assertSame(403, $csrf($submitRequest, $this->next())->status());
    }

    public function testStatelessRequestWithNoOriginOrRefererIsRejected(): void
    {
        // Unlike the session-backed path (which has a server-stored secret to fall back
        // on), a session-less request has no binding at all without Origin/Referer — a
        // harvested-but-validly-signed token must not sail through on header silence.
        $csrf = $this->csrf();
        $tokenRequest = Request::fromArrays(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/contact', 'HTTP_HOST' => 'example.test'],
        );
        $token = $csrf->tokenFor($tokenRequest);

        $submitRequest = Request::fromArrays(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact', 'HTTP_HOST' => 'example.test'],
            input: [Csrf::FIELD_NAME => $token],
        );

        self::assertSame(403, $csrf($submitRequest, $this->next())->status());
    }

    public function testRevokedSessionFallsBackToStatelessValidation(): void
    {
        $csrf = $this->csrf();
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        Session::revoke($session['id']);

        // No active session resolves anymore, so tokenFor() issues a stateless token —
        // the cookie is stale, not a valid session reference. A same-origin Origin
        // header is required here since the stateless path has no fallback secret.
        $token = $csrf->tokenFor($this->requestWithSessionCookie($session['token'], ['method' => 'GET']));

        $submitRequest = $this->requestWithSessionCookie($session['token'], [
            'input' => [Csrf::FIELD_NAME => $token],
            'server' => ['HTTP_ORIGIN' => 'https://example.test'],
        ]);

        self::assertSame('ok', $csrf($submitRequest, $this->next())->content());
    }
}
