<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Migration;

/**
 * `php mitosis migrate` — applies every pending file under database/migrations.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Migrate implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $path = (string) $arguments->option('path', getcwd() . '/database/migrations');
        $context = $arguments->option('context');

        $applied = Migration::migrate($path, is_string($context) ? $context : null);

        if ($applied === []) {
            Console::info('Nothing to migrate.');

            return 0;
        }

        foreach ($applied as $name) {
            Console::success('Migrated: ' . $name);
        }

        return 0;
    }
}
