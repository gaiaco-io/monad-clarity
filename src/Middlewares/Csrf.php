<?php

declare(strict_types=1);

namespace Monad\Clarity\Middlewares;

use Monad\Clarity\Services\Request;
use Monad\Clarity\Services\Response;
use Monad\Clarity\Services\Session;
use Monad\Clarity\Utils\ConstantTime;
use Monad\Clarity\Utils\CryptographicToken;
use Monad\Clarity\Utils\HMAC;

/**
 * CSRF protection (ReleaseNotes_26.07.md §13). Storage strategy depends on whether the
 * request carries a valid session (Services\Session, resolved from the `mid` cookie):
 * - **Session-backed**: the token lives in the session's payload (§13.2.1–2), rotated
 *   via Session::write(). This alone defeats forgery — the stored value lives
 *   server-side, keyed by a session id the attacker cannot read (an HttpOnly cookie),
 *   so an attacker has no way to learn the correct value to submit.
 * - **Session-less** (no session, e.g. a public/anonymous form) — `{random}.{timestamp}.
 *   {hmac(random.timestamp, secret)}` (§13.2.9). Be clear about what this does and does
 *   NOT provide: the HMAC proves the token was minted by this app and hasn't exceeded
 *   `$statelessTokenTtlSeconds` — it is NOT bound to a browser, session, or cookie, so a
 *   token harvested from the attacker's own visit is a validly-signed token and would
 *   replay successfully if origin/referer were the only other check and happened to be
 *   absent. Real protection for this path is therefore the Origin/Referer check in
 *   originIsTrusted(): for a session-less request, both must be checked and one of them
 *   MUST be present — unlike the session-backed path, there is no fallback secret to
 *   lean on if both headers are missing. A true signed-double-submit-cookie scheme
 *   (binding the token to the specific browser via a cookie the attacker cannot read)
 *   would close this independent of Origin/Referer, but requires request-scoped
 *   coordination between this middleware and whatever calls tokenFor() from a view —
 *   deferred as a documented limitation rather than half-built.
 *
 * Not `final` — per CrossRepoContracts.md §5, this class is designed for extension: the
 * skeleton's `app/middlewares/Csrf` is expected to `extend` this with a zero-argument
 * constructor (reading secrets/config from the app's own config layer) so it can be
 * registered as a bare class-string in Route middleware (Route resolves a string
 * middleware via `new $class()`, with no constructor arguments). `requiresValidation()`,
 * `isExcluded()`, `originIsTrusted()`, and `reject()` are `protected` extension points.
 *
 * @package Monad\Clarity\Middlewares
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
class Csrf
{
    public const FIELD_NAME = '_csrf';
    public const HEADER_NAME = 'X-CSRF-Token';
    public const SESSION_PAYLOAD_KEY = 'csrf_token';

    private const VALIDATED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const DEFAULT_STATELESS_TOKEN_TTL_SECONDS = 3600;

    /**
     * @param list<string> $excludedPaths Path prefixes exempt from validation (webhooks,
     *     stateless API routes, §13.2.8). An exact match or `{prefix}/...` matches.
     */
    public function __construct(
        private readonly string $hmacSecret,
        private readonly array $excludedPaths = [],
        private readonly int $statelessTokenTtlSeconds = self::DEFAULT_STATELESS_TOKEN_TTL_SECONDS,
        private readonly ?string $context = null,
    ) {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        if (!$this->requiresValidation($request)) {
            return $next($request);
        }

        if (!$this->originIsTrusted($request) || !$this->validate($request)) {
            return $this->reject($request);
        }

        return $next($request);
    }

    /**
     * The token to embed in the current response (a hidden form field, a meta tag for
     * AJAX). Reuses the session's existing token if one is already stored, otherwise
     * issues a fresh one — this must be idempotent within a session's lifetime, or
     * every page load would invalidate the token embedded in every other open tab.
     */
    public function tokenFor(Request $request): string
    {
        $sessionId = $this->activeSessionId($request);

        if ($sessionId === null) {
            return $this->generateStatelessToken();
        }

        $existing = Session::read($sessionId, self::SESSION_PAYLOAD_KEY, context: $this->context);

        return is_string($existing) && $existing !== '' ? $existing : $this->rotate($request);
    }

    /**
     * Force-issue a fresh token, invalidating whatever was issued before. Call after a
     * successful state-changing request (e.g. login) to defeat token fixation.
     */
    public function rotate(Request $request): string
    {
        $sessionId = $this->activeSessionId($request);

        if ($sessionId === null) {
            return $this->generateStatelessToken();
        }

        $token = CryptographicToken::generate();
        Session::write($sessionId, self::SESSION_PAYLOAD_KEY, $token, context: $this->context);

        return $token;
    }

    /**
     * Safe methods (GET/HEAD/OPTIONS, implicit by omission from the validated set) and
     * excluded paths never require a CSRF token.
     */
    protected function requiresValidation(Request $request): bool
    {
        return in_array($request->method(), self::VALIDATED_METHODS, true) && !$this->isExcluded($request->path());
    }

    protected function isExcluded(string $path): bool
    {
        foreach ($this->excludedPaths as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A request with an Origin or Referer pointing at a different host:port is rejected
     * outright, even before the token is compared. For a session-backed request, absent
     * both headers (some same-site navigations omit them) falls through to the token
     * check, which is unforgeable on its own (server-stored secret). For a session-less
     * request there is no such fallback secret — the token alone cannot prove which
     * browser holds it (see the class docblock) — so this is the actual line of defense
     * and at least one of the two headers is required to be present.
     */
    protected function originIsTrusted(Request $request): bool
    {
        $host = $request->header('Host');

        if ($host === null) {
            return true;
        }

        $origin = $request->header('Origin') ?: $request->header('Referer');

        if ($origin === null || $origin === '') {
            return $this->activeSessionId($request) !== null;
        }

        return strcasecmp($this->authority($origin), $host) === 0;
    }

    /**
     * host[:port] from a URL, for comparison against the raw `Host` header — which,
     * unlike `parse_url(..., PHP_URL_HOST)`, keeps a non-default port (e.g. the `mitosis
     * serve` default of :8000). Comparing host alone would reject same-origin requests
     * on any non-default port.
     */
    private function authority(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return $port !== null ? $host . ':' . $port : $host;
    }

    protected function reject(Request $request): Response
    {
        return Response::json(['error' => 'Invalid or expired CSRF token.'], 403);
    }

    private function validate(Request $request): bool
    {
        $submitted = $request->header(self::HEADER_NAME) ?? $request->input(self::FIELD_NAME);

        if (!is_string($submitted) || $submitted === '') {
            return false;
        }

        $sessionId = $this->activeSessionId($request);

        if ($sessionId === null) {
            return $this->verifyStatelessToken($submitted);
        }

        $stored = Session::read($sessionId, self::SESSION_PAYLOAD_KEY, context: $this->context);

        return is_string($stored) && $stored !== '' && ConstantTime::equals($stored, $submitted);
    }

    private function activeSessionId(Request $request): ?string
    {
        $token = $request->cookie(Session::COOKIE_NAME);

        if ($token === null) {
            return null;
        }

        $session = Session::resolve($token, $this->context);

        return $session['id'] ?? null;
    }

    private function generateStatelessToken(): string
    {
        $nonce = bin2hex(random_bytes(16)) . '.' . time();

        return $nonce . '.' . HMAC::sign($nonce, $this->hmacSecret);
    }

    private function verifyStatelessToken(string $token): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        [$random, $timestamp, $signature] = $parts;

        if (!ctype_digit($timestamp) || (int) $timestamp < time() - $this->statelessTokenTtlSeconds) {
            return false;
        }

        return HMAC::verify($random . '.' . $timestamp, $signature, $this->hmacSecret);
    }
}
