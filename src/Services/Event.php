<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

/**
 * Tiny synchronous event dispatcher, decoupling emitters (login, registration, uploads,
 * migrations) from listeners without pulling in a queue or message bus.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class Event
{
    public const LOGIN_SUCCESS = 'login.success';
    public const LOGIN_FAILED = 'login.failed';
    public const PAYMENT_COMPLETED = 'payment.completed'; // reserved — fires once Checkout ships
    public const USER_REGISTERED = 'user.registered';
    public const FILE_UPLOADED = 'file.uploaded';
    public const MIGRATION_COMPLETED = 'migration.completed';

    /** @var array<string, list<callable>> */
    private static array $listeners = [];

    /**
     * Register a listener for an event name. Names are not restricted to the built-in
     * constants above — application code may dispatch and listen for its own.
     */
    public static function listen(string $name, callable $listener): void
    {
        self::$listeners[$name][] = $listener;
    }

    /**
     * Synchronously invoke every listener registered for $name, in registration order.
     */
    public static function dispatch(string $name, mixed $payload = null): void
    {
        foreach (self::$listeners[$name] ?? [] as $listener) {
            $listener($payload);
        }
    }

    /**
     * Whether any listener is registered for $name.
     */
    public static function hasListeners(string $name): bool
    {
        return !empty(self::$listeners[$name]);
    }

    /**
     * Remove listeners for $name, or every listener for every name if $name is omitted.
     * Needed for test isolation and long-running workers (Swoole/RoadRunner); a plain
     * php-fpm/CLI request starts with empty static state on every request regardless.
     */
    public static function forget(?string $name = null): void
    {
        if ($name === null) {
            self::$listeners = [];
            return;
        }

        unset(self::$listeners[$name]);
    }
}
