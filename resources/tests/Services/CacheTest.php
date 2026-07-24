<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services;

use DateInterval;
use Monad\Clarity\Services\Cache;
use Monad\Clarity\Services\CacheInvalidArgumentException;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Schema\Blueprint;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $fileCacheDirectory;

    #[After]
    public function cleanUp(): void
    {
        if (isset($this->fileCacheDirectory) && is_dir($this->fileCacheDirectory)) {
            foreach (glob($this->fileCacheDirectory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->fileCacheDirectory);
        }

        DB::reset();
    }

    private function fileCache(): Cache
    {
        $this->fileCacheDirectory = sys_get_temp_dir() . '/clarity-cache-test-' . bin2hex(random_bytes(8));

        return new Cache(driver: Cache::DRIVER_FILE, path: $this->fileCacheDirectory);
    }

    private function databaseCache(): Cache
    {
        DB::useConnection(new PDO('sqlite::memory:'));
        Schema::createTable('caches', function (Blueprint $table) {
            $table->binary('key_hash', 32);
            $table->string('cache_key', 512);
            $table->binary('cache_value');
            $table->string('encoding', 20, default: 'serialize');
            $table->datetime('expires_at', nullable: true);
            $table->datetime('created_at', default: Schema::raw('CURRENT_TIMESTAMP'));
            $table->datetime('updated_at', default: Schema::raw('CURRENT_TIMESTAMP'));
            $table->primary('key_hash');
        });

        return new Cache(driver: Cache::DRIVER_DATABASE);
    }

    private function redisCache(): Cache
    {
        return new Cache(driver: Cache::DRIVER_REDIS, redis: new FakeRedis());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function driverProvider(): array
    {
        return [['file'], ['database'], ['redis']];
    }

    private function cacheFor(string $driver): Cache
    {
        return match ($driver) {
            'file' => $this->fileCache(),
            'database' => $this->databaseCache(),
            'redis' => $this->redisCache(),
        };
    }

    #[DataProvider('driverProvider')]
    public function testSetAndGetRoundTrip(string $driver): void
    {
        $cache = $this->cacheFor($driver);

        self::assertTrue($cache->set('greeting', ['hello' => 'world']));
        self::assertSame(['hello' => 'world'], $cache->get('greeting'));
    }

    #[DataProvider('driverProvider')]
    public function testGetReturnsDefaultForMissingKey(string $driver): void
    {
        self::assertSame('fallback', $this->cacheFor($driver)->get('missing', 'fallback'));
    }

    #[DataProvider('driverProvider')]
    public function testHasReflectsPresence(string $driver): void
    {
        $cache = $this->cacheFor($driver);

        self::assertFalse($cache->has('key'));
        $cache->set('key', 'value');
        self::assertTrue($cache->has('key'));
    }

    #[DataProvider('driverProvider')]
    public function testDeleteRemovesEntry(string $driver): void
    {
        $cache = $this->cacheFor($driver);
        $cache->set('key', 'value');

        self::assertTrue($cache->delete('key'));
        self::assertFalse($cache->has('key'));
    }

    #[DataProvider('driverProvider')]
    public function testClearRemovesEverything(string $driver): void
    {
        $cache = $this->cacheFor($driver);
        $cache->set('a', 1);
        $cache->set('b', 2);

        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    #[DataProvider('driverProvider')]
    public function testNegativeTtlMeansAlreadyExpired(string $driver): void
    {
        $cache = $this->cacheFor($driver);

        $cache->set('key', 'value', -1);

        self::assertNull($cache->get('key'));
        self::assertFalse($cache->has('key'));
    }

    #[DataProvider('driverProvider')]
    public function testNullTtlMeansNeverExpires(string $driver): void
    {
        $cache = $this->cacheFor($driver);

        $cache->set('key', 'value', null);

        self::assertSame('value', $cache->get('key'));
    }

    #[DataProvider('driverProvider')]
    public function testDateIntervalTtlInTheFutureIsNotExpired(string $driver): void
    {
        $cache = $this->cacheFor($driver);

        $cache->set('key', 'value', new DateInterval('PT1H'));

        self::assertSame('value', $cache->get('key'));
    }

    #[DataProvider('driverProvider')]
    public function testDateIntervalTtlResolvingToThePastIsExpired(string $driver): void
    {
        $cache = $this->cacheFor($driver);

        $pastInterval = new DateInterval('PT1H');
        $pastInterval->invert = 1; // subtract instead of add -> resolves 1 hour in the past

        $cache->set('key', 'value', $pastInterval);

        self::assertNull($cache->get('key'));
        self::assertFalse($cache->has('key'));
    }

    #[DataProvider('driverProvider')]
    public function testGetSetDeleteMultiple(string $driver): void
    {
        $cache = $this->cacheFor($driver);

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => null], (array) $cache->getMultiple(['a', 'b', 'c']));

        self::assertTrue($cache->deleteMultiple(['a', 'b']));
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    #[DataProvider('driverProvider')]
    public function testEmptyKeyThrows(string $driver): void
    {
        $this->expectException(CacheInvalidArgumentException::class);

        $this->cacheFor($driver)->get('');
    }

    #[DataProvider('driverProvider')]
    public function testReservedCharacterInKeyThrows(string $driver): void
    {
        $this->expectException(CacheInvalidArgumentException::class);

        $this->cacheFor($driver)->get('bad{key}');
    }

    public function testConstructorRejectsUnknownDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cache(driver: 'memcached');
    }

    public function testConstructorRequiresPathForFileDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cache(driver: Cache::DRIVER_FILE);
    }

    public function testConstructorRequiresRedisInstanceForRedisDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cache(driver: Cache::DRIVER_REDIS);
    }

    /**
     * The database driver's cardinal rule (Architecture.md §9): key_hash is an index
     * shortcut, never trusted alone. A real SHA-256 collision can't be constructed for a
     * test, so this proves the code path directly — a row whose key_hash matches the
     * requested key's hash, but whose stored cache_key is a *different* string, must be
     * treated as a miss, not returned as a hit.
     */
    public function testDatabaseDriverNeverTrustsKeyHashAlone(): void
    {
        $cache = $this->databaseCache();

        DB::insert('caches', [
            'key_hash' => hash('sha256', 'requested-key', true),
            'cache_key' => 'a-completely-different-key-sharing-the-same-hash',
            'cache_value' => serialize('leaked value'),
            'encoding' => 'serialize',
            'expires_at' => null,
        ], DB::ID_TYPE_INT);

        self::assertNull($cache->get('requested-key'));
        self::assertFalse($cache->has('requested-key'));
    }
}

