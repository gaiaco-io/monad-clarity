<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Migration;

/**
 * `php mitosis db:seed --file=seed.php` — runs a seed file (relative to
 * database/seeds unless an absolute path is given) via Services\Migration::runSeed().
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class DBSeed implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $file = $arguments->option('file');

        if (!is_string($file)) {
            Console::error('Usage: db:seed --file=<path>');

            return 1;
        }

        $context = $arguments->option('context');
        $path = str_starts_with($file, '/') ? $file : getcwd() . '/database/seeds/' . $file;

        Migration::runSeed($path, is_string($context) ? $context : null);

        Console::success('Seeded: ' . $file);

        return 0;
    }
}
