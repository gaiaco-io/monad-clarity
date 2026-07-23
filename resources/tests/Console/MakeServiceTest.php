<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Console;

use Gaia\Clarity\Console\Arguments;
use Gaia\Clarity\Console\MakeService;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class MakeServiceTest extends TestCase
{
    private string $originalCwd;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->projectRoot = sys_get_temp_dir() . '/clarity-make-service-' . bin2hex(random_bytes(8));
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

    public function testCreatesServiceFileWithExpectedContent(): void
    {
        $exitCode = (new MakeService())(Arguments::parse(['Billing']));

        $path = $this->projectRoot . '/app/services/Billing.php';

        self::assertSame(0, $exitCode);
        self::assertFileExists($path);
        self::assertStringContainsString('namespace App\Services;', (string) file_get_contents($path));
        self::assertStringContainsString('final class Billing', (string) file_get_contents($path));
    }

    public function testFailsWithoutAName(): void
    {
        self::assertSame(1, (new MakeService())(Arguments::parse([])));
    }

    public function testRefusesToOverwriteAnExistingService(): void
    {
        (new MakeService())(Arguments::parse(['Billing']));

        self::assertSame(1, (new MakeService())(Arguments::parse(['Billing'])));
    }
}
