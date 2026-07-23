<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Console;

use Gaia\Clarity\Console\Arguments;
use Gaia\Clarity\Console\MakeModel;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class MakeModelTest extends TestCase
{
    private string $originalCwd;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->projectRoot = sys_get_temp_dir() . '/clarity-make-model-' . bin2hex(random_bytes(8));
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

    public function testCreatesModelFileWithExpectedContent(): void
    {
        $exitCode = (new MakeModel())(Arguments::parse(['User']));

        $path = $this->projectRoot . '/app/Models/User.php';

        self::assertSame(0, $exitCode);
        self::assertFileExists($path);
        self::assertStringContainsString('namespace App\Models;', (string) file_get_contents($path));
        self::assertStringContainsString('final class User', (string) file_get_contents($path));
    }

    public function testFailsWithoutAName(): void
    {
        self::assertSame(1, (new MakeModel())(Arguments::parse([])));
    }

    public function testRefusesToOverwriteAnExistingModel(): void
    {
        (new MakeModel())(Arguments::parse(['User']));

        self::assertSame(1, (new MakeModel())(Arguments::parse(['User'])));
    }
}
