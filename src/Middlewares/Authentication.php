<?php

declare(strict_types=1);

namespace Monad\Clarity\Middlewares;

use Monad\Clarity\Middlewares\Authentication\AuthenticationException;
use Monad\Clarity\Middlewares\Authentication\AuthResult;
use Monad\Clarity\Services\Event;
use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\Session;
use Monad\Clarity\Utils\Hash;
use Monad\Clarity\Utils\HMAC;

/**
 * Authentication (ReleaseNotes_26.07.md §15 — 16 requirements). Clarity owns the
 * *mechanism*; the app owns the user *store*. Every requirement here composes
 * primitives that already exist rather than introducing new ones:
 * - Password hashing/verification/rehash-detection: `Utils\Hash` directly (no separate
 *   "password service" class — Hash already covers this in full).
 * - Session regeneration and remember-me: `Services\Session` (regenerate()/assignUser()
 *   for login fixation defense; a remember-me token is a long-lived Session row, not a
 *   new table).
 * - Login throttling: `Middlewares\RateLimiter`, required (not optional) per §28.2.
 * - Authentication events: `Services\Event`.
 * - Email verification / password reset: stateless HMAC tokens (`Utils\HMAC`), the same
 *   pattern as `Middlewares\Csrf`'s session-less path — no new schema.
 * - Google SSO: the authorization-code flow over `Services\HttpClient`, verified via
 *   Google's own `tokeninfo` endpoint (Google performs the signature verification;
 *   this class still checks audience/issuer/expiry itself) rather than a hand-rolled
 *   JWT/JWKS implementation or a new dependency.
 *
 * **The pluggable user resolver (§15.2.15) is the one genuinely new, frozen surface.**
 * Duck-typed callables, not a formal interface — consistent with how Migration
 * (duck-typed up()/down()), Files (duck-typed S3Client), and Cache (duck-typed Redis)
 * already handle app/external shapes in this codebase: `$findByCredential` and
 * `$findById` each take an identifier and return
 * `array{id: string, passwordHash: string, locked: bool, emailVerifiedAt: ?string}|null`.
 * Both are read-only — Authentication never writes to the app's user table. Every write
 * a real login flow needs (persisting a rehashed password, marking email verified,
 * recording a login timestamp) is handed back to the caller via AuthResult's fields or
 * this class's return values, never performed here.
 *
 * Not `final` — per CrossRepoContracts.md §5, extended by `app/middlewares/` with a
 * zero-argument constructor supplying the app's actual resolver/secrets/config.
 *
 * @package Monad\Clarity\Middlewares
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
class Authentication
{
    public const FAILURE_INVALID_CREDENTIALS = 'invalid_credentials';
    public const FAILURE_ACCOUNT_LOCKED = 'account_locked';
    public const FAILURE_RATE_LIMITED = 'rate_limited';

    private const PURPOSE_EMAIL_VERIFICATION = 'email_verify';
    private const PURPOSE_PASSWORD_RESET = 'password_reset';

    private const DEFAULT_EMAIL_VERIFICATION_TTL_SECONDS = 60 * 60 * 24;
    private const DEFAULT_PASSWORD_RESET_TTL_SECONDS = 60 * 60;
    private const REMEMBER_LIFETIME_SECONDS = 60 * 60 * 24 * 30;

    /**
     * Verified once, lazily, against a fixed dummy value — spent on every attempt()
     * call regardless of whether $findByCredential found a user, so an unknown
     * identifier costs exactly the same Hash::verify() KDF work as a known one with the
     * wrong password. Without this, `$user === null` short-circuits before Hash::verify
     * ever runs, and the resulting timing gap is a textbook user-enumeration oracle —
     * measurable well within the login rate limit.
     */
    private static ?string $dummyPasswordHash = null;

    /**
     * @param callable(string $identifier): (array{id: string, passwordHash: string, locked: bool, emailVerifiedAt: ?string}|null) $findByCredential
     * @param callable(string $id): (array{id: string, passwordHash: string, locked: bool, emailVerifiedAt: ?string}|null) $findById
     */
    public function __construct(
        private readonly mixed $findByCredential,
        private readonly mixed $findById,
        private readonly string $hmacSecret,
        private readonly RateLimiter $loginRateLimiter,
        private readonly ?HttpClient $httpClient = null,
        private readonly ?string $googleClientId = null,
        private readonly ?string $googleClientSecret = null,
        private readonly string $googleTokenEndpoint = 'https://oauth2.googleapis.com/token',
        private readonly string $googleTokenInfoEndpoint = 'https://oauth2.googleapis.com/tokeninfo',
        private readonly ?string $context = null,
        private readonly int $emailVerificationTtlSeconds = self::DEFAULT_EMAIL_VERIFICATION_TTL_SECONDS,
        private readonly int $passwordResetTtlSeconds = self::DEFAULT_PASSWORD_RESET_TTL_SECONDS,
    ) {
    }

    /**
     * Credential (email/username + password) login. Throttled per $identifier before
     * the password is even checked — a locked account and a wrong password both count
     * against the limit identically, so an attacker cannot use the limit itself to
     * enumerate which accounts exist or are locked. Hash::verify() always runs exactly
     * once, against either the real stored hash or a fixed dummy one, so response time
     * doesn't leak whether $identifier matched a user either.
     */
    public function attempt(
        string $identifier,
        string $password,
        string $ipAddress,
        string $userAgent,
        ?string $existingSessionId = null,
    ): AuthResult {
        if (!$this->loginRateLimiter->attempt($identifier)) {
            return $this->fail(self::FAILURE_RATE_LIMITED, $identifier);
        }

        $user = ($this->findByCredential)($identifier);
        $passwordValid = Hash::verify($password, $user['passwordHash'] ?? self::dummyPasswordHash());

        if ($user === null || !$passwordValid) {
            return $this->fail(self::FAILURE_INVALID_CREDENTIALS, $identifier, $user['id'] ?? null);
        }

        if ($user['locked']) {
            return $this->fail(self::FAILURE_ACCOUNT_LOCKED, $identifier, $user['id']);
        }

        $this->loginRateLimiter->clear($identifier);

        $needsRehash = Hash::needsRehash($user['passwordHash']);

        return $this->login($user['id'], $ipAddress, $userAgent, $existingSessionId, $needsRehash);
    }

    private static function dummyPasswordHash(): string
    {
        return self::$dummyPasswordHash ??= Hash::make('clarity-dummy-password-for-timing-safety');
    }

    /**
     * Establish a session for an already-authenticated user id — shared by attempt()
     * and by the app's own post-verification flow for other authentication methods
     * (Google SSO, or anything else built on top). If $existingSessionId names a
     * pre-login guest session, it is promoted, regenerated, and renewed to a fresh full
     * lifetime in place (fixation defense while preserving whatever payload — e.g. a
     * cart — it already held, and not leaving the newly-authenticated session seconds
     * from expiring if the guest session was nearly stale); otherwise a brand new
     * session is started.
     *
     * Re-checks account-lock state via findById() even though attempt() already checked
     * it — every session-granting path (credential login, remember-me resumption, and
     * this shared primitive that Google SSO and anything else routes through) enforces
     * the same invariant independently, rather than trusting a caller upstream to have
     * checked it. One redundant lookup on the already-cheap attempt() path buys a
     * uniform guarantee on every path, including ones this class doesn't know about yet.
     */
    public function login(
        string $userId,
        string $ipAddress,
        string $userAgent,
        ?string $existingSessionId = null,
        bool $needsRehash = false,
    ): AuthResult {
        $user = ($this->findById)($userId);

        if ($user === null || $user['locked']) {
            return $this->fail(self::FAILURE_ACCOUNT_LOCKED, $userId, $userId);
        }

        if ($existingSessionId !== null) {
            Session::assignUser($existingSessionId, $userId, $this->context);
            Session::renew($existingSessionId, context: $this->context);
            $token = Session::regenerate($existingSessionId, $this->context);
            $sessionId = $existingSessionId;
        } else {
            $session = Session::start($userId, $ipAddress, $userAgent, context: $this->context);
            $sessionId = $session['id'];
            $token = $session['token'];
        }

        Event::dispatch(Event::LOGIN_SUCCESS, ['userId' => $userId, 'sessionId' => $sessionId, 'ipAddress' => $ipAddress]);

        return AuthResult::success($userId, $sessionId, $token, $needsRehash);
    }

    /**
     * Exchange a Google OAuth2 authorization code for the caller's verified profile.
     * Deliberately does NOT touch the user resolver or sessions — whether this Google
     * account maps to an existing user, or should create one, is an app policy decision
     * (mirrors Event::USER_REGISTERED already being app-fired, not Clarity-fired). Call
     * login() with the resolved user id once the app has looked up or created its own
     * user row for this profile.
     *
     * @return array{googleId: string, email: string, emailVerified: bool, name: ?string}
     * @throws AuthenticationException on misconfiguration, a network failure, or a
     *     rejected/expired/wrong-audience id_token.
     */
    public function verifyGoogleAuthorizationCode(string $code, string $redirectUri): array
    {
        if ($this->httpClient === null || $this->googleClientId === null || $this->googleClientSecret === null) {
            throw new AuthenticationException('Google SSO is not configured (httpClient/googleClientId/googleClientSecret).');
        }

        $tokenResponse = $this->httpClient->post(
            $this->googleTokenEndpoint,
            http_build_query([
                'code' => $code,
                'client_id' => $this->googleClientId,
                'client_secret' => $this->googleClientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
        );

        if ($tokenResponse->getStatusCode() !== 200) {
            throw new AuthenticationException('Google token exchange failed.');
        }

        $idToken = json_decode((string) $tokenResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)['id_token'] ?? null;

        if (!is_string($idToken) || $idToken === '') {
            throw new AuthenticationException('Google did not return an id_token.');
        }

        return $this->verifyGoogleIdToken($idToken);
    }

    /**
     * @return array{googleId: string, email: string, emailVerified: bool, name: ?string}
     */
    private function verifyGoogleIdToken(string $idToken): array
    {
        $verifyResponse = $this->httpClient->get(
            $this->googleTokenInfoEndpoint . '?id_token=' . urlencode($idToken)
        );

        if ($verifyResponse->getStatusCode() !== 200) {
            throw new AuthenticationException('Google rejected the id_token.');
        }

        $claims = json_decode((string) $verifyResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (($claims['aud'] ?? null) !== $this->googleClientId) {
            throw new AuthenticationException('Google id_token audience mismatch.');
        }

        if (!in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new AuthenticationException('Google id_token issuer mismatch.');
        }

        if ((int) ($claims['exp'] ?? 0) < time()) {
            throw new AuthenticationException('Google id_token has expired.');
        }

        return [
            'googleId' => (string) $claims['sub'],
            'email' => (string) $claims['email'],
            'emailVerified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
            'name' => $claims['name'] ?? null,
        ];
    }

    /**
     * Issue a long-lived (30-day) remember-me session for $userId — a Session row like
     * any other, just with a longer TTL and a distinct cookie name the caller chooses
     * (deliberately not prescribed here; Session::COOKIE_NAME is reserved for the
     * regular login session). Returns the plaintext token for the caller to deliver.
     */
    public function issueRememberToken(string $userId, string $ipAddress, string $userAgent): string
    {
        $session = Session::start(
            $userId,
            $ipAddress,
            $userAgent,
            context: $this->context,
            lifetimeSecondsOverride: self::REMEMBER_LIFETIME_SECONDS,
        );

        return $session['token'];
    }

    /**
     * Resolve a remember-me token, rotate it (§15.2.10 — defeats replay of an
     * intercepted value), and establish a fresh regular session for this visit. Returns
     * null if the token is invalid, expired, revoked, was never tied to a user (a
     * remember token is always user-bound; a guest session presented here is rejected),
     * or the user has since been locked/disabled — a lock revokes standing remember-me
     * access exactly as it would a fresh login attempt, re-checked here via
     * findById() rather than trusting whatever state the user was in when the token was
     * first issued 30 days of lifetime ago.
     */
    public function resumeFromRememberToken(string $rememberToken, string $ipAddress, string $userAgent): ?AuthResult
    {
        $rememberSession = Session::resolve($rememberToken, $this->context);

        if ($rememberSession === null || $rememberSession['user_id'] === null) {
            return null;
        }

        $user = ($this->findById)($rememberSession['user_id']);

        if ($user === null || $user['locked']) {
            return null;
        }

        $newRememberToken = Session::regenerate($rememberSession['id'], $this->context);
        $result = $this->login($rememberSession['user_id'], $ipAddress, $userAgent);

        return $result->withRememberToken($newRememberToken);
    }

    /**
     * A stateless, self-verifying "confirm this email address" token — the app emails
     * it; verifyEmailVerificationToken() checks it when the user follows the link. No
     * storage: the app is responsible for persisting the resulting verified state.
     */
    public function issueEmailVerificationToken(string $userId): string
    {
        return $this->issuePurposeToken(self::PURPOSE_EMAIL_VERIFICATION, $userId);
    }

    /**
     * @return string|null The user id the token was issued for, or null if invalid/expired.
     */
    public function verifyEmailVerificationToken(string $token): ?string
    {
        return $this->verifyPurposeToken(self::PURPOSE_EMAIL_VERIFICATION, $token, $this->emailVerificationTtlSeconds);
    }

    /**
     * A stateless, self-verifying password-reset token — same shape as the email
     * verification token but tagged with a different purpose, so one cannot be replayed
     * as the other even though both are structurally identical HMAC tokens.
     */
    public function issuePasswordResetToken(string $userId): string
    {
        return $this->issuePurposeToken(self::PURPOSE_PASSWORD_RESET, $userId);
    }

    /**
     * @return string|null The user id the token was issued for, or null if invalid/expired.
     */
    public function verifyPasswordResetToken(string $token): ?string
    {
        return $this->verifyPurposeToken(self::PURPOSE_PASSWORD_RESET, $token, $this->passwordResetTtlSeconds);
    }

    /**
     * Look up a user by id through the app's resolver — for resuming a session
     * (`Session::resolve()` already returned `user_id`; the app still needs the rest of
     * the user's data) without every call site needing its own resolver reference.
     *
     * @return array{id: string, passwordHash: string, locked: bool, emailVerifiedAt: ?string}|null
     */
    public function findUserById(string $userId): ?array
    {
        return ($this->findById)($userId);
    }

    private function fail(string $reason, string $identifier, ?string $userId = null): AuthResult
    {
        Event::dispatch(Event::LOGIN_FAILED, ['identifier' => $identifier, 'userId' => $userId, 'reason' => $reason]);

        return AuthResult::failure($reason);
    }

    private function issuePurposeToken(string $purpose, string $userId): string
    {
        $payload = $purpose . '.' . self::base64UrlEncode($userId) . '.' . time();

        return $payload . '.' . HMAC::sign($payload, $this->hmacSecret);
    }

    private function verifyPurposeToken(string $purpose, string $token, int $ttlSeconds): ?string
    {
        $parts = explode('.', $token);

        if (count($parts) !== 4) {
            return null;
        }

        [$tokenPurpose, $encodedUserId, $timestamp, $signature] = $parts;

        if ($tokenPurpose !== $purpose || !ctype_digit($timestamp) || (int) $timestamp < time() - $ttlSeconds) {
            return null;
        }

        $payload = $tokenPurpose . '.' . $encodedUserId . '.' . $timestamp;

        return HMAC::verify($payload, $signature, $this->hmacSecret) ? self::base64UrlDecode($encodedUserId) : null;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
