<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services;

use DateTimeImmutable;
use Monad\Clarity\Console\Setup;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Session;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    #[Before]
    public function setUpInMemoryDatabase(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));
        Schema::createTable('sessions', Setup::sessionsBlueprint('sqlite'));
    }

    #[After]
    public function resetState(): void
    {
        DB::reset();
        Session::reset();
    }

    public function testStartCreatesASessionRowWithAllFrozenColumnsPopulated(): void
    {
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0', ['role' => 'admin']);

        self::assertNotEmpty($session['id']);
        self::assertNotEmpty($session['token']);
        self::assertNotEmpty($session['expireAt']);

        $row = Session::resolve($session['token']);

        self::assertNotNull($row);
        self::assertSame('user-1', $row['user_id']);
        self::assertSame('127.0.0.1', $row['ip_address']);
        self::assertSame('PHPUnit/1.0', $row['user_agent']);
        self::assertSame(['role' => 'admin'], $row['payload']);
    }

    public function testStartSupportsAGuestSessionWithNullUserId(): void
    {
        $session = Session::start(null, '127.0.0.1', 'PHPUnit/1.0');

        $row = Session::resolve($session['token']);

        self::assertNotNull($row);
        self::assertNull($row['user_id']);
    }

    public function testResolveReturnsNullForATokenThatNeverExisted(): void
    {
        self::assertNull(Session::resolve('never-issued-token'));
    }

    public function testResolveReturnsNullForAnExpiredSession(): void
    {
        Session::configure(lifetimeSeconds: -1);
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');

        self::assertNull(Session::resolve($session['token']));
    }

    public function testResolveReturnsNullForARevokedSession(): void
    {
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');

        Session::revoke($session['id']);

        self::assertNull(Session::resolve($session['token']));
    }

    public function testWriteAndReadRoundTripAPayloadValue(): void
    {
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');

        Session::write($session['id'], 'cart_total', 42);

        self::assertSame(42, Session::read($session['id'], 'cart_total'));
        self::assertSame(['cart_total' => 42], Session::readAll($session['id']));
    }

    public function testReadReturnsDefaultForAMissingKey(): void
    {
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');

        self::assertNull(Session::read($session['id'], 'missing'));
        self::assertSame('fallback', Session::read($session['id'], 'missing', 'fallback'));
    }

    public function testRegenerateRotatesTheTokenAndInvalidatesTheOldOne(): void
    {
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        Session::write($session['id'], 'foo', 'bar');

        $newToken = Session::regenerate($session['id']);

        self::assertNotSame($session['token'], $newToken);
        self::assertNull(Session::resolve($session['token']));

        $row = Session::resolve($newToken);
        self::assertNotNull($row);
        self::assertSame($session['id'], $row['id']);
        self::assertSame('user-1', $row['user_id']);
        self::assertSame(['foo' => 'bar'], $row['payload']);
    }

    public function testAssignUserPromotesAGuestSessionWithoutLosingItsPayload(): void
    {
        $session = Session::start(null, '127.0.0.1', 'PHPUnit/1.0', ['cart' => ['sku-1']]);

        Session::assignUser($session['id'], 'user-1');

        $row = Session::resolve($session['token']);
        self::assertSame('user-1', $row['user_id']);
        self::assertSame(['cart' => ['sku-1']], $row['payload']);
    }

    public function testAssignUserCanClearUserIdBackToGuestOnLogout(): void
    {
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');

        Session::assignUser($session['id'], null);

        self::assertNull(Session::resolve($session['token'])['user_id']);
    }

    public function testRenewPushesExpiryOutToAFreshFullLifetime(): void
    {
        Session::configure(lifetimeSeconds: -1); // start with an already-expired session
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        self::assertNull(Session::resolve($session['token']));

        Session::configure(lifetimeSeconds: 3600);
        Session::renew($session['id']);

        self::assertNotNull(Session::resolve($session['token']));
    }

    public function testDestroyRemovesTheRowEntirely(): void
    {
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');

        Session::destroy($session['id']);

        self::assertNull(Session::resolve($session['token']));
        $this->expectException(InvalidArgumentException::class);
        Session::readAll($session['id']);
    }

    public function testWriteOnAnUnknownSessionIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Session::write('does-not-exist', 'key', 'value');
    }

    public function testPurgeExpiredDeletesOnlyExpiredAndRevokedRows(): void
    {
        Session::configure(lifetimeSeconds: -1);
        $expired = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        Session::reset();

        $revoked = Session::start('user-2', '127.0.0.1', 'PHPUnit/1.0');
        Session::revoke($revoked['id']);

        $active = Session::start('user-3', '127.0.0.1', 'PHPUnit/1.0');

        $purged = Session::purgeExpired();

        self::assertSame(2, $purged);
        self::assertNotNull(Session::resolve($active['token']));

        DB::run('SELECT COUNT(*) AS count FROM sessions WHERE id IN (?, ?)', [$expired['id'], $revoked['id']]);
        self::assertSame(0, (int) DB::fetch()['count']);
    }

    public function testExpireAtReflectsConfiguredLifetime(): void
    {
        Session::configure(lifetimeSeconds: 3600);
        $before = new DateTimeImmutable('+3599 seconds');
        $session = Session::start('user-1', '127.0.0.1', 'PHPUnit/1.0');
        $after = new DateTimeImmutable('+3601 seconds');

        $expireAt = new DateTimeImmutable($session['expireAt']);

        self::assertGreaterThan($before, $expireAt);
        self::assertLessThan($after, $expireAt);
    }
}
