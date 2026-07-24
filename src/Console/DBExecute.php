<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Migration;

/**
 * `php mitosis db:execute database/migrations/20260711_fix_user_table.sql` — runs a raw
 * .sql file via Services\Migration::runSqlScript().
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class DBExecute implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $file = $arguments->argument(0);

        if ($file === null) {
            Console::error('Usage: db:execute <path-to-sql-file>');

            return 1;
        }

        $context = $arguments->option('context');
        Migration::runSqlScript($file, is_string($context) ? $context : null);

        Console::success('Executed: ' . $file);

        return 0;
    }
}
