<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\MakeMigration;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class MakeMigrationTest extends TestCase
{
    private string $originalCwd;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->projectRoot = sys_get_temp_dir() . '/clarity-make-migration-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0775, true);
        chdir($this->projectRoot);
    }

    #[After]
    public function restoreCwdAndCleanUp(): void
    {
        chdir($this->originalCwd);
        self::removeDirectory($this->projectRoot);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? self::removeDirectory($entry) : unlink($entry);
        }

        rmdir($directory);
    }

    public function testCreatesTimestampedMigrationFileWithUpDownTemplate(): void
    {
        $exitCode = (new MakeMigration())(Arguments::parse(['add index to users']));

        $files = glob($this->projectRoot . '/database/migrations/*.php') ?: [];

        self::assertSame(0, $exitCode);
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/\d{14}_add_index_to_users\.php$/', $files[0]);

        $contents = (string) file_get_contents($files[0]);
        self::assertStringContainsString('public function up(): void', $contents);
        self::assertStringContainsString('public function down(): void', $contents);
    }

    public function testFailsWithoutADescription(): void
    {
        self::assertSame(1, (new MakeMigration())(Arguments::parse([])));
    }
}