/**
 * Minimal fake satisfying the subset of ext-redis's \Redis surface Cache actually calls
 * (get/set/setex/del/keys) — lets the Redis driver be tested without ext-redis installed
 * or a real Redis server, since DeploymentTopology.md §1 keeps ext-redis optional.
 */
final class FakeRedis
{
    /** @var array<string, array{value: string, expiresAt: ?int}> */
    private array $store = [];

    public function get(string $key): string|false
    {
        if (!isset($this->store[$key])) {
            return false;
        }

        $entry = $this->store[$key];

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] < time()) {
            unset($this->store[$key]);

            return false;
        }

        return $entry['value'];
    }

    public function set(string $key, string $value): bool
    {
        $this->store[$key] = ['value' => $value, 'expiresAt' => null];

        return true;
    }

    public function setex(string $key, int $ttlSeconds, string $value): bool
    {
        $this->store[$key] = ['value' => $value, 'expiresAt' => time() + $ttlSeconds];

        return true;
    }

    /**
     * @param list<string> $keys
     */
    public function del(array|string $keys): int
    {
        $count = 0;

        foreach ((array) $keys as $key) {
            if (isset($this->store[$key])) {
                unset($this->store[$key]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public function keys(string $pattern): array
    {
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

        return array_values(array_filter(array_keys($this->store), fn (string $key) => preg_match($regex, $key) === 1));
    }
}
