<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Middlewares;

use Monad\Clarity\Middlewares\RateLimiter;
use Monad\Clarity\Services\Cache;
use Monad\Clarity\Services\Request;
use Monad\Clarity\Services\Response;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/clarity-ratelimiter-' . bin2hex(random_bytes(8));
        mkdir($this->cacheDirectory, 0775, true);
    }

    #[After]
    public function cleanUp(): void
    {
        foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->cacheDirectory);
    }

    private function cache(): Cache
    {
        return new Cache(driver: Cache::DRIVER_FILE, path: $this->cacheDirectory);
    }

    private function next(): callable
    {
        return static fn (Request $request): Response => Response::text('ok');
    }

    public function testAllowsAttemptsUpToTheLimit(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 3, windowSeconds: 60);

        self::assertTrue($limiter->attempt('user@example.com'));
        self::assertTrue($limiter->attempt('user@example.com'));
        self::assertTrue($limiter->attempt('user@example.com'));
    }

    public function testRejectsAttemptsOverTheLimit(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 2, windowSeconds: 60);

        $limiter->attempt('user@example.com');
        $limiter->attempt('user@example.com');

        self::assertFalse($limiter->attempt('user@example.com'));
    }

    public function testRemainingDecreasesWithEachAttemptWithoutDoubleCountingAPeek(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 5, windowSeconds: 60);

        self::assertSame(5, $limiter->remaining('user@example.com'));

        $limiter->attempt('user@example.com');

        self::assertSame(4, $limiter->remaining('user@example.com'));
        self::assertSame(4, $limiter->remaining('user@example.com'));
    }

    public function testDifferentKeysAreTrackedIndependently(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 1, windowSeconds: 60);

        self::assertTrue($limiter->attempt('alice@example.com'));
        self::assertTrue($limiter->attempt('bob@example.com'));
        self::assertFalse($limiter->attempt('alice@example.com'));
    }

    public function testClearResetsTheWindowForThatKeyOnly(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 1, windowSeconds: 60);

        $limiter->attempt('alice@example.com');
        $limiter->attempt('bob@example.com');
        self::assertFalse($limiter->attempt('alice@example.com'));

        $limiter->clear('alice@example.com');

        self::assertTrue($limiter->attempt('alice@example.com'));
        self::assertFalse($limiter->attempt('bob@example.com'));
    }

    public function testAvailableInSecondsReflectsTheConfiguredWindow(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 5, windowSeconds: 60);

        $limiter->attempt('user@example.com');
        $seconds = $limiter->availableInSeconds('user@example.com');

        self::assertGreaterThan(55, $seconds);
        self::assertLessThanOrEqual(60, $seconds);
    }

    public function testAvailableInSecondsIsZeroOnceTheWindowHasElapsed(): void
    {
        // windowSeconds: -1 means the window is already in the past the instant it's
        // created — there's nothing left to wait out.
        $limiter = new RateLimiter($this->cache(), maxAttempts: 5, windowSeconds: -1);

        $limiter->attempt('user@example.com');

        self::assertSame(0, $limiter->availableInSeconds('user@example.com'));
    }

    public function testAnExpiredWindowStartsFreshWithoutCarryingOverTheOldCount(): void
    {
        // windowSeconds: -1 means the window is already in the past the instant it's
        // created — every call lands in a brand-new window, so the limit never trips.
        $limiter = new RateLimiter($this->cache(), maxAttempts: 1, windowSeconds: -1);

        self::assertTrue($limiter->attempt('user@example.com'));
        self::assertTrue($limiter->attempt('user@example.com'));
        self::assertTrue($limiter->attempt('user@example.com'));
    }

    public function testIpv6KeyDoesNotTriggerAPsr16ReservedCharacterError(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 2, windowSeconds: 60);

        self::assertTrue($limiter->attempt('2001:db8::1'));
        self::assertTrue($limiter->attempt('2001:db8::1'));
        self::assertFalse($limiter->attempt('2001:db8::1'));
    }

    public function testInvokeAddsRateLimitHeadersOnAllowedRequest(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 5, windowSeconds: 60);
        $request = Request::fromArrays(server: ['REMOTE_ADDR' => '127.0.0.1']);

        $response = $limiter($request, $this->next());

        self::assertSame('ok', $response->content());
        self::assertSame('5', $response->header('X-RateLimit-Limit'));
        self::assertSame('4', $response->header('X-RateLimit-Remaining'));
    }

    public function testInvokeReturns429WithRetryAfterWhenOverLimit(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 1, windowSeconds: 60);
        $request = Request::fromArrays(server: ['REMOTE_ADDR' => '127.0.0.1']);

        $limiter($request, $this->next());
        $response = $limiter($request, $this->next());

        self::assertSame(429, $response->status());
        self::assertNotNull($response->header('Retry-After'));
    }

    public function testInvokeKeysByRequestIpByDefault(): void
    {
        $limiter = new RateLimiter($this->cache(), maxAttempts: 1, windowSeconds: 60);

        $first = Request::fromArrays(server: ['REMOTE_ADDR' => '10.0.0.1']);
        $second = Request::fromArrays(server: ['REMOTE_ADDR' => '10.0.0.2']);

        self::assertSame(200, $limiter($first, $this->next())->status());
        self::assertSame(200, $limiter($second, $this->next())->status());
        self::assertSame(429, $limiter($first, $this->next())->status());
    }
}
