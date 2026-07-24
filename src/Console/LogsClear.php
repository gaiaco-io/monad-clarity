<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;

/**
 * `php mitosis logs:clear` — truncates every `*.log*` file under storage/logs
 * (`--path=` to override) to empty, without deleting the files themselves.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class LogsClear implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $path = (string) $arguments->option('path', getcwd() . '/storage/logs');

        foreach (self::logFiles($path) as $file) {
            file_put_contents($file, '');
        }

        Console::success('Logs cleared at ' . $path . '.');

        return 0;
    }

    /**
     * @return list<string>
     */
    private static function logFiles(string $root): array
    {
        $direct = glob(rtrim($root, '/') . '/*.log*') ?: [];
        $nested = glob(rtrim($root, '/') . '/*/*.log*') ?: [];

        return [...$direct, ...$nested];
    }
}
