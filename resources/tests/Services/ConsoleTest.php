<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services;

use Monad\Clarity\Console\Arguments;
use Monad\Clarity\Console\Command;
use Monad\Clarity\Services\Console;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    #[After]
    public function resetConsole(): void
    {
        Console::reset();
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    public function testRunWithNoCommandPrintsHelpAndSucceeds(): void
    {
        $output = $this->capture(fn () => self::assertSame(0, Console::run(['mitosis'])));

        self::assertStringContainsString('available commands', $output);
        self::assertStringContainsString('make:controller', $output);
        self::assertStringContainsString('setup', $output);
    }

    public function testRunWithHelpFlagPrintsHelp(): void
    {
        $output = $this->capture(fn () => self::assertSame(0, Console::run(['mitosis', '--help'])));

        self::assertStringContainsString('available commands', $output);
    }

    public function testRunWithUnknownCommandFailsAndPrintsHelp(): void
    {
        $output = $this->capture(fn () => self::assertSame(1, Console::run(['mitosis', 'nope'])));

        self::assertStringContainsString('Unknown command "nope"', $output);
        self::assertStringContainsString('available commands', $output);
    }

    public function testCommandNamesListsAllFifteenBuiltIns(): void
    {
        self::assertCount(15, Console::commandNames());
        self::assertContains('migrate', Console::commandNames());
        self::assertContains('cache:clear', Console::commandNames());
    }

    public function testRegisterAcceptsAClosureAndReceivesParsedArguments(): void
    {
        $received = null;

        Console::register('greet', function (Arguments $arguments) use (&$received) {
            $received = $arguments->argument(0);

            return 0;
        });

        $exitCode = Console::run(['mitosis', 'greet', 'World']);

        self::assertSame(0, $exitCode);
        self::assertSame('World', $received);
    }

    public function testRegisterAcceptsAClassStringImplementingCommand(): void
    {
        Console::register('echo-arg', EchoArgumentCommand::class);

        $output = $this->capture(fn () => Console::run(['mitosis', 'echo-arg', 'hello']));

        self::assertStringContainsString('hello', $output);
    }

    public function testRegisteredCommandExitCodeIsReturnedFromRun(): void
    {
        Console::register('fail', fn (Arguments $arguments) => 1);

        self::assertSame(1, Console::run(['mitosis', 'fail']));
    }

    public function testThrowingCommandIsCaughtAndReturnsFailureExitCode(): void
    {
        Console::register('boom', function (): int {
            throw new \RuntimeException('kaboom');
        });

        $output = $this->capture(fn () => self::assertSame(1, Console::run(['mitosis', 'boom'])));

        self::assertStringContainsString('kaboom', $output);
    }

    public function testBrokenCliRoutesFileFailsCleanlyInsteadOfThrowing(): void
    {
        $routesFile = sys_get_temp_dir() . '/clarity-cli-routes-broken-' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($routesFile, '<?php throw new \RuntimeException("broken routes file");');

        Console::configure($routesFile);

        $output = $this->capture(function () {
            self::assertSame(1, Console::run(['mitosis', 'help']));
        });

        self::assertStringContainsString('broken routes file', $output);

        unlink($routesFile);
    }

    public function testConfigureLoadsApplicationCliRoutesBeforeDispatch(): void
    {
        $routesFile = sys_get_temp_dir() . '/clarity-cli-routes-' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents(
            $routesFile,
            '<?php Monad\Clarity\Services\Console::register("from-routes", fn () => 0);'
        );

        Console::configure($routesFile);

        self::assertSame(0, Console::run(['mitosis', 'from-routes']));

        unlink($routesFile);
    }
}

final class EchoArgumentCommand implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        Console::writeLine((string) $arguments->argument(0));

        return 0;
    }
}
