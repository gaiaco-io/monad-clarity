<?php

declare(strict_types=1);

namespace Gaia\Clarity\Console;

use Gaia\Clarity\Services\Console;
use Gaia\Clarity\Services\DB;
use Gaia\Clarity\Services\Migration;
use Throwable;

/**
 * `php mitosis health` — the deployment acceptance gate (DeploymentTopology.md §5):
 * configuration completeness, DB connectivity, writable storage paths, migration
 * status, required PHP extensions. Exits 0 only if every check passes.
 *
 * "Configuration completeness" is necessarily a best-effort check: Clarity does not own
 * the application's required-config-key schema (that's app/skeleton territory), so this
 * checks for the presence of a `.env` file at the project root as a practical proxy
 * rather than validating specific keys it has no way of knowing about.
 *
 * @package Gaia\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Health implements Command
{
    private const REQUIRED_EXTENSIONS = ['pdo', 'mbstring', 'json', 'curl', 'fileinfo'];

    private const REQUIRED_STORAGE_PATHS = [
        'storage/cache',
        'storage/logs',
        'storage/userfiles',
    ];

    public function __invoke(Arguments $arguments): int
    {
        $checks = [
            'Configuration' => self::checkConfiguration(),
            'Database connectivity' => self::checkDatabase($arguments),
            'Writable storage' => self::checkStorage(),
            'Migration status' => self::checkMigrations($arguments),
            'PHP extensions' => self::checkExtensions(),
        ];

        $allPassed = true;

        foreach ($checks as $name => [$passed, $detail]) {
            $allPassed = $allPassed && $passed;
            $passed ? Console::success("{$name}: {$detail}") : Console::error("{$name}: {$detail}");
        }

        return $allPassed ? 0 : 1;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private static function checkConfiguration(): array
    {
        return is_file(getcwd() . '/.env')
            ? [true, '.env present']
            : [false, '.env not found'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private static function checkDatabase(Arguments $arguments): array
    {
        $context = $arguments->option('context');

        try {
            DB::connect(is_string($context) ? $context : null)->query('SELECT 1');

            return [true, 'connected'];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private static function checkStorage(): array
    {
        $unwritable = [];

        foreach (self::REQUIRED_STORAGE_PATHS as $relative) {
            $path = getcwd() . '/' . $relative;

            if (!is_dir($path) || !is_writable($path)) {
                $unwritable[] = $relative;
            }
        }

        return $unwritable === []
            ? [true, 'all storage paths writable']
            : [false, 'not writable: ' . implode(', ', $unwritable)];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private static function checkMigrations(Arguments $arguments): array
    {
        $path = (string) $arguments->option('migrations-path', getcwd() . '/database/migrations');
        $context = $arguments->option('context');

        if (!is_dir($path)) {
            return [true, 'no migrations directory'];
        }

        $status = Migration::status($path, is_string($context) ? $context : null);
        $pending = array_keys(array_filter($status, static fn (bool $applied) => !$applied));

        return $pending === []
            ? [true, 'up to date']
            : [false, 'pending: ' . implode(', ', $pending)];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private static function checkExtensions(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension) => !extension_loaded($extension)
        ));

        return $missing === []
            ? [true, 'all present']
            : [false, 'missing: ' . implode(', ', $missing)];
    }
}
