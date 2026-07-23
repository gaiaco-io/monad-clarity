<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Console;

use Gaia\Clarity\Console\Arguments;
use Gaia\Clarity\Console\Migrate;
use Gaia\Clarity\Console\MigrateStatus;
use Gaia\Clarity\Services\DB;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class MigrateStatusTest extends TestCase
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

    public function testReportsPendingMigrationsButStillExitsZero(): void
    {
        $output = $this->capture(function () {
            $exitCode = (new MigrateStatus())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('20260101000000_create_widgets_table (pending)', $output);
    }

    public function testReportsAppliedMigrationsAndSuccessExitCode(): void
    {
        (new Migrate())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));

        $output = $this->capture(function () {
            $exitCode = (new MigrateStatus())(Arguments::parse(['--path=' . self::MIGRATIONS_PATH]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('20260101000000_create_widgets_table', $output);
        self::assertStringNotContainsString('(pending)', $output);
    }
}
