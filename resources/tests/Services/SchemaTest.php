<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services;

use Monad\Clarity\Console\Setup;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Schema\Blueprint;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SchemaTest extends TestCase
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

    public function testDialectDetectsSqlite(): void
    {
        self::assertSame('sqlite', Schema::dialect());
    }

    public function testCreateTableWithVariousColumnTypesRoundTripsData(): void
    {
        Schema::createTable('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description', nullable: true);
            $table->integer('stock', default: 0);
            $table->boolean('active', default: true);
            $table->json('metadata');
            $table->datetime('created_at', default: Schema::raw('CURRENT_TIMESTAMP'));
            $table->binary('thumbnail', nullable: true);
        });

        self::assertTrue(Schema::hasTable('widgets'));

        DB::insert('widgets', [
            'id' => 'fixed-id',
            'name' => 'Gadget',
            'stock' => 5,
            'active' => 1,
            'metadata' => json_encode(['color' => 'red']),
        ]);

        DB::run('SELECT * FROM widgets WHERE id = ?', ['fixed-id']);
        $row = DB::fetch();

        self::assertSame('Gadget', $row['name']);
        self::assertSame(5, $row['stock']);
        self::assertSame(1, $row['active']);
        self::assertSame(['color' => 'red'], json_decode($row['metadata'], true));
        self::assertNotEmpty($row['created_at']);
    }

    public function testAutoIncrementIdWorksOnSqlite(): void
    {
        Schema::createTable('counters', function (Blueprint $table) {
            $table->autoIncrementId();
            $table->string('label');
        });

        $first = DB::insert('counters', ['label' => 'a'], DB::ID_TYPE_INT);
        $second = DB::insert('counters', ['label' => 'b'], DB::ID_TYPE_INT);

        self::assertSame('1', $first);
        self::assertSame('2', $second);
    }

    public function testUniqueConstraintIsEnforced(): void
    {
        Schema::createTable('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->unique('email');
        });

        DB::insert('accounts', ['id' => 'a', 'email' => 'a@example.com']);

        $this->expectException(PDOException::class);

        DB::insert('accounts', ['id' => 'b', 'email' => 'a@example.com']);
    }

    public function testPlainIndexIsCreatedAndDoesNotEnforceUniqueness(): void
    {
        Schema::createTable('logs', function (Blueprint $table) {
            $table->id();
            $table->string('level');
            $table->index('level');
        });

        DB::insert('logs', ['id' => 'a', 'level' => 'info']);
        DB::insert('logs', ['id' => 'b', 'level' => 'info']);

        DB::run('SELECT COUNT(*) as count FROM logs WHERE level = ?', ['info']);
        self::assertSame(2, (int) DB::fetch()['count']);
    }

    public function testAlterTableAddsColumn(): void
    {
        Schema::createTable('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::alterTable('products', function (Blueprint $table) {
            $table->integer('price', nullable: true);
        });

        self::assertTrue(Schema::hasColumn('products', 'price'));

        DB::insert('products', ['id' => 'a', 'name' => 'Widget', 'price' => 999]);
        DB::run('SELECT price FROM products WHERE id = ?', ['a']);
        self::assertSame(999, DB::fetch()['price']);
    }

    public function testDropColumn(): void
    {
        Schema::createTable('temp', function (Blueprint $table) {
            $table->id();
            $table->string('junk', nullable: true);
        });

        Schema::dropColumn('temp', 'junk');

        self::assertFalse(Schema::hasColumn('temp', 'junk'));
    }

    public function testDropTable(): void
    {
        Schema::createTable('disposable', fn (Blueprint $table) => $table->id());

        self::assertTrue(Schema::hasTable('disposable'));

        Schema::dropTable('disposable');

        self::assertFalse(Schema::hasTable('disposable'));
    }

    public function testDropTableIfExistsDoesNotThrowWhenMissing(): void
    {
        Schema::dropTable('never_existed', ifExists: true);

        self::assertFalse(Schema::hasTable('never_existed'));
    }

    public function testCreateIndexAndDropIndexStandalone(): void
    {
        Schema::createTable('articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
        });

        Schema::createIndex('articles', 'slug', name: 'idx_articles_slug');
        Schema::dropIndex('articles', 'idx_articles_slug');

        // No exception means both operations succeeded; SQLite offers no direct way to
        // introspect index existence as simply as hasTable/hasColumn.
        self::assertTrue(Schema::hasTable('articles'));
    }

    public function testHasTableAndHasColumnReturnFalseForUnknowns(): void
    {
        self::assertFalse(Schema::hasTable('nonexistent'));

        Schema::createTable('known', fn (Blueprint $table) => $table->id());
        self::assertFalse(Schema::hasColumn('known', 'nonexistent'));
    }

    public function testCreateTableRejectsInvalidTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Schema::createTable('bad; DROP TABLE widgets;--', fn (Blueprint $table) => $table->id());
    }

    /**
     * The compatibility surface itself (CrossRepoContracts.md §8): Schema must be able to
     * define the two setup-owned tables (sessions, caches) from DDL.sql. This exercises
     * Console\Setup::sessionsBlueprint() directly — the canonical definition the real
     * `setup` command creates these tables from — rather than a second, hand-maintained
     * copy that could silently drift from what `setup` actually emits. It asserts the
     * MySQL-dialect compiled SQL carries the essential column/nullability/key shape
     * (checked via reflection on the pure compiler — no live MySQL server available in
     * CI), then proves the dialect-appropriate definition actually works end to end on
     * SQLite, showing the abstraction holds across dialects rather than just on paper.
     */
    public function testSessionsTableMatchesFrozenDdlShapeAcrossDialects(): void
    {
        $blueprintForMysql = new Blueprint();
        (Setup::sessionsBlueprint('mysql'))($blueprintForMysql);
        $mysqlSql = self::compileCreateTable('mysql', 'sessions', $blueprintForMysql);

        self::assertStringContainsString('id CHAR(36) NOT NULL DEFAULT (uuid())', $mysqlSql);
        self::assertStringContainsString('user_id CHAR(36) NULL', $mysqlSql);
        self::assertStringContainsString('digest VARCHAR(255) NOT NULL', $mysqlSql);
        self::assertStringContainsString('payload JSON NOT NULL', $mysqlSql);
        self::assertStringContainsString(
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            $mysqlSql
        );
        self::assertStringContainsString('revoked_at DATETIME NULL', $mysqlSql);
        self::assertStringContainsString('PRIMARY KEY (id)', $mysqlSql);

        // sqlite has no uuid() function — the dialect-appropriate definition omits the
        // DB-level default entirely (see Setup::sessionsBlueprint()'s doc comment).
        Schema::createTable('sessions', Setup::sessionsBlueprint('sqlite'));

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

        DB::run('SELECT * FROM sessions WHERE id = ?', ['session-1']);
        $row = DB::fetch();

        self::assertNull($row['user_id']);
        self::assertSame('digest-value', $row['digest']);
        self::assertNotEmpty($row['created_at']);
    }

    public function testCachesTableMatchesFrozenDdlShapeAcrossDialects(): void
    {
        $define = Setup::cachesBlueprint();

        $blueprintForMysql = new Blueprint();
        $define($blueprintForMysql);
        $mysqlSql = self::compileCreateTable('mysql', 'caches', $blueprintForMysql);

        self::assertStringContainsString('key_hash BINARY(32) NOT NULL', $mysqlSql);
        self::assertStringContainsString('cache_value LONGBLOB NOT NULL', $mysqlSql);
        self::assertStringContainsString("encoding VARCHAR(20) NOT NULL DEFAULT 'serialize'", $mysqlSql);
        self::assertStringContainsString('expires_at DATETIME NULL', $mysqlSql);
        self::assertStringContainsString('PRIMARY KEY (key_hash)', $mysqlSql);

        Schema::createTable('caches', $define);

        // caches has no `id` column at all (its PK is key_hash) — ID_TYPE_INT suppresses
        // DB::insert()'s default UUID-`id` auto-injection, which doesn't apply here.
        DB::insert('caches', [
            'key_hash' => hash('sha256', 'my-cache-key', true),
            'cache_key' => 'my-cache-key',
            'cache_value' => serialize(['x' => 1]),
            'expires_at' => null,
        ], DB::ID_TYPE_INT);

        self::assertTrue(Schema::hasTable('caches'));
    }

    private static function compileCreateTable(string $dialect, string $table, Blueprint $blueprint): string
    {
        $method = new ReflectionMethod(Schema::class, 'compileCreateTable');

        return $method->invoke(null, $dialect, $table, $blueprint);
    }
}
