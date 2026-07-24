<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

/**
 * The shape every command — built-in or app-registered via `app/routes/cli.php` — is
 * invoked through. Not required by Console::register() (a plain closure works too), but
 * every built-in command implements it for consistency.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
interface Command
{
    /**
     * @return int Process exit code (0 success, non-zero failure).
     */
    public function __invoke(Arguments $arguments): int;
}
