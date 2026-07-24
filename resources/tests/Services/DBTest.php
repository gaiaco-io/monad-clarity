<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services;

use Monad\Clarity\Services\DB;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class DBTest extends TestCase
{
    #[Before]
    public function setUpInMemoryDatabase(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id TEXT PRIMARY KEY, name TEXT NOT NULL, quantity INTEGER NOT NULL DEFAULT 0)');

        DB::useConnection($pdo);
    }

    #[After]
    public function resetDB(): void
    {
        DB::reset();
    }

    public function testConnectReturnsSameInstanceForSameContext(): void
    {
        self::assertSame(DB::connect(), DB::connect());
    }

    public function testConnectThrowsForUnconfiguredContext(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::connect('unconfigured');
    }

    public function testConfigureBuildsConnectionOnFirstUse(): void
    {
        DB::configure('sqlite-context', ['dsn' => 'sqlite::memory:']);

        $pdo = DB::connect('sqlite-context');

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame($pdo, DB::connect('sqlite-context'));
    }

    public function testInsertGeneratesUuidByDefaultAndPersistsRow(): void
    {
        $id = DB::insert('widgets', ['name' => 'Gadget', 'quantity' => 3]);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);

        DB::run('SELECT * FROM widgets WHERE id = ?', [$id]);
        self::assertSame(['id' => $id, 'name' => 'Gadget', 'quantity' => 3], DB::fetch());
    }

    public function testInsertWithIntegerIdUsesDriverLastInsertId(): void
    {
        DB::connect()->exec('CREATE TABLE counters (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

        $id = DB::insert('counters', ['label' => 'first'], DB::ID_TYPE_INT);

        self::assertSame('1', $id);
        self::assertSame('1', DB::lastInsertId());
    }

    public function testUpdateReturnsAffectedRowCount(): void
    {
        DB::insert('widgets', ['id' => 'a', 'name' => 'A', 'quantity' => 1]);
        DB::insert('widgets', ['id' => 'b', 'name' => 'B', 'quantity' => 1]);

        $affected = DB::update('widgets', ['quantity' => 5], ['quantity' => 1]);

        self::assertSame(2, $affected);
    }

    public function testDeleteReturnsAffectedRowCount(): void
    {
        DB::insert('widgets', ['id' => 'a', 'name' => 'A', 'quantity' => 1]);

        self::assertSame(1, DB::delete('widgets', ['id' => 'a']));
        self::assertSame(0, DB::delete('widgets', ['id' => 'a']));
    }

    public function testWhereClauseSupportsInOperator(): void
    {
        DB::insert('widgets', ['id' => 'a', 'name' => 'A', 'quantity' => 1]);
        DB::insert('widgets', ['id' => 'b', 'name' => 'B', 'quantity' => 2]);
        DB::insert('widgets', ['id' => 'c', 'name' => 'C', 'quantity' => 3]);

        self::assertSame(2, DB::delete('widgets', ['id' => ['IN', ['a', 'b']]]));
    }

    public function testWhereClauseSupportsBetweenOperator(): void
    {
        DB::insert('widgets', ['id' => 'a', 'name' => 'A', 'quantity' => 1]);
        DB::insert('widgets', ['id' => 'b', 'name' => 'B', 'quantity' => 5]);
        DB::insert('widgets', ['id' => 'c', 'name' => 'C', 'quantity' => 10]);

        self::assertSame(2, DB::delete('widgets', ['quantity' => ['BETWEEN', [1, 5]]]));
    }

    public function testWhereClauseSupportsLikeOperator(): void
    {
        DB::insert('widgets', ['id' => 'a', 'name' => 'Gadget', 'quantity' => 1]);
        DB::insert('widgets', ['id' => 'b', 'name' => 'Widget', 'quantity' => 1]);

        $affected = DB::delete('widgets', ['name' => ['LIKE', '%adget%']]);

        self::assertSame(1, $affected);
    }

    public function testWhereClauseSupportsComparisonOperator(): void
    {
        DB::insert('widgets', ['id' => 'a', 'name' => 'A', 'quantity' => 1]);
        DB::insert('widgets', ['id' => 'b', 'name' => 'B', 'quantity' => 9]);

        $affected = DB::delete('widgets', ['quantity' => ['>', 5]]);

        self::assertSame(1, $affected);
    }

    public function testWhereClauseSupportsIsNullOperator(): void
    {
        DB::connect()->exec('CREATE TABLE nullable_widgets (id TEXT PRIMARY KEY, note TEXT)');
        DB::insert('nullable_widgets', ['id' => 'a', 'note' => null]);
        DB::insert('nullable_widgets', ['id' => 'b', 'note' => 'has note']);

        $affected = DB::delete('nullable_widgets', ['note' => ['IS', 'NULL']]);

        self::assertSame(1, $affected);
    }

    public function testWhereClauseRejectsUnsupportedOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::delete('widgets', ['id' => ['~=', 'x']]);
    }

    public function testInsertRejectsInvalidTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::insert('widgets; DROP TABLE widgets;--', ['name' => 'x']);
    }

    public function testInsertRejectsInvalidColumnName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::insert('widgets', ['name; DROP TABLE widgets;--' => 'x']);
    }

    public function testPdoExceptionPropagatesUncaught(): void
    {
        $this->expectException(PDOException::class);

        DB::run('SELECT * FROM a_table_that_does_not_exist');
    }

    public function testTransactionCommit(): void
    {
        DB::beginTransaction();
        DB::insert('widgets', ['id' => 'a', 'name' => 'A', 'quantity' => 1]);
        DB::commit();

        DB::run('SELECT COUNT(*) as count FROM widgets');
        self::assertSame(1, (int) DB::fetch()['count']);
    }

    public function testTransactionRollback(): void
    {
        DB::beginTransaction();
        DB::insert('widgets', ['id' => 'a', 'name' => 'A', 'quantity' => 1]);
        DB::rollBack();

        DB::run('SELECT COUNT(*) as count FROM widgets');
        self::assertSame(0, (int) DB::fetch()['count']);
    }

    public function testFetchReturnsEmptyArrayWhenNoStatementRun(): void
    {
        DB::reset();
        DB::useConnection(new PDO('sqlite::memory:'));

        self::assertSame([], DB::fetch());
        self::assertSame([], DB::fetchAll());
    }
}
