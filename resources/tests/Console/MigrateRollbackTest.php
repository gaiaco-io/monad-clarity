<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Console;

use Gaia\Clarity\Console\Arguments;
use Gaia\Clarity\Console\Migrate;
use Gaia\Clarity\Console\MigrateRollback;
use Gaia\Clarity\Services\DB;
use Gaia\Clarity\Services\Schema;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class MigrateRollbackTest extends TestCase
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

    public function testRollsBackAppliedMigrationsInReverseOrder(): void
    {
        (new Migrate())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));

        $output = $this->capture(function () {
            $exitCode = (new MigrateRollback())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH, '--steps=2']));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('Rolled back: 20260101000100_add_price_to_widgets', $output);
        self::assertStringContainsString('Rolled back: 20260101000000_create_widgets_table', $output);
        self::assertFalse(Schema::hasTable('widgets'));
    }

    public function testDefaultsToOneStep(): void
    {
        (new Migrate())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));

        $output = $this->capture(function () {
            (new MigrateRollback())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));
        });

        self::assertStringContainsString('Rolled back: 20260101000100_add_price_to_widgets', $output);
        self::assertStringNotContainsString('20260101000000_create_widgets_table', $output);
        self::assertTrue(Schema::hasTable('widgets'));
    }

    public function testReportsNothingToRollBackWhenNothingApplied(): void
    {
        $output = $this->capture(function () {
            $exitCode = (new MigrateRollback())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('Nothing to roll back', $output);
    }
}
