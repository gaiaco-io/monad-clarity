<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Monad\Clarity\Services\Console;

/**
 * `php mitosis make:service Billing` — writes app/Services/{Name}.php from a template.
 *
 * The directory is capitalised to match the template's own `namespace App\Services;` —
 * PSR-4 requires the on-disk path to match the namespace segment's case exactly (see
 * MakeController's docblock for the full rationale).
 *
 * @package Monad\Clarity\Console
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

        $path = getcwd() . '/app/Services/' . $name . '.php';

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
