<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\CacheClear;
use Monad\Clarity\Console\DBExecute;
use Monad\Clarity\Console\DBSeed;
use Monad\Clarity\Console\Health;
use Monad\Clarity\Console\LogsClear;
use Monad\Clarity\Console\MakeController;
use Monad\Clarity\Console\MakeMigration;
use Monad\Clarity\Console\MakeModel;
use Monad\Clarity\Console\MakeService;
use Monad\Clarity\Console\Migrate;
use Monad\Clarity\Console\MigrateRollback;
use Monad\Clarity\Console\MigrateStatus;
use Monad\Clarity\Console\Serve;
use Monad\Clarity\Console\Setup;
use Monad\Clarity\Console\Test;
use Throwable;

/**
 * The `mitosis` CLI kernel. `run(array $argv): int` is the frozen contract
 * (CrossRepoContracts.md §2–3) that the skeleton's `mitosis` stub calls directly; the 15
 * built-in command classes under `Console\*` are internal and freely reorganisable.
 *
 * Application-defined commands are wired via `app/routes/cli.php`, loaded (if configured)
 * on every run() before dispatch — that file calls Console::register() the same way
 * `app/routes/web.php` calls Route::get()/post().
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class Console
{
    private const BUILT_IN_COMMANDS = [
        'make:controller' => MakeController::class,
        'make:model' => MakeModel::class,
        'make:migration' => MakeMigration::class,
        'make:service' => MakeService::class,
        'migrate' => Migrate::class,
        'migrate:status' => MigrateStatus::class,
        'migrate:rollback' => MigrateRollback::class,
        'db:seed' => DBSeed::class,
        'db:execute' => DBExecute::class,
        'test' => Test::class,
        'health' => Health::class,
        'serve' => Serve::class,
        'cache:clear' => CacheClear::class,
        'logs:clear' => LogsClear::class,
        'setup' => Setup::class,
    ];

    /** @var array<string, callable|string> */
    private static array $commands = [];

    private static bool $builtInsRegistered = false;

    private static ?string $cliRoutesPath = null;

    /**
     * Path to the application's `app/routes/cli.php`, loaded on every run() before
     * dispatch. Set from `config/bootstrap.php`, since the frozen `mitosis` stub
     * (Architecture.md §5) calls run($argv) directly with no room for extra arguments.
     */
    public static function configure(?string $cliRoutesPath = null): void
    {
        self::$cliRoutesPath = $cliRoutesPath;
    }

    /**
     * Register a command under $name. $handler is either a callable (closure, invokable
     * object, `[Class::class, 'method']`) or a class-string instantiated with no
     * constructor arguments and invoked as `(new $handler())($arguments)`.
     */
    public static function register(string $name, callable|string $handler): void
    {
        self::$commands[$name] = $handler;
    }

    public static function run(array $argv): int
    {
        self::ensureBuiltInsRegistered();

        try {
            self::loadApplicationRoutes();
        } catch (Throwable $e) {
            self::error('Failed to load app/routes/cli.php: ' . $e->getMessage());

            return 1;
        }

        $commandName = $argv[1] ?? null;

        if ($commandName === null || in_array($commandName, ['help', '--help', '-h'], true)) {
            self::printHelp();

            return 0;
        }

        if (!isset(self::$commands[$commandName])) {
            self::error(sprintf('Unknown command "%s".', $commandName));
            self::printHelp();

            return 1;
        }

        $arguments = Arguments::parse(array_slice($argv, 2));

        try {
            return self::invoke(self::$commands[$commandName], $arguments);
        } catch (Throwable $e) {
            self::error($e->getMessage());

            return 1;
        }
    }

    /**
     * @return list<string>
     */
    public static function commandNames(): array
    {
        self::ensureBuiltInsRegistered();

        return array_keys(self::$commands);
    }

    public static function writeLine(string $message = ''): void
    {
        echo $message . PHP_EOL;
    }

    public static function success(string $message): void
    {
        self::writeLine("\033[32m\xe2\x9c\x93\033[0m " . $message);
    }

    public static function error(string $message): void
    {
        self::writeLine("\033[31m\xe2\x9c\x97\033[0m " . $message);
    }

    public static function info(string $message): void
    {
        self::writeLine("\033[36m\xe2\x84\xb9\033[0m " . $message);
    }

    /**
     * Clear registered commands and configuration. For test isolation — process-lifetime
     * static state otherwise.
     */
    public static function reset(): void
    {
        self::$commands = [];
        self::$builtInsRegistered = false;
        self::$cliRoutesPath = null;
    }

    private static function ensureBuiltInsRegistered(): void
    {
        if (self::$builtInsRegistered) {
            return;
        }

        foreach (self::BUILT_IN_COMMANDS as $name => $class) {
            self::$commands[$name] = $class;
        }

        self::$builtInsRegistered = true;
    }

    private static function loadApplicationRoutes(): void
    {
        if (self::$cliRoutesPath !== null && is_file(self::$cliRoutesPath)) {
            require self::$cliRoutesPath;
        }
    }

    private static function invoke(callable|string $handler, Arguments $arguments): int
    {
        $callable = is_callable($handler) ? $handler : new $handler();

        return (int) $callable($arguments);
    }

    private static function printHelp(): void
    {
        self::writeLine('Monad Clarity — available commands:');

        foreach (self::commandNames() as $name) {
            self::writeLine('  ' . $name);
        }
    }
}
