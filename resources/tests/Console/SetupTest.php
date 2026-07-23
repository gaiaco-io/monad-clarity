<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Console;

use Gaia\Clarity\Console\Arguments;
use Gaia\Clarity\Console\Setup;
use Gaia\Clarity\Services\DB;
use Gaia\Clarity\Services\Schema;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class SetupTest extends TestCase
{
    #[Before]
    public function setUpInMemoryDatabase(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));
    }

    #[After]
    public function resetDB(): void
    {
        DB::reset();
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    public function testCreatesSessionsAndCachesTables(): void
    {
        $output = $this->capture(function () {
            $exitCode = (new Setup())(Arguments::parse([]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('Setup complete', $output);
        self::assertTrue(Schema::hasTable('sessions'));
        self::assertTrue(Schema::hasTable('caches'));
    }

    public function testSessionsAndCachesTablesAcceptRowsMatchingTheFrozenShape(): void
    {
        (new Setup())(Arguments::parse([]));

        DB::insert('sessions', [
            'id' => 'session-1',
            'user_id' => null,
            'digest' => 'digest-value',
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
            'payload' => json_encode(['foo' => 'bar']),
            'expire_at' => '2026-01-01 00:00:00',
            'revoked_at' => null,
        ]);

        DB::insert('caches', [
            'key_hash' => hash('sha256', 'my-cache-key', true),
            'cache_key' => 'my-cache-key',
            'cache_value' => serialize(['x' => 1]),
            'expires_at' => null,
        ], DB::ID_TYPE_INT);

        DB::run('SELECT * FROM sessions WHERE id = ?', ['session-1']);
        self::assertSame('digest-value', DB::fetch()['digest']);

        self::assertTrue(Schema::hasTable('caches'));
    }
}
