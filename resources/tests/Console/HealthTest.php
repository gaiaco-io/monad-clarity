<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Console;

use Gaia\Clarity\Console\Arguments;
use Gaia\Clarity\Console\Health;
use Gaia\Clarity\Services\DB;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class HealthTest extends TestCase
{
    private string $originalCwd;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->projectRoot = sys_get_temp_dir() . '/clarity-health-' . bin2hex(random_bytes(8));

        foreach (['storage/cache', 'storage/logs', 'storage/userfiles', 'database/migrations'] as $directory) {
            mkdir($this->projectRoot . '/' . $directory, 0775, true);
        }

        file_put_contents($this->projectRoot . '/.env', 'APP_ENV=testing');

        chdir($this->projectRoot);
        DB::useConnection(new PDO('sqlite::memory:'));
    }

    #[After]
    public function restoreCwdAndCleanUp(): void
    {
        chdir($this->originalCwd);
        DB::reset();
        self::removeDirectory($this->projectRoot);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // glob('/*') skips dotfiles (e.g. the fixture .env) — scandir() doesn't.
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            $entry = $directory . '/' . $name;
            is_dir($entry) ? self::removeDirectory($entry) : unlink($entry);
        }

        rmdir($directory);
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    public function testAllChecksPassOnAWellFormedProject(): void
    {
        $output = $this->capture(function () {
            $exitCode = (new Health())(Arguments::parse([]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('Configuration: .env present', $output);
        self::assertStringContainsString('Database connectivity: connected', $output);
        self::assertStringContainsString('Writable storage: all storage paths writable', $output);
        self::assertStringContainsString('Migration status: up to date', $output);
        self::assertStringContainsString('PHP extensions: all present', $output);
    }

    public function testFailsWhenEnvFileIsMissing(): void
    {
        unlink($this->projectRoot . '/.env');

        $output = $this->capture(function () {
            $exitCode = (new Health())(Arguments::parse([]));
            self::assertSame(1, $exitCode);
        });

        self::assertStringContainsString('.env not found', $output);
    }

    public function testFailsWhenAStoragePathIsNotWritable(): void
    {
        chmod($this->projectRoot . '/storage/cache', 0400);

        $output = $this->capture(function () {
            $exitCode = (new Health())(Arguments::parse([]));
            self::assertSame(1, $exitCode);
        });

        self::assertStringContainsString('not writable: storage/cache', $output);

        chmod($this->projectRoot . '/storage/cache', 0775);
    }

    public function testReportsNoMigrationsDirectoryWhenNoneExists(): void
    {
        rmdir($this->projectRoot . '/database/migrations');

        $output = $this->capture(function () {
            $exitCode = (new Health())(Arguments::parse([]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('Migration status: no migrations directory', $output);
    }

    public function testReportsPendingMigrations(): void
    {
        copy(
            __DIR__ . '/../fixtures/migrations/20260101000000_create_widgets_table.php',
            $this->projectRoot . '/database/migrations/20260101000000_create_widgets_table.php'
        );

        $output = $this->capture(function () {
            $exitCode = (new Health())(Arguments::parse([]));
            self::assertSame(1, $exitCode);
        });

        self::assertStringContainsString('pending: 20260101000000_create_widgets_table', $output);
    }
}
