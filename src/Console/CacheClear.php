<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;

/**
 * `php mitosis cache:clear` — empties the file cache driver's directory
 * (storage/cache by default, `--path=` to override). Only the file driver is touched:
 * the command has no way to know whether an application's Cache is configured for the
 * database or Redis driver, so a database/Redis-backed cache is left untouched rather
 * than silently doing nothing while claiming full success.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class CacheClear implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $path = (string) $arguments->option('path', getcwd() . '/storage/cache');

        foreach (glob(rtrim($path, '/') . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        Console::success('File cache cleared at ' . $path . ' (database/Redis drivers are not affected).');

        return 0;
    }
}
