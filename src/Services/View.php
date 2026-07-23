<?php

namespace Gaia\Clarity\Services;

use Throwable;
use Gaia\Clarity\Services\Mediator;

/**
 * Handles server-side view loading and display utilities.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

class View
{
    public static array $dataset = [];
    private static array $var_set = [];
    private static string $view_path = '';
    private static bool $prepared = false;
    private const FALLBACK_VIEW = 'Errors/404';

    /**
     * Prepare the view dataset for rendering. This is a pre-requisite for the render method.
     * 
     * @param string $file_name
     * @param array $data The data to be passed to the view file.
     * @return View Returns self for method chaining.
     */
    public static function prepare(string $file_name, array $data = []): self
    {
        try {
            if ($data !== []) {
                foreach ($data as $key => $value) {
                    // Only escape strings, preserve arrays/objects for view iteration
                    if (is_string($value)) {
                        self::$dataset[$key] = htmlspecialchars(stripslashes(trim($value)), ENT_QUOTES, 'UTF-8');
                    } elseif (is_array($value)) {
                        // Recursively escape string values in arrays
                        self::$dataset[$key] = self::escapeArray($value);
                    } else {
                        // Preserve objects, numbers, etc. - views should handle escaping
                        self::$dataset[$key] = $value;
                    }
                    // Also populate var_set for View::get() to work
                    self::$var_set[$key] = self::$dataset[$key];
                }
            }

            // Extract dataset variables for use in view files
            extract(self::$dataset, EXTR_SKIP);

            // Resolve view directory path relative to project root
            $view_dir = PATH['view'] ?? '';
            $project_root = dirname(__DIR__, 2); // Go up from Clarity/services to project root

            // PATH['view'] from config/dir.php is already an absolute resolved path
            // Only resolve if it's empty or relative (shouldn't happen if config/dir.php works correctly)
            if (empty($view_dir)) {
                // Fallback: default view directory
                $view_dir = $project_root . '/app/server/views/';
            } elseif ($view_dir[0] !== '/') {
                // Only resolve relative paths (shouldn't happen if config/dir.php works correctly)
                $view_dir = rtrim($project_root . '/' . $view_dir, '/') . '/';
            } else {
                // Already absolute path from config/dir.php - use as-is (prevents duplication)
                $view_dir = rtrim($view_dir, '/') . '/';
            }

            self::$view_path = $view_dir . $file_name . '.php';

            if (!file_exists(self::$view_path)) {
                throw new \RuntimeException("View file not found: " . self::$view_path);
            }
            self::$prepared = true;
        } catch (Throwable $e) {
            Mediator::handleException($e);
            // Exit here since Mediator::handleException() should have already exited,
            // but if it didn't, we need to prevent further execution
            exit;
        }

        return new self();
    }

    /**
     * Require a view file and handle any errors. View files are loaded using the require_once function.
     * The .php extension is automatically appended to the file path. View file must be placed in
     * /app/server/views/
     *
     * @return self Returns self for method chaining.
     */
    public static function render(): self
    {
        if (!self::$prepared || empty(self::$view_path)) {
            self::prepareFallback();
        }

        if (!file_exists(self::$view_path)) {
            throw new \RuntimeException("View file not found: " . self::$view_path);
        }

        require_once self::$view_path;
        return new self();
    }

    /**
     * Set a variable in the view dataset.
     * 
     * @param string $var_name The name of the variable to set.
     * @param mixed $value The value of the variable to set.
     * @return self Returns self for method chaining.
     */
    public function set(string $var_name, mixed $value): self
    {
        self::$var_set[$var_name] = $value;
        return $this;
    }

    /**
     * Get a variable from the view dataset.
     * 
     * @param string $var_name The name of the variable to get.
     * @return mixed
     */
    public static function get(string $var_name): mixed
    {
        return self::$var_set[$var_name] ?? null;
    }

    /**
     * Check if a variable is set in the view dataset.
     * 
     * @param string $var_name The name of the variable to check.
     * @return bool
     */
    public static function has(string $var_name): bool
    {
        return isset(self::$var_set[$var_name]);
    }

    /**
     * Recursively escape string values in arrays
     */
    private static function escapeArray(array $array): array
    {
        $escaped = [];
        foreach ($array as $key => $value) {
            if (is_string($value)) {
                $escaped[$key] = htmlspecialchars(stripslashes(trim($value)), ENT_QUOTES, 'UTF-8');
            } elseif (is_array($value)) {
                $escaped[$key] = self::escapeArray($value);
            } else {
                $escaped[$key] = $value;
            }
        }
        return $escaped;
    }

    /**
     * Prepare fallback view data and reset previous state.
     */
    private static function prepareFallback(): void
    {
        self::$dataset = [];
        self::$var_set = [];

        $data = [
            'app_name' => APP['name'] ?? 'Application',
            'requested_path' => $_SERVER['REQUEST_URI'] ?? '/',
        ];

        self::prepare(self::FALLBACK_VIEW, $data);
    }

    /**
     * Check if a view has been prepared.
     */
    public static function isPrepared(): bool
    {
        return self::$prepared;
    }
}
