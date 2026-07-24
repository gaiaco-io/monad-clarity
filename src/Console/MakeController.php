<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;

/**
 * `php mitosis make:controller UserController` — writes app/Controllers/{Name}.php from
 * a template. Paths are relative to the current working directory, matching the fixed
 * skeleton tree in RepoMap.md (commands run from the application's project root).
 *
 * The directory is capitalised to match the template's own `namespace App\Controllers;`
 * — PSR-4 requires the on-disk path to match the namespace segment's case exactly, so a
 * lowercase `app/controllers/` directory would silently fail to autoload on any
 * case-sensitive filesystem (Linux, most CI/production hosts) despite working by
 * coincidence in local development on a case-insensitive one (macOS, Windows).
 *
 * @package Monad\Clarity\Console
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

        $path = getcwd() . '/app/Controllers/' . $name . '.php';

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
