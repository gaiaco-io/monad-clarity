<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\LogsClear;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class LogsClearTest extends TestCase
{
    private string $logsDirectory;

    protected function setUp(): void
    {
        $this->logsDirectory = sys_get_temp_dir() . '/clarity-logs-clear-' . bin2hex(random_bytes(8));
        mkdir($this->logsDirectory . '/error', 0775, true);
        mkdir($this->logsDirectory . '/event', 0775, true);

        file_put_contents($this->logsDirectory . '/error/app.log', 'boom');
        file_put_contents($this->logsDirectory . '/event/timeline.log', 'login');
    }

    #[After]
    public function cleanUp(): void
    {
        self::removeDirectory($this->logsDirectory);
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

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    public function testTruncatesNestedLogFilesWithoutDeletingThem(): void
    {
        $output = $this->capture(function () {
            $exitCode = (new LogsClear())(Arguments::parse(['--path=' . $this->logsDirectory]));
            self::assertSame(0, $exitCode);
        });

        self::assertFileExists($this->logsDirectory . '/error/app.log');
        self::assertSame('', file_get_contents($this->logsDirectory . '/error/app.log'));
        self::assertSame('', file_get_contents($this->logsDirectory . '/event/timeline.log'));
        self::assertStringContainsString('Logs cleared', $output);
    }
}
