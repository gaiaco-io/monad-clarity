<?php

declare(strict_types=1);

namespace Gaia\Clarity\Console;

use Gaia\Clarity\Services\Console;

/**
 * `php mitosis serve` — runs PHP's built-in server bound to 127.0.0.1:8000 (`--port=N`
 * to override), rooted at public/ with public/router.php as the front controller if
 * present. A development convenience only (DeploymentTopology.md §6), not a production
 * server.
 *
 * commandLine() is split out from __invoke() deliberately: it blocks on passthru() until
 * the server process is killed, so tests exercise the assembled command string instead
 * of ever invoking this for real.
 *
 * @package Gaia\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Serve implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        Console::info(sprintf('Starting development server at http://127.0.0.1:%s', $arguments->option('port', '8000')));

        passthru($this->commandLine($arguments), $exitCode);

        return $exitCode;
    }

    public function commandLine(Arguments $arguments): string
    {
        $port = (string) $arguments->option('port', '8000');
        $publicDirectory = getcwd() . '/public';
        $router = $publicDirectory . '/router.php';

        $command = sprintf(
            '%s -S %s -t %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg('127.0.0.1:' . $port),
            escapeshellarg($publicDirectory)
        );

        return is_file($router) ? $command . ' ' . escapeshellarg($router) : $command;
    }
}
