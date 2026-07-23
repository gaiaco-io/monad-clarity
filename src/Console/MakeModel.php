<?php

declare(strict_types=1);

namespace Gaia\Clarity\Console;

use Gaia\Clarity\Services\Console;

/**
 * `php mitosis make:model User` — writes app/models/{Name}.php from a template.
 *
 * @package Gaia\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MakeModel implements Command
{
    use GeneratesFiles;

    public function __invoke(Arguments $arguments): int
    {
        $name = $arguments->argument(0);

        if ($name === null) {
            Console::error('Usage: make:model <Name>');

            return 1;
        }

        $path = getcwd() . '/app/models/' . $name . '.php';

        if (!self::writeGeneratedFile($path, self::template($name))) {
            Console::error(sprintf('Model already exists: %s', $path));

            return 1;
        }

        Console::success(sprintf('Created model: %s', $path));

        return 0;
    }

    private static function template(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Models;

        final class {$name}
        {
        }

        PHP;
    }
}
