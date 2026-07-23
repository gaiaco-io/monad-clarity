<?php

declare(strict_types=1);

namespace Gaia\Clarity\Console;

use Gaia\Clarity\Services\Console;

/**
 * `php mitosis make:controller UserController` — writes app/controllers/{Name}.php from
 * a template. Paths are relative to the current working directory, matching the fixed
 * skeleton tree in RepoMap.md (commands run from the application's project root).
 *
 * @package Gaia\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MakeController implements Command
{
    use GeneratesFiles;

    public function __invoke(Arguments $arguments): int
    {
        $name = $arguments->argument(0);

        if ($name === null) {
            Console::error('Usage: make:controller <Name>');

            return 1;
        }

        $path = getcwd() . '/app/controllers/' . $name . '.php';

        if (!self::writeGeneratedFile($path, self::template($name))) {
            Console::error(sprintf('Controller already exists: %s', $path));

            return 1;
        }

        Console::success(sprintf('Created controller: %s', $path));

        return 0;
    }

    private static function template(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Controllers;

        final class {$name}
        {
        }

        PHP;
    }
}
