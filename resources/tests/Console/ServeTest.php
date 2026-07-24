<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\Serve;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * Only commandLine() is exercised here, never __invoke() — __invoke() calls passthru()
 * against PHP's built-in server, which blocks until the process is killed. A test that
 * invoked it would hang the suite rather than fail it.
 */
final class ServeTest extends TestCase
{
    private string $originalCwd;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->projectRoot = sys_get_temp_dir() . '/clarity-serve-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/public', 0775, true);
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

    public function testDefaultsToPort8000AndPublicDirectory(): void
    {
        $commandLine = (new Serve())->commandLine(Arguments::parse([]));

        self::assertStringContainsString("'127.0.0.1:8000'", $commandLine);
        self::assertStringContainsString(escapeshellarg($this->projectRoot . '/public'), $commandLine);
    }

    public function testHonoursPortOption(): void
    {
        $commandLine = (new Serve())->commandLine(Arguments::parse(['--port=9001']));

        self::assertStringContainsString("'127.0.0.1:9001'", $commandLine);
    }

    public function testIncludesRouterWhenPresent(): void
    {
        file_put_contents($this->projectRoot . '/public/router.php', '<?php return false;');

        $commandLine = (new Serve())->commandLine(Arguments::parse([]));

        self::assertStringContainsString(escapeshellarg($this->projectRoot . '/public/router.php'), $commandLine);
    }

    public function testOmitsRouterWhenAbsent(): void
    {
        $commandLine = (new Serve())->commandLine(Arguments::parse([]));

        self::assertStringNotContainsString('router.php', $commandLine);
    }
}
