<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Migration;

/**
 * `php mitosis migrate:rollback` — rolls back the most recently applied migration
 * (`--steps=N` to roll back further).
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MigrateRollback implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $path = (string) $arguments->option('path', getcwd() . '/database/migrations');
        $context = $arguments->option('context');
        $steps = (int) $arguments->option('steps', '1');

        $rolledBack = Migration::rollback($path, $steps, is_string($context) ? $context : null);

        if ($rolledBack === []) {
            Console::info('Nothing to roll back.');

            return 0;
        }

        foreach ($rolledBack as $name) {
            Console::success('Rolled back: ' . $name);
        }

        return 0;
    }
}
