<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services;

use DateTimeImmutable;
use Gaia\Clarity\Utils\CryptographicToken;
use InvalidArgumentException;

/**
 * DB-backed session storage (the `sessions` table, DDL.sql — CrossRepoContracts.md §8).
 * `user_id` is nullable: a session may exist with no associated user (guest browsing,
 * pre-login, pre-authentication CSRF token storage per Middlewares\Csrf).
 *
 * Deliberately does not touch superglobals, cookies, or the HTTP layer — start()/
 * regenerate() return a plaintext token; delivering it as the `mid` cookie (self::
 * COOKIE_NAME) on the outgoing Response is the caller's job (Middlewares\Authentication,
 * Middlewares\Csrf). This keeps Session a pure, independently testable data layer rather
 * than one entangled with request/response state.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class Session
{
    public const COOKIE_NAME = 'mid';

    private const DEFAULT_LIFETIME_SECONDS = 7200;

    private static int $lifetimeSeconds = self::DEFAULT_LIFETIME_SECONDS;

    public static function configure(int $lifetimeSeconds = self::DEFAULT_LIFETIME_SECONDS): void
    {
        self::$lifetimeSeconds = $lifetimeSeconds;
    }

    /**
     * Create a new session row. $userId null starts a guest/pre-login session.
     * $lifetimeSecondsOverride bypasses the globally configured lifetime for this one
     * row — e.g. Middlewares\Authentication's remember-me sessions, which need a much
     * longer TTL than a regular login session without reconfiguring Session globally.
     *
     * @param array<string, mixed> $payload
     * @return array{id: string, token: string, expireAt: string}
     */
    public static function start(
        ?string $userId,
        string $ipAddress,
        string $userAgent,
        array $payload = [],
        ?string $context = null,
        ?int $lifetimeSecondsOverride = null,
    ): array {
        $token = CryptographicToken::generate();
        $expireAt = self::expiryTimestamp($lifetimeSecondsOverride);

        $id = DB::insert('sessions', [
            'user_id' => $userId,
            'digest' => self::digest($token),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'expire_at' => $expireAt,
            'revoked_at' => null,
        ], DB::ID_TYPE_UUID, $context);

        return ['id' => $id, 'token' => $token, 'expireAt' => $expireAt];
    }

    /**
     * Resolve a plaintext token (as read from the `mid` cookie) to its session row.
     * An expired or revoked session — or one that never existed — all resolve to null
     * alike; the caller cannot distinguish why, by design.
     *
     * @return array<string, mixed>|null
     */
    public static function resolve(string $token, ?string $context = null): ?array
    {
        DB::run(
            'SELECT * FROM sessions WHERE digest = ? AND revoked_at IS NULL AND expire_at > ?',
            [self::digest($token), self::now()],
            $context
        );
        $row = DB::fetch();

        if ($row === []) {
            return null;
        }

        $row['payload'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }

    public static function write(string $sessionId, string $key, mixed $value, ?string $context = null): void
    {
        $payload = self::payloadFor($sessionId, $context);
        $payload[$key] = $value;

        DB::update('sessions', ['payload' => json_encode($payload, JSON_THROW_ON_ERROR)], ['id' => $sessionId], $context);
    }

    public static function read(string $sessionId, string $key, mixed $default = null, ?string $context = null): mixed
    {
        return self::payloadFor($sessionId, $context)[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readAll(string $sessionId, ?string $context = null): array
    {
        return self::payloadFor($sessionId, $context);
    }

    /**
     * Rotate a session's token in place — same id, user_id, and payload, new digest.
     * Callers regenerate on privilege escalation (e.g. login) to defeat session
     * fixation. Returns the new plaintext token for re-issuing as the `mid` cookie.
     */
    public static function regenerate(string $sessionId, ?string $context = null): string
    {
        self::payloadFor($sessionId, $context); // throws if $sessionId doesn't exist

        $token = CryptographicToken::generate();

        DB::update('sessions', ['digest' => self::digest($token)], ['id' => $sessionId], $context);

        return $token;
    }

    /**
     * Promote a session from guest to authenticated (or the reverse, on logout) without
     * losing its id or payload — Middlewares\Authentication calls this alongside
     * regenerate() when a pre-login guest session already exists at login time, so a
     * shopping cart or other guest-collected payload survives authentication while the
     * token still rotates (fixation defense).
     */
    public static function assignUser(string $sessionId, ?string $userId, ?string $context = null): void
    {
        self::payloadFor($sessionId, $context); // throws if $sessionId doesn't exist

        DB::update('sessions', ['user_id' => $userId], ['id' => $sessionId], $context);
    }

    /**
     * Push a session's expiry back out to a fresh full lifetime — for a pre-login guest
     * session promoted at login time, which would otherwise keep whatever `expire_at`
     * it was given when it started as a guest (possibly seconds away from expiring).
     * Returns the new expiry timestamp.
     */
    public static function renew(string $sessionId, ?int $lifetimeSecondsOverride = null, ?string $context = null): string
    {
        self::payloadFor($sessionId, $context); // throws if $sessionId doesn't exist

        $expireAt = self::expiryTimestamp($lifetimeSecondsOverride);
        DB::update('sessions', ['expire_at' => $expireAt], ['id' => $sessionId], $context);

        return $expireAt;
    }

    /**
     * Mark a session revoked without deleting its row — an audit trail survives; the
     * session no longer resolves() as valid.
     */
    public static function revoke(string $sessionId, ?string $context = null): void
    {
        DB::update('sessions', ['revoked_at' => self::now()], ['id' => $sessionId], $context);
    }

    public static function destroy(string $sessionId, ?string $context = null): void
    {
        DB::delete('sessions', ['id' => $sessionId], $context);
    }

    /**
     * Hard-delete every expired or revoked row. A maintenance operation for a scheduled
     * task, not the request path.
     */
    public static function purgeExpired(?string $context = null): int
    {
        $expiredCount = DB::delete('sessions', ['expire_at' => ['<=', self::now()]], $context);

        // Rows already caught by the delete above are gone, so this can't double-count
        // a row that was both expired and revoked.
        DB::run('DELETE FROM sessions WHERE revoked_at IS NOT NULL', [], $context);

        return $expiredCount + DB::rowCount();
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadFor(string $sessionId, ?string $context): array
    {
        DB::run('SELECT payload FROM sessions WHERE id = ?', [$sessionId], $context);
        $row = DB::fetch();

        if ($row === []) {
            throw new InvalidArgumentException(sprintf('No session with id "%s".', $sessionId));
        }

        return json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
    }

    private static function digest(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private static function expiryTimestamp(?int $lifetimeSecondsOverride = null): string
    {
        $lifetimeSeconds = $lifetimeSecondsOverride ?? self::$lifetimeSeconds;

        return (new DateTimeImmutable())->modify('+' . $lifetimeSeconds . ' seconds')->format('Y-m-d H:i:s');
    }

    /**
     * Reset configuration. For test isolation.
     */
    public static function reset(): void
    {
        self::$lifetimeSeconds = self::DEFAULT_LIFETIME_SECONDS;
    }
}
