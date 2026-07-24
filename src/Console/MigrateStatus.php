<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Migration;

/**
 * `php mitosis migrate:status` — lists every migration file and whether it has run.
 * Purely informational: always exits 0, even with pending migrations. `health` is the
 * documented deployment gate (DeploymentTopology.md §5) that already fails on pending
 * migrations — this command doubling as a second gate would make `migrate:status && …`
 * fail unpredictably in scripts for a command whose whole job is to report, not judge.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MigrateStatus implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $path = (string) $arguments->option('path', getcwd() . '/database/migrations');
        $context = $arguments->option('context');

        $status = Migration::status($path, is_string($context) ? $context : null);

        foreach ($status as $name => $applied) {
            $applied ? Console::success($name) : Console::error($name . ' (pending)');
        }

        return 0;
    }
}
