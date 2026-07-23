<?php

declare(strict_types=1);

namespace Gaia\Clarity\Console;

use Gaia\Clarity\Services\Console;

/**
 * `php mitosis make:service Billing` — writes app/services/{Name}.php from a template.
 *
 * @package Gaia\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MakeService implements Command
{
    use GeneratesFiles;

    public function __invoke(Arguments $arguments): int
    {
        $name = $arguments->argument(0);

        if ($name === null) {
            Console::error('Usage: make:service <Name>');

            return 1;
        }

        $path = getcwd() . '/app/services/' . $name . '.php';

        if (!self::writeGeneratedFile($path, self::template($name))) {
            Console::error(sprintf('Service already exists: %s', $path));

            return 1;
        }

        Console::success(sprintf('Created service: %s', $path));

        return 0;
    }

    private static function template(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Services;

        final class {$name}
        {
        }

        PHP;
    }
}
