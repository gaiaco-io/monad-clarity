<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use RuntimeException;

/**
 * Shared "write a template file, refusing to overwrite an existing one" behaviour for
 * the four make:* generator commands.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
trait GeneratesFiles
{
    private static function writeGeneratedFile(string $path, string $contents): bool
    {
        if (is_file($path)) {
            return false;
        }

        self::ensureDirectory(dirname($path));
        file_put_contents($path, $contents);

        return true;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }
    }
}
