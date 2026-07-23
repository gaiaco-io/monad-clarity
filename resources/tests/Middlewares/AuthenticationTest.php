<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Middlewares;

use Gaia\Clarity\Console\Setup;
use Gaia\Clarity\Middlewares\Authentication;
use Gaia\Clarity\Middlewares\Authentication\AuthenticationException;
use Gaia\Clarity\Middlewares\RateLimiter;
use Gaia\Clarity\Services\Cache;
use Gaia\Clarity\Services\DB;
use Gaia\Clarity\Services\Event;
use Gaia\Clarity\Services\HttpClient;
use Gaia\Clarity\Services\Schema;
use Gaia\Clarity\Services\Session;
use Gaia\Clarity\Utils\Hash;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

/**
 * Google SSO coverage runs a real HTTP round trip against a local PHP built-in server
 * fixture (resources/tests/fixtures/google-oauth-server.php), the same pattern
 * HttpClientTest uses, so these tests never depend on real Google infrastructure.
 */
final class AuthenticationTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 18944;
    private const SECRET = 'test-hmac-secret';

    /** @var resource|null */
    private static $serverProcess = null;

    /** @var array<string, array{id: string, passwordHash: string, locked: bool, emailVerifiedAt: ?string}> */
    private array $users = [];

    public static function setUpBeforeClass(): void
    {
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', self::HOST . ':' . self::PORT, __DIR__ . '/../fixtures/google-oauth-server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $deadline = microtime(true) + 3;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        self::fail('Fixture Google OAuth server did not start in time.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
    }

    #[Before]
    public function setUpDatabaseAndUsers(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));
        Schema::createTable('sessions', Setup::sessionsBlueprint('sqlite'));
        Schema::createTable('caches', Setup::cachesBlueprint());

        $this->users = [
            'user@example.com' => [
                'id' => 'user-1',
                'passwordHash' => Hash::make('correct-password'),
                'locked' => false,
                'emailVerifiedAt' => null,
            ],
            'locked@example.com' => [
                'id' => 'user-2',
                'passwordHash' => Hash::make('correct-password'),
                'locked' => true,
                'emailVerifiedAt' => null,
            ],
        ];
    }

    #[After]
    public function resetState(): void
    {
        DB::reset();
        Session::reset();
        Event::forget();
    }

    private function auth(?RateLimiter $rateLimiter = null): Authentication
    {
        return new Authentication(
            findByCredential: fn (string $identifier): ?array => $this->users[$identifier] ?? null,
            findById: fn (string $id): ?array => self::firstWhere($this->users, fn (array $u) => $u['id'] === $id),
            hmacSecret: self::SECRET,
            loginRateLimiter: $rateLimiter ?? $this->rateLimiter(),
        );
    }

    private function authWithTtls(?int $emailVerificationTtlSeconds = null, ?int $passwordResetTtlSeconds = null): Authentication
    {
        return new Authentication(
            findByCredential: fn (string $identifier): ?array => $this->users[$identifier] ?? null,
            findById: fn (string $id): ?array => self::firstWhere($this->users, fn (array $u) => $u['id'] === $id),
            hmacSecret: self::SECRET,
            loginRateLimiter: $this->rateLimiter(),
            emailVerificationTtlSeconds: $emailVerificationTtlSeconds ?? 60 * 60 * 24,
            passwordResetTtlSeconds: $passwordResetTtlSeconds ?? 60 * 60,
        );
    }

    private function authWithGoogle(): Authentication
    {
        return new Authentication(
            findByCredential: fn (string $identifier): ?array => $this->users[$identifier] ?? null,
            findById: fn (string $id): ?array => self::firstWhere($this->users, fn (array $u) => $u['id'] === $id),
            hmacSecret: self::SECRET,
            loginRateLimiter: $this->rateLimiter(),
            httpClient: new HttpClient(),
            googleClientId: 'test-client-id',
            googleClientSecret: 'test-client-secret',
            googleTokenEndpoint: 'http://' . self::HOST . ':' . self::PORT . '/o/oauth2/token',
            googleTokenInfoEndpoint: 'http://' . self::HOST . ':' . self::PORT . '/oauth2/v3/tokeninfo',
        );
    }

    private function rateLimiter(int $maxAttempts = 100): RateLimiter
    {
        return new RateLimiter(new Cache(driver: Cache::DRIVER_DATABASE), $maxAttempts, 60);
    }

    /**
     * @param array<string, array{id: string, passwordHash: string, locked: bool, emailVerifiedAt: ?string}> $users
     */
    private static function firstWhere(array $users, callable $predicate): ?array
    {
        foreach ($users as $user) {
            if ($predicate($user)) {
                return $user;
            }
        }

        return null;
    }

    // -- Credential login ----------------------------------------------------------

    public function testAttemptSucceedsWithCorrectCredentials(): void
    {
        $result = $this->auth()->attempt('user@example.com', 'correct-password', '127.0.0.1', 'PHPUnit/1.0');

        self::assertTrue($result->success);
        self::assertSame('user-1', $result->userId);
        self::assertNotEmpty($result->sessionToken);
        self::assertNotNull(Session::resolve($result->sessionToken));
    }

    public function testAttemptFailsWithWrongPassword(): void
    {
        $result = $this->auth()->attempt('user@example.com', 'wrong-password', '127.0.0.1', 'PHPUnit/1.0');

        self::assertFalse($result->success);
        self::assertSame(Authentication::FAILURE_INVALID_CREDENTIALS, $result->failureReason);
        self::assertNull($result->sessionToken);
    }

    public function testAttemptFailsForUnknownIdentifierWithoutRevealingWhichCase(): void
    {
        $result = $this->auth()->attempt('nobody@example.com', 'anything', '127.0.0.1', 'PHPUnit/1.0');

        self::assertFalse($result->success);
        self::assertSame(Authentication::FAILURE_INVALID_CREDENTIALS, $result->failureReason);
    }

    public function testAttemptFailsForALockedAccountEvenWithTheCorrectPassword(): void
    {
        $result = $this->auth()->attempt('locked@example.com', 'correct-password', '127.0.0.1', 'PHPUnit/1.0');

        self::assertFalse($result->success);
        self::assertSame(Authentication::FAILURE_ACCOUNT_LOCKED, $result->failureReason);
    }

    public function testAttemptIsThrottledPerIdentifier(): void
    {
        $auth = $this->auth($this->rateLimiter(maxAttempts: 1));

        $auth->attempt('user@example.com', 'wrong-password', '127.0.0.1', 'PHPUnit/1.0');
        $result = $auth->attempt('user@example.com', 'correct-password', '127.0.0.1', 'PHPUnit/1.0');

        self::assertFalse($result->success);
        self::assertSame(Authentication::FAILURE_RATE_LIMITED, $result->failureReason);
    }

    public function testSuccessfulLoginClearsThePriorThrottleCount(): void
    {
        $auth = $this->auth($this->rateLimiter(maxAttempts: 2));

        $auth->attempt('user@example.com', 'wrong-password', '127.0.0.1', 'PHPUnit/1.0');
        $auth->attempt('user@example.com', 'correct-password', '127.0.0.1', 'PHPUnit/1.0');

        // The failed attempt above used 1 of 2; a successful login clears the counter,
        // so a fresh wrong-then-right pair should not be blocked by carry-over.
        $auth->attempt('user@example.com', 'wrong-password', '127.0.0.1', 'PHPUnit/1.0');
        $result = $auth->attempt('user@example.com', 'correct-password', '127.0.0.1', 'PHPUnit/1.0');

        self::assertTrue($result->success);
    }

    public function testAttemptFlagsWhenTheStoredHashNeedsRehashing(): void
    {
        $this->users['weak@example.com'] = [
            'id' => 'user-3',
            'passwordHash' => password_hash('correct-password', PASSWORD_BCRYPT, ['cost' => 4]),
            'locked' => false,
            'emailVerifiedAt' => null,
        ];

        $result = $this->auth()->attempt('weak@example.com', 'correct-password', '127.0.0.1', 'PHPUnit/1.0');

        self::assertTrue($result->success);
        self::assertTrue($result->needsRehash);
    }

    public function testAttemptPromotesAndRegeneratesAnExistingGuestSessionInPlace(): void
    {
        $guest = Session::start(null, '127.0.0.1', 'PHPUnit/1.0', ['cart' => ['sku-1']]);

        $result = $this->auth()->attempt('user@example.com', 'correct-password', '127.0.0.1', 'PHPUnit/1.0', $guest['id']);

        self::assertTrue($result->success);
        self::assertSame($guest['id'], $result->sessionId);
        self::assertNotSame($guest['token'], $result->sessionToken);
        self::assertNull(Session::resolve($guest['token']));

        $row = Session::resolve($result->sessionToken);
        self::assertSame('user-1', $row['user_id']);
        self::assertSame(['cart' => ['sku-1']], $row['payload']);
    }

    public function testAttemptDispatchesLoginSuccessEvent(): void
    {
        $captured = null;
        Event::listen(Event::LOGIN_SUCCESS, function (array $payload) use (&$captured) {
            $captured = $payload;
        });

        $this->auth()->attempt('user@example.com', 'correct-password', '127.0.0.1', 'PHPUnit/1.0');

        self::assertSame('user-1', $captured['userId']);
    }

    public function testAttemptDispatchesLoginFailedEvent(): void
    {
        $captured = null;
        Event::listen(Event::LOGIN_FAILED, function (array $payload) use (&$captured) {
            $captured = $payload;
        });

        $this->auth()->attempt('user@example.com', 'wrong-password', '127.0.0.1', 'PHPUnit/1.0');

        self::assertSame(Authentication::FAILURE_INVALID_CREDENTIALS, $captured['reason']);
    }

    public function testUnknownIdentifierAndWrongPasswordReportTheSameFailureReason(): void
    {
        // This is behavioral parity only, NOT a regression guard for the timing fix —
        // both branches returned this same reason even before dummyPasswordHash()
        // existed, since only the response *time* differed, not the outcome. The actual
        // fix (attempt() calls Hash::verify() exactly once per call, against the real
        // hash if $user was found or a fixed dummy hash otherwise) is structural and
        // deliberately left unguarded by an automated test — a real wall-clock timing
        // assertion would be flaky in CI, and Hash is a static utility with nothing to
        // inject a spy into. Verified by code inspection instead: confirm attempt()
        // calls Hash::verify() unconditionally exactly once, on every code path.
        $unknown = $this->auth()->attempt('nobody@example.com', 'x', '127.0.0.1', 'PHPUnit/1.0');
        $wrongPassword = $this->auth()->attempt('user@example.com', 'wrong-password', '127.0.0.1', 'PHPUnit/1.0');

        self::assertSame($unknown->failureReason, $wrongPassword->failureReason);
    }

    public function testAttemptRenewsAPromotedGuestSessionsExpiry(): void
    {
        Session::configure(lifetimeSeconds: -1); // guest session starts already-expired
        $guest = Session::start(null, '127.0.0.1', 'PHPUnit/1.0');
        Session::configure(lifetimeSeconds: 3600);

        $result = $this->auth()->attempt('user@example.com', 'correct-password', '127.0.0.1', 'PHPUnit/1.0', $guest['id']);

        self::assertTrue($result->success);
        self::assertNotNull(Session::resolve($result->sessionToken));
    }

    public function testLoginRejectsALockedUserDirectlyEvenWithoutGoingThroughAttempt(): void
    {
        // login() is the shared primitive attempt(), resumeFromRememberToken(), and any
        // future caller (Google SSO) all route through — it must enforce the lock
        // invariant on its own rather than trusting every caller to have checked first.
        $result = $this->auth()->login('user-2', '127.0.0.1', 'PHPUnit/1.0'); // user-2 is locked

        self::assertFalse($result->success);
        self::assertSame(Authentication::FAILURE_ACCOUNT_LOCKED, $result->failureReason);
    }

    // -- findUserById ----------------------------------------------------------------

    public function testFindUserByIdDelegatesToTheResolver(): void
    {
        $user = $this->auth()->findUserById('user-1');

        self::assertNotNull($user);
        self::assertSame('user-1', $user['id']);
    }

    // -- Remember-me -------------------------------------------------------------

    public function testRememberTokenResumesAndRotates(): void
    {
        $auth = $this->auth();
        $rememberToken = $auth->issueRememberToken('user-1', '127.0.0.1', 'PHPUnit/1.0');

        $result = $auth->resumeFromRememberToken($rememberToken, '127.0.0.1', 'PHPUnit/1.0');

        self::assertNotNull($result);
        self::assertTrue($result->success);
        self::assertSame('user-1', $result->userId);
        self::assertNotSame($rememberToken, $result->rememberToken);

        // The old remember token no longer resolves — it was rotated, not reused.
        self::assertNull(Session::resolve($rememberToken));
    }

    public function testRememberTokenRotationInvalidatesReplay(): void
    {
        $auth = $this->auth();
        $rememberToken = $auth->issueRememberToken('user-1', '127.0.0.1', 'PHPUnit/1.0');

        $auth->resumeFromRememberToken($rememberToken, '127.0.0.1', 'PHPUnit/1.0');

        // Reusing the same (now-stale) remember token a second time must fail.
        self::assertNull($auth->resumeFromRememberToken($rememberToken, '127.0.0.1', 'PHPUnit/1.0'));
    }

    public function testResumeFromRememberTokenRejectsAGuestSession(): void
    {
        $guest = Session::start(null, '127.0.0.1', 'PHPUnit/1.0');

        self::assertNull($this->auth()->resumeFromRememberToken($guest['token'], '127.0.0.1', 'PHPUnit/1.0'));
    }

    public function testResumeFromRememberTokenRejectsAnUnknownToken(): void
    {
        self::assertNull($this->auth()->resumeFromRememberToken('never-issued', '127.0.0.1', 'PHPUnit/1.0'));
    }

    public function testResumeFromRememberTokenRejectsALockedUser(): void
    {
        $auth = $this->auth();
        $rememberToken = $auth->issueRememberToken('user-2', '127.0.0.1', 'PHPUnit/1.0'); // user-2 is locked

        self::assertNull($auth->resumeFromRememberToken($rememberToken, '127.0.0.1', 'PHPUnit/1.0'));
    }

    // -- Email verification tokens ------------------------------------------------

    public function testEmailVerificationTokenRoundTrips(): void
    {
        $auth = $this->auth();
        $token = $auth->issueEmailVerificationToken('user-1');

        self::assertSame('user-1', $auth->verifyEmailVerificationToken($token));
    }

    public function testExpiredEmailVerificationTokenIsRejected(): void
    {
        $auth = $this->authWithTtls(emailVerificationTtlSeconds: -1);
        $token = $auth->issueEmailVerificationToken('user-1');

        self::assertNull($auth->verifyEmailVerificationToken($token));
    }

    public function testExpiredPasswordResetTokenIsRejected(): void
    {
        $auth = $this->authWithTtls(passwordResetTtlSeconds: -1);
        $token = $auth->issuePasswordResetToken('user-1');

        self::assertNull($auth->verifyPasswordResetToken($token));
    }

    public function testTokenSignedForADifferentPurposeIsRejected(): void
    {
        $auth = $this->auth();
        $passwordResetToken = $auth->issuePasswordResetToken('user-1');

        self::assertNull($auth->verifyEmailVerificationToken($passwordResetToken));
    }

    public function testMalformedEmailVerificationTokenIsRejected(): void
    {
        self::assertNull($this->auth()->verifyEmailVerificationToken('not-a-real-token'));
    }

    public function testEmailVerificationTokenSignedWithADifferentSecretIsRejected(): void
    {
        $token = $this->auth()->issueEmailVerificationToken('user-1');

        $otherAuth = new Authentication(
            findByCredential: fn () => null,
            findById: fn () => null,
            hmacSecret: 'a-completely-different-secret',
            loginRateLimiter: $this->rateLimiter(),
        );

        self::assertNull($otherAuth->verifyEmailVerificationToken($token));
    }

    // -- Password reset tokens -----------------------------------------------------

    public function testPasswordResetTokenRoundTrips(): void
    {
        $auth = $this->auth();
        $token = $auth->issuePasswordResetToken('user-1');

        self::assertSame('user-1', $auth->verifyPasswordResetToken($token));
    }

    public function testPasswordResetTokenCannotBeReplayedAsAnEmailVerificationToken(): void
    {
        $auth = $this->auth();
        $token = $auth->issuePasswordResetToken('user-1');

        self::assertNull($auth->verifyEmailVerificationToken($token));
        self::assertSame('user-1', $auth->verifyPasswordResetToken($token));
    }

    // -- Google SSO -----------------------------------------------------------------

    public function testVerifyGoogleAuthorizationCodeReturnsTheVerifiedProfile(): void
    {
        $profile = $this->authWithGoogle()->verifyGoogleAuthorizationCode('valid-code', 'https://app.test/callback');

        self::assertSame('google-user-1', $profile['googleId']);
        self::assertSame('user@example.com', $profile['email']);
        self::assertTrue($profile['emailVerified']);
        self::assertSame('Test User', $profile['name']);
    }

    public function testVerifyGoogleAuthorizationCodeReportsUnverifiedEmail(): void
    {
        $profile = $this->authWithGoogle()->verifyGoogleAuthorizationCode('unverified-email-code', 'https://app.test/callback');

        self::assertFalse($profile['emailVerified']);
    }

    public function testVerifyGoogleAuthorizationCodeRejectsWrongAudience(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('audience');

        $this->authWithGoogle()->verifyGoogleAuthorizationCode('wrong-audience-code', 'https://app.test/callback');
    }

    public function testVerifyGoogleAuthorizationCodeRejectsWrongIssuer(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('issuer');

        $this->authWithGoogle()->verifyGoogleAuthorizationCode('bad-issuer-code', 'https://app.test/callback');
    }

    public function testVerifyGoogleAuthorizationCodeRejectsAnExpiredIdToken(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');

        $this->authWithGoogle()->verifyGoogleAuthorizationCode('expired-code', 'https://app.test/callback');
    }

    public function testVerifyGoogleAuthorizationCodeRejectsAFailedTokenExchange(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('exchange');

        $this->authWithGoogle()->verifyGoogleAuthorizationCode('invalid-code', 'https://app.test/callback');
    }

    public function testVerifyGoogleAuthorizationCodeRejectsAResponseWithNoIdToken(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('id_token');

        $this->authWithGoogle()->verifyGoogleAuthorizationCode('no-id-token-code', 'https://app.test/callback');
    }

    public function testVerifyGoogleAuthorizationCodeThrowsWhenNotConfigured(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->auth()->verifyGoogleAuthorizationCode('valid-code', 'https://app.test/callback');
    }
}
