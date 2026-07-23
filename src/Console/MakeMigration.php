<?php

declare(strict_types=1);

namespace Gaia\Clarity\Console;

use Gaia\Clarity\Services\Console;

/**
 * `php mitosis make:migration add index to users` — writes
 * database/migrations/{timestamp}_{slug}.php, a template returning an anonymous
 * up()/down() object matching what Services\Migration::migrate() expects.
 *
 * @package Gaia\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MakeMigration implements Command
{
    use GeneratesFiles;

    public function __invoke(Arguments $arguments): int
    {
        $name = $arguments->argument(0);

        if ($name === null) {
            Console::error('Usage: make:migration <description>');

            return 1;
        }

        $filename = date('YmdHis') . '_' . self::slug($name) . '.php';
        $path = getcwd() . '/database/migrations/' . $filename;

        self::writeGeneratedFile($path, self::template());

        Console::success(sprintf('Created migration: %s', $path));

        return 0;
    }

    private static function slug(string $name): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));
    }

    private static function template(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        use Gaia\Clarity\Services\Schema;
        use Gaia\Clarity\Services\Schema\Blueprint;

        return new class {
            public function up(): void
            {
                //
            }

            public function down(): void
            {
                //
            }
        };

        PHP;
    }
}
