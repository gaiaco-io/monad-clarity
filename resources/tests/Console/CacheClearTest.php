<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\CacheClear;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class CacheClearTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/clarity-cache-clear-' . bin2hex(random_bytes(8));
        mkdir($this->cacheDirectory, 0775, true);
        file_put_contents($this->cacheDirectory . '/a.cache', 'one');
        file_put_contents($this->cacheDirectory . '/b.cache', 'two');
    }

    #[After]
    public function cleanUp(): void
    {
        if (is_dir($this->cacheDirectory)) {
            rmdir($this->cacheDirectory);
        }
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    public function testRemovesEveryFileInTheCacheDirectory(): void
    {
        $output = $this->capture(function () {
            $exitCode = (new CacheClear())(Arguments::parse(['--path=' . $this->cacheDirectory]));
            self::assertSame(0, $exitCode);
        });

        self::assertSame([], glob($this->cacheDirectory . '/*'));
        self::assertStringContainsString('File cache cleared', $output);
    }

    public function testDoesNotDeleteSubdirectories(): void
    {
        mkdir($this->cacheDirectory . '/nested');

        (new CacheClear())(Arguments::parse(['--path=' . $this->cacheDirectory]));

        self::assertDirectoryExists($this->cacheDirectory . '/nested');

        rmdir($this->cacheDirectory . '/nested');
    }
}
