<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services;

use Gaia\Clarity\Services\Event;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    #[After]
    public function resetListeners(): void
    {
        Event::forget();
    }

    public function testListenerReceivesDispatchedPayload(): void
    {
        $received = null;
        Event::listen(Event::USER_REGISTERED, function ($payload) use (&$received) {
            $received = $payload;
        });

        Event::dispatch(Event::USER_REGISTERED, ['id' => 42]);

        self::assertSame(['id' => 42], $received);
    }

    public function testMultipleListenersFireInRegistrationOrder(): void
    {
        $calls = [];
        Event::listen('custom.event', function () use (&$calls) {
            $calls[] = 'first';
        });
        Event::listen('custom.event', function () use (&$calls) {
            $calls[] = 'second';
        });

        Event::dispatch('custom.event');

        self::assertSame(['first', 'second'], $calls);
    }

    public function testDispatchWithNoListenersDoesNotThrow(): void
    {
        Event::dispatch(Event::LOGIN_FAILED);

        self::assertFalse(Event::hasListeners(Event::LOGIN_FAILED));
    }

    public function testHasListenersReflectsRegistrations(): void
    {
        self::assertFalse(Event::hasListeners(Event::LOGIN_SUCCESS));

        Event::listen(Event::LOGIN_SUCCESS, function () {
        });

        self::assertTrue(Event::hasListeners(Event::LOGIN_SUCCESS));
    }

    public function testForgetRemovesListenersForOneName(): void
    {
        Event::listen(Event::FILE_UPLOADED, function () {
        });
        Event::listen(Event::MIGRATION_COMPLETED, function () {
        });

        Event::forget(Event::FILE_UPLOADED);

        self::assertFalse(Event::hasListeners(Event::FILE_UPLOADED));
        self::assertTrue(Event::hasListeners(Event::MIGRATION_COMPLETED));
    }

    public function testForgetWithNoArgumentClearsEveryListener(): void
    {
        Event::listen(Event::LOGIN_SUCCESS, function () {
        });
        Event::listen(Event::LOGIN_FAILED, function () {
        });

        Event::forget();

        self::assertFalse(Event::hasListeners(Event::LOGIN_SUCCESS));
        self::assertFalse(Event::hasListeners(Event::LOGIN_FAILED));
    }

    public function testEventNamesAreNotRestrictedToBuiltInConstants(): void
    {
        $received = null;
        Event::listen('app.custom_event', function ($payload) use (&$received) {
            $received = $payload;
        });

        Event::dispatch('app.custom_event', 'anything');

        self::assertSame('anything', $received);
    }
}
