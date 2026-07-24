<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache with three drivers: file (`/storage/cache`), database (the `caches`
 * table, DDL.sql), and Redis. One instance is bound to one driver at construction.
 *
 * The database driver's cardinal rule (Architecture.md §9): `key_hash` is only an index
 * shortcut, never trusted alone — every read compares the row's stored `cache_key`
 * against the requested key, and treats a mismatch as a miss exactly like a hash
 * collision would demand, whether or not one has actually occurred.
 *
 * The Redis driver accepts any object exposing get/set/setex/del/exists/keys (typically
 * a real `\Redis` instance) rather than being hard-typed to the `Redis` class, so it has
 * no dependency on ext-redis being loaded to even declare this file, and can be
 * exercised in tests with a plain fake — DeploymentTopology.md §1 is explicit that
 * ext-redis must not be a hard package-level requirement.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Cache implements CacheInterface
{
    public const DRIVER_FILE = 'file';
    public const DRIVER_DATABASE = 'database';
    public const DRIVER_REDIS = 'redis';

    private const TABLE = 'caches';
    private const REDIS_PREFIX = 'clarity:cache:';
    private const RESERVED_KEY_CHARACTERS = '/[{}()\/\\\\@:]/';

    public function __construct(
        private readonly string $driver,
        private readonly ?string $path = null,
        private readonly ?string $context = null,
        private readonly ?object $redis = null,
    ) {
        if (!in_array($driver, [self::DRIVER_FILE, self::DRIVER_DATABASE, self::DRIVER_REDIS], true)) {
            throw new InvalidArgumentException(sprintf('Unknown cache driver "%s".', $driver));
        }

        if ($driver === self::DRIVER_FILE && $path === null) {
            throw new InvalidArgumentException('File driver requires $path.');
        }

        if ($driver === self::DRIVER_REDIS && $redis === null) {
            throw new InvalidArgumentException('Redis driver requires $redis.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        self::assertValidKey($key);

        $result = $this->read($key);

        return $result['hit'] ? $result['value'] : $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        self::assertValidKey($key);

        return $this->write($key, $value, self::ttlToSeconds($ttl));
    }

    public function delete(string $key): bool
    {
        self::assertValidKey($key);

        return match ($this->driver) {
            self::DRIVER_FILE => self::deleteQuietly($this->filePath($key)),
            self::DRIVER_DATABASE => $this->deleteFromDatabase($key),
            self::DRIVER_REDIS => (bool) $this->redis->del(self::REDIS_PREFIX . $key),
        };
    }

    public function clear(): bool
    {
        return match ($this->driver) {
            self::DRIVER_FILE => self::clearDirectory($this->path),
            self::DRIVER_DATABASE => $this->clearDatabase(),
            self::DRIVER_REDIS => self::clearRedisNamespace($this->redis),
        };
    }

    public function has(string $key): bool
    {
        self::assertValidKey($key);

        return $this->read($key)['hit'];
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $allSucceeded = true;

        foreach ($values as $key => $value) {
            $allSucceeded = $this->set((string) $key, $value, $ttl) && $allSucceeded;
        }

        return $allSucceeded;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $allSucceeded = true;

        foreach ($keys as $key) {
            $allSucceeded = $this->delete($key) && $allSucceeded;
        }

        return $allSucceeded;
    }

    /**
     * @return array{hit: bool, value: mixed}
     */
    private function read(string $key): array
    {
        return match ($this->driver) {
            self::DRIVER_FILE => $this->readFromFile($key),
            self::DRIVER_DATABASE => $this->readFromDatabase($key),
            self::DRIVER_REDIS => $this->readFromRedis($key),
        };
    }

    private function write(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        return match ($this->driver) {
            self::DRIVER_FILE => $this->writeToFile($key, $value, $ttlSeconds),
            self::DRIVER_DATABASE => $this->writeToDatabase($key, $value, $ttlSeconds),
            self::DRIVER_REDIS => $this->writeToRedis($key, $value, $ttlSeconds),
        };
    }

    // -- File driver ---------------------------------------------------------------

    /**
     * @return array{hit: bool, value: mixed}
     */
    private function readFromFile(string $key): array
    {
        $path = $this->filePath($key);

        if (!is_file($path)) {
            return ['hit' => false, 'value' => null];
        }

        $payload = @unserialize((string) file_get_contents($path));

        if (!is_array($payload) || !array_key_exists('value', $payload)) {
            return ['hit' => false, 'value' => null];
        }

        if ($payload['expiresAt'] !== null && $payload['expiresAt'] < time()) {
            self::deleteQuietly($path);

            return ['hit' => false, 'value' => null];
        }

        return ['hit' => true, 'value' => $payload['value']];
    }

    private function writeToFile(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        $path = $this->filePath($key);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $payload = serialize([
            'expiresAt' => $ttlSeconds !== null ? time() + $ttlSeconds : null,
            'value' => $value,
        ]);

        return file_put_contents($path, $payload, LOCK_EX) !== false;
    }

    private function filePath(string $key): string
    {
        return rtrim((string) $this->path, '/') . '/' . hash('sha256', $key) . '.cache';
    }

    private static function clearDirectory(string $path): bool
    {
        foreach (glob(rtrim($path, '/') . '/*.cache') ?: [] as $file) {
            self::deleteQuietly($file);
        }

        return true;
    }

    private static function deleteQuietly(string $path): bool
    {
        return !is_file($path) || unlink($path);
    }

    // -- Database driver -------------------------------------------------------------

    /**
     * @return array{hit: bool, value: mixed}
     */
    private function readFromDatabase(string $key): array
    {
        DB::run(
            'SELECT cache_key, cache_value, expires_at FROM ' . self::TABLE . ' WHERE key_hash = ?',
            [self::keyHash($key)],
            $this->context
        );
        $row = DB::fetch();

        // Never trust key_hash alone (Architecture.md §9): the stored cache_key must
        // match the requested key exactly, or this is treated as a miss.
        if ($row === [] || $row['cache_key'] !== $key) {
            return ['hit' => false, 'value' => null];
        }

        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            DB::delete(self::TABLE, ['key_hash' => self::keyHash($key)], $this->context);

            return ['hit' => false, 'value' => null];
        }

        return ['hit' => true, 'value' => unserialize($row['cache_value'])];
    }

    private function writeToDatabase(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        $keyHash = self::keyHash($key);
        $expiresAt = $ttlSeconds !== null
            ? (new DateTimeImmutable("+{$ttlSeconds} seconds"))->format('Y-m-d H:i:s')
            : null;

        // Upsert via delete-then-insert: portable across MySQL/PostgreSQL/SQLite without
        // relying on dialect-specific "ON DUPLICATE KEY"/"ON CONFLICT" syntax.
        DB::delete(self::TABLE, ['key_hash' => $keyHash], $this->context);
        DB::insert(self::TABLE, [
            'key_hash' => $keyHash,
            'cache_key' => $key,
            'cache_value' => serialize($value),
            'encoding' => 'serialize',
            'expires_at' => $expiresAt,
        ], DB::ID_TYPE_INT, $this->context);

        return true;
    }

    private function deleteFromDatabase(string $key): bool
    {
        DB::delete(self::TABLE, ['key_hash' => self::keyHash($key)], $this->context);

        return true;
    }

    private function clearDatabase(): bool
    {
        DB::delete(self::TABLE, [], $this->context);

        return true;
    }

    private static function keyHash(string $key): string
    {
        return hash('sha256', $key, true);
    }

    // -- Redis driver ------------------------------------------------------------

    /**
     * @return array{hit: bool, value: mixed}
     */
    private function readFromRedis(string $key): array
    {
        $value = $this->redis->get(self::REDIS_PREFIX . $key);

        if ($value === false) {
            return ['hit' => false, 'value' => null];
        }

        return ['hit' => true, 'value' => unserialize($value)];
    }

    private function writeToRedis(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        $serialized = serialize($value);
        $redisKey = self::REDIS_PREFIX . $key;

        return (bool) ($ttlSeconds !== null
            ? $this->redis->setex($redisKey, $ttlSeconds, $serialized)
            : $this->redis->set($redisKey, $serialized));
    }

    private static function clearRedisNamespace(object $redis): bool
    {
        $keys = $redis->keys(self::REDIS_PREFIX . '*');

        if ($keys !== []) {
            $redis->del($keys);
        }

        return true;
    }

    private static function ttlToSeconds(DateInterval|int|null $ttl): ?int
    {
        if ($ttl === null || is_int($ttl)) {
            return $ttl;
        }

        $now = new DateTimeImmutable();

        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }

    private static function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw new CacheInvalidArgumentException('Cache key must not be empty.');
        }

        if (preg_match(self::RESERVED_KEY_CHARACTERS, $key) === 1) {
            throw new CacheInvalidArgumentException(
                sprintf('Cache key "%s" contains a character reserved by PSR-16 ({}()/\@:).', $key)
            );
        }
    }
}
