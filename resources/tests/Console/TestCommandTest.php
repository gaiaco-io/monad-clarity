<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\Test as TestCommand;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * Only commandLine() is exercised here, never __invoke() — __invoke() calls passthru()
 * against vendor/bin/phpunit, and this suite IS that phpunit run: invoking it for real
 * would recursively re-run the entire suite from inside itself.
 */
final class TestCommandTest extends TestCase
{
    private string $originalCwd;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->projectRoot = sys_get_temp_dir() . '/clarity-test-cmd-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/vendor/bin', 0775, true);
        chdir($this->projectRoot);
        // getcwd() may resolve through a symlink (e.g. /var -> /private/var on macOS);
        // re-derive from getcwd() so string comparisons against commandLine()'s
        // getcwd()-based paths match exactly.
        $this->projectRoot = (string) getcwd();
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

    public function testCommandLineTargetsVendorBinPhpunit(): void
    {
        $commandLine = (new TestCommand())->commandLine(Arguments::parse([]));

        self::assertStringContainsString(escapeshellarg($this->projectRoot . '/vendor/bin/phpunit'), $commandLine);
    }

    public function testCommandLinePassesThroughArguments(): void
    {
        $commandLine = (new TestCommand())->commandLine(Arguments::parse(['--filter=Foo']));

        self::assertStringContainsString(escapeshellarg('--filter=Foo'), $commandLine);
    }

    public function testInvokeFailsCleanlyWhenPhpunitBinaryIsMissing(): void
    {
        rmdir($this->projectRoot . '/vendor/bin');
        rmdir($this->projectRoot . '/vendor');

        $exitCode = null;
        ob_start();
        $exitCode = (new TestCommand())(Arguments::parse([]));
        ob_get_clean();

        self::assertSame(1, $exitCode);
    }
}
