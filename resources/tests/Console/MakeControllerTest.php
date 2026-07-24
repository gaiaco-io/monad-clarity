<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\MakeController;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class MakeControllerTest extends TestCase
{
    private string $originalCwd;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->projectRoot = sys_get_temp_dir() . '/clarity-make-controller-' . bin2hex(random_bytes(8));
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

    public function testCreatesControllerFileWithExpectedContent(): void
    {
        $exitCode = (new MakeController())(Arguments::parse(['UserController']));

        $path = $this->projectRoot . '/app/Controllers/UserController.php';

        self::assertSame(0, $exitCode);
        self::assertFileExists($path);
        self::assertStringContainsString('namespace App\Controllers;', (string) file_get_contents($path));
        self::assertStringContainsString('final class UserController', (string) file_get_contents($path));
    }

    public function testFailsWithoutAName(): void
    {
        $exitCode = (new MakeController())(Arguments::parse([]));

        self::assertSame(1, $exitCode);
    }

    public function testRefusesToOverwriteAnExistingController(): void
    {
        (new MakeController())(Arguments::parse(['UserController']));
        $exitCode = (new MakeController())(Arguments::parse(['UserController']));

        self::assertSame(1, $exitCode);
    }
}
