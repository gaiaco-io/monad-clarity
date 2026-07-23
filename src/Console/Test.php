<?php

declare(strict_types=1);

namespace Gaia\Clarity\Console;

use Gaia\Clarity\Services\Console;

/**
 * `php mitosis test` — delegates to the application's bundled PHPUnit. No bespoke
 * runner; all arguments after `test` pass straight through.
 *
 * commandLine() is split out from __invoke() the same way Serve is: passthru() here
 * would re-run this very suite from inside itself if a test ever called __invoke()
 * directly, so tests only ever assert on the assembled command string.
 *
 * @package Gaia\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Test implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $binary = getcwd() . '/vendor/bin/phpunit';

        if (!is_file($binary)) {
            Console::error('PHPUnit not found — is it installed via composer?');

            return 1;
        }

        passthru($this->commandLine($arguments), $exitCode);

        return $exitCode;
    }

    public function commandLine(Arguments $arguments): string
    {
        $binary = getcwd() . '/vendor/bin/phpunit';

        $parts = array_map('escapeshellarg', [$binary, ...$arguments->rawTokens()]);

        return implode(' ', $parts);
    }
}
