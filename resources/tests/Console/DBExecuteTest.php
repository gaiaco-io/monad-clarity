<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Console;

use Gaia\Clarity\Console\Arguments;
use Gaia\Clarity\Console\DBExecute;
use Gaia\Clarity\Services\DB;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class DBExecuteTest extends TestCase
{
    private const SQL_FILE = __DIR__ . '/../fixtures/sql/create_notes_table.sql';

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

    public function testExecutesSqlFileAndReportsSuccess(): void
    {
        $output = $this->capture(function () {
            $exitCode = (new DBExecute())(Arguments::parse([self::SQL_FILE]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('Executed:', $output);

        DB::run('SELECT * FROM notes WHERE id = ?', ['n1']);
        self::assertSame('hello', DB::fetch()['body']);
    }

    public function testFailsWithoutAFileArgument(): void
    {
        self::assertSame(1, (new DBExecute())(Arguments::parse([])));
    }
}
