<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services;

use Gaia\Clarity\Services\DB;
use Gaia\Clarity\Services\Event;
use Gaia\Clarity\Services\Migration;
use Gaia\Clarity\Services\Schema;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationTest extends TestCase
{
    private const MIGRATIONS_PATH = __DIR__ . '/../fixtures/migrations';
    private const INVALID_MIGRATIONS_PATH = __DIR__ . '/../fixtures/migrations-invalid';
    private const SEEDS_PATH = __DIR__ . '/../fixtures/seeds';
    private const SQL_PATH = __DIR__ . '/../fixtures/sql';

    #[Before]
    public function setUpInMemoryDatabase(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));
    }

    #[After]
    public function resetDB(): void
    {
        DB::reset();
        Event::forget();
    }

    public function testMigrateAppliesAllPendingMigrationsInOrder(): void
    {
        $applied = Migration::migrate(self::MIGRATIONS_PATH);

        self::assertSame([
            '20260101000000_create_widgets_table',
            '20260101000100_add_price_to_widgets',
        ], $applied);

        self::assertTrue(Schema::hasTable('widgets'));
        self::assertTrue(Schema::hasColumn('widgets', 'price'));
    }

    public function testMigrateIsIdempotentOnSecondCall(): void
    {
        Migration::migrate(self::MIGRATIONS_PATH);

        self::assertSame([], Migration::migrate(self::MIGRATIONS_PATH));
    }

    public function testStatusReflectsAppliedMigrations(): void
    {
        self::assertSame([
            '20260101000000_create_widgets_table' => false,
            '20260101000100_add_price_to_widgets' => false,
        ], Migration::status(self::MIGRATIONS_PATH));

        Migration::migrate(self::MIGRATIONS_PATH);

        self::assertSame([
            '20260101000000_create_widgets_table' => true,
            '20260101000100_add_price_to_widgets' => true,
        ], Migration::status(self::MIGRATIONS_PATH));
    }

    public function testRollbackRunsDownInReverseOrderAndUntracksThem(): void
    {
        Migration::migrate(self::MIGRATIONS_PATH);

        $rolledBack = Migration::rollback(self::MIGRATIONS_PATH, steps: 2);

        self::assertSame([
            '20260101000100_add_price_to_widgets',
            '20260101000000_create_widgets_table',
        ], $rolledBack);
        self::assertFalse(Schema::hasTable('widgets'));
        self::assertSame(
            ['20260101000000_create_widgets_table' => false, '20260101000100_add_price_to_widgets' => false],
            Migration::status(self::MIGRATIONS_PATH)
        );
    }

    public function testRollbackDefaultsToOneStep(): void
    {
        Migration::migrate(self::MIGRATIONS_PATH);

        $rolledBack = Migration::rollback(self::MIGRATIONS_PATH);

        self::assertSame(['20260101000100_add_price_to_widgets'], $rolledBack);
        self::assertTrue(Schema::hasTable('widgets'));
        self::assertFalse(Schema::hasColumn('widgets', 'price'));
    }

    public function testMigrateDispatchesMigrationCompletedEventForEachNewlyAppliedMigration(): void
    {
        $dispatched = [];
        Event::listen(Event::MIGRATION_COMPLETED, function (array $payload) use (&$dispatched) {
            $dispatched[] = $payload['migration'];
        });

        Migration::migrate(self::MIGRATIONS_PATH);

        self::assertSame([
            '20260101000000_create_widgets_table',
            '20260101000100_add_price_to_widgets',
        ], $dispatched);
    }

    public function testLoadingAnInvalidMigrationFileThrows(): void
    {
        $this->expectException(RuntimeException::class);

        Migration::migrate(self::INVALID_MIGRATIONS_PATH);
    }

    public function testRunSeedInsertsDataViaCallable(): void
    {
        Migration::migrate(self::MIGRATIONS_PATH);
        Migration::runSeed(self::SEEDS_PATH . '/widgets_seed.php');

        DB::run('SELECT * FROM widgets WHERE id = ?', ['seed-1']);
        self::assertSame('Seeded Widget', DB::fetch()['name']);
    }

    public function testRunSeedThrowsForMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Migration::runSeed(self::SEEDS_PATH . '/does_not_exist.php');
    }

    public function testRunSqlScriptExecutesEveryStatement(): void
    {
        Migration::runSqlScript(self::SQL_PATH . '/create_notes_table.sql');

        DB::run('SELECT COUNT(*) as count FROM notes');
        self::assertSame(2, (int) DB::fetch()['count']);
    }

    public function testRunSqlScriptThrowsForMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Migration::runSqlScript(self::SQL_PATH . '/does_not_exist.sql');
    }

    public function testExportDdlReproducesSchemaAndReimportIsIdempotent(): void
    {
        Migration::migrate(self::MIGRATIONS_PATH);

        $ddl = Migration::exportDdl();

        self::assertStringContainsString('CREATE TABLE', $ddl);
        self::assertStringContainsString('widgets', $ddl);

        // Idempotency proof: re-running the exported DDL against the same (already
        // migrated) database must not throw.
        DB::connect()->exec($ddl);

        self::assertTrue(Schema::hasTable('widgets'));
    }
}
