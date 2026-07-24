<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use RuntimeException;
use Throwable;

/**
 * Server-side view rendering. Fixed, sequential pipeline (§24.3): resolve view, merge
 * local + shared data, run explicitly registered composers, render content, apply layout,
 * return response. No runtime magic, no implicit variable injection (§24.4) — every
 * variable reaching a view arrived via share(), a render() argument, or a composer.
 *
 * A view opts into a layout explicitly, either via `View::render($view, ['layout' => ...])`
 * or by assigning `$layout = '...'` at the top of the view file itself. The layout is
 * rendered with the same data plus a `$content` variable holding the child's output.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    /** @var array<string, list<callable>> */
    private static array $composers = [];

    private static string $basePath = '';

    /**
     * Set the directory view files resolve against. Must be called before render()/exists()
     * — Clarity has no config service of its own, so the application supplies this
     * explicitly (e.g. from config/dir.php) rather than View reading an ambient global.
     */
    public static function configure(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
    }

    /**
     * Make $value available as $key to every subsequently rendered view.
     */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Register a callback that runs just before $view renders. Receives the merged data
     * so far and returns additional/overriding data to merge in.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $callback
     */
    public static function composer(string $view, callable $callback): void
    {
        self::$composers[$view][] = $callback;
    }

    public static function exists(string $view): bool
    {
        return is_file(self::resolvePath($view));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = [], int $status = 200): Response
    {
        $path = self::resolvePath($view);

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('View "%s" not found at %s.', $view, $path));
        }

        $merged = [...self::$shared, ...$data];

        foreach (self::$composers[$view] ?? [] as $composer) {
            $merged = [...$merged, ...$composer($merged)];
        }

        [$content, $layout] = self::renderFile($path, $merged);

        if ($layout !== null) {
            [$content] = self::renderFile(self::resolvePath($layout), [...$merged, 'content' => $content]);
        }

        return Response::htm($content, $status);
    }

    /**
     * Remove all shared data and composers. For test isolation between requests —
     * process-lifetime static state otherwise.
     */
    public static function reset(): void
    {
        self::$shared = [];
        self::$composers = [];
        self::$basePath = '';
    }

    private static function resolvePath(string $view): string
    {
        if (self::$basePath === '') {
            throw new RuntimeException('View base path not configured; call View::configure() first.');
        }

        return self::$basePath . '/' . $view . '.php';
    }

    /**
     * Require the view file with $data extracted into local scope, capturing its output.
     * A view sets $layout (available here since `require` shares the enclosing function's
     * scope) to opt into a layout; $data['layout'] is honoured as the same thing.
     *
     * @param array<string, mixed> $data
     * @return array{0: string, 1: ?string}
     */
    private static function renderFile(string $path, array $data): array
    {
        $render = static function (string $__path, array $__data): array {
            extract($__data, EXTR_SKIP);

            ob_start();

            try {
                require $__path;
            } catch (Throwable $e) {
                ob_end_clean();

                throw $e;
            }

            return [ob_get_clean(), $layout ?? null];
        };

        return $render($path, $data);
    }
}
