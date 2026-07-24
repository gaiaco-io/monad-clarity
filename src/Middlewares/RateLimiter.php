<?php

declare(strict_types=1);

namespace Monad\Clarity\Middlewares;

use Monad\Clarity\Services\Cache;
use Monad\Clarity\Services\Request;
use Monad\Clarity\Services\Response;

/**
 * Fixed-window rate limiting (ReleaseNotes_26.07.md §28) backed by Services\Cache — any
 * of its three drivers works, so the limiter is consistent across a multi-node
 * deployment whenever Cache is configured with the database or Redis driver
 * (DeploymentTopology.md §2; the file driver is single-node only, same caveat as Cache
 * itself).
 *
 * Required call sites per §28.2 aren't all full-request middleware: login and password
 * reset rate-limit a specific identifier (an email address, not the whole route), so
 * `attempt(string $key)` is public and usable directly from Authentication's login flow,
 * independent of `__invoke()`'s per-request pipeline use for public API / LLM / webhook
 * routes.
 *
 * Not `final` — per CrossRepoContracts.md §5, extended by `app/middlewares/` with a
 * zero-argument constructor supplying the app's own Cache instance and limits.
 * `resolveKey()` and `rejectionResponse()` are `protected` extension points.
 *
 * Known, accepted limitations — document rather than paper over:
 * - **Not atomic.** `hit()` is read-then-write; PSR-16 has no atomic increment, and nothing
 *   short of a Redis-only `INCR` path would close this, which would break the clean
 *   three-driver symmetry Cache offers for a case not required here. Under concurrent
 *   requests against the same key, two requests can both read the same count and both
 *   pass — the limit is a strong deterrent, not a hard guarantee. This matters most for
 *   its highest-stakes consumer, login throttling: a parallel burst of credential
 *   guesses can exceed the configured limit before the count catches up.
 * - **Fixed-window boundary burst.** A caller can send `$maxAttempts` at the tail of one
 *   window and another `$maxAttempts` at the head of the next, i.e. up to 2x the limit
 *   within a short span straddling the boundary. A sliding-window or token-bucket
 *   algorithm would close this at the cost of real complexity §28 doesn't ask for.
 *
 * @package Monad\Clarity\Middlewares
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
class RateLimiter
{
    private const CACHE_KEY_PREFIX = 'ratelimit_';

    public function __construct(
        private readonly Cache $cache,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $key = $this->resolveKey($request);
        $window = $this->hit($key);

        if (!$window['allowed']) {
            return $this->rejectionResponse($window['retryAfterSeconds']);
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $window['remaining']);
    }

    /**
     * Record one attempt against $key (an email, IP, API token — whatever identifies the
     * caller for this limit) and report whether it's within the limit.
     */
    public function attempt(string $key): bool
    {
        return $this->hit($key)['allowed'];
    }

    /**
     * Attempts remaining in the current window for $key, without recording a new one.
     */
    public function remaining(string $key): int
    {
        $bucket = $this->currentBucket($key);

        return max(0, $this->maxAttempts - $bucket['count']);
    }

    /**
     * Seconds until $key's current window resets, without recording a new attempt.
     */
    public function availableInSeconds(string $key): int
    {
        $bucket = $this->currentBucket($key);

        return max(0, $bucket['windowStart'] + $this->windowSeconds - time());
    }

    /**
     * Clear $key's window entirely — e.g. after a successful login, so a prior run of
     * failed attempts doesn't count against the next legitimate one.
     */
    public function clear(string $key): void
    {
        $this->cache->delete($this->cacheKey($key));
    }

    /**
     * The identifier a per-request pipeline use limits by — the caller's IP by default.
     * Override to key by an authenticated user id, an API token, or similar.
     */
    protected function resolveKey(Request $request): string
    {
        return $request->ip();
    }

    protected function rejectionResponse(int $retryAfterSeconds): Response
    {
        return Response::json(['error' => 'Too many requests.'], 429)
            ->withHeader('Retry-After', (string) $retryAfterSeconds);
    }

    /**
     * @return array{allowed: bool, remaining: int, retryAfterSeconds: int}
     */
    private function hit(string $key): array
    {
        $bucket = $this->currentBucket($key);
        $bucket['count']++;

        $this->cache->set($this->cacheKey($key), $bucket, $this->windowSeconds);

        return [
            'allowed' => $bucket['count'] <= $this->maxAttempts,
            'remaining' => max(0, $this->maxAttempts - $bucket['count']),
            'retryAfterSeconds' => max(0, $bucket['windowStart'] + $this->windowSeconds - time()),
        ];
    }

    /**
     * @return array{count: int, windowStart: int}
     */
    private function currentBucket(string $key): array
    {
        $bucket = $this->cache->get($this->cacheKey($key));
        $now = time();

        if (!is_array($bucket) || !isset($bucket['windowStart'], $bucket['count']) || $bucket['windowStart'] + $this->windowSeconds <= $now) {
            return ['count' => 0, 'windowStart' => $now];
        }

        return $bucket;
    }

    /**
     * Cache keys reject PSR-16's reserved characters (including `:`, which an IPv6
     * address contains), so $key is hashed rather than concatenated in raw.
     */
    private function cacheKey(string $key): string
    {
        return self::CACHE_KEY_PREFIX . hash('sha256', $key);
    }
}
