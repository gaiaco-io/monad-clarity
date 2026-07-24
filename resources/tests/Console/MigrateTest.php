<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\Migrate;
use Monad\Clarity\Services\DB;
use Monad\Clarity\Services\Schema;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class MigrateTest extends TestCase
{
    private const MIGRATIONS_PATH = __DIR__ . '/../fixtures/migrations';

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

    public function testMigratesPendingFilesAndReportsThem(): void
    {
        $output = $this->capture(function () {
            $exitCode = (new Migrate())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('Migrated: 20260101000000_create_widgets_table', $output);
        self::assertTrue(Schema::hasTable('widgets'));
    }

    public function testReportsNothingToMigrateOnSecondRun(): void
    {
        (new Migrate())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));

        $output = $this->capture(function () {
            $exitCode = (new Migrate())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('Nothing to migrate', $output);
    }
}
