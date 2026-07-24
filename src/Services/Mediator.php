<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use ErrorException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers PHP's error, exception, and shutdown handlers, and renders whatever reaches
 * them — one of two ways depending on environment. The development renderer favours
 * diagnosis (class, message, file/line, source excerpt, stack, request context, previous
 * chain); the production renderer favours safety (no internals, a stable status code, a
 * logged incident, and an incident ID the caller can hand to support).
 *
 * Static, like Route/View/DB — handleException() is called from existing unmigrated call
 * sites (DB, Session) that predate this phase and expect a static entry point.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class Mediator
{
    private const FATAL_ERROR_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    private const SOURCE_EXCERPT_RADIUS = 5;

    private static bool $debug = false;
    private static ?LoggerInterface $logger = null;

    /**
     * Set the environment mode and the logger exceptions are recorded through. Must be
     * called before register() — Clarity has no config service, so the application
     * supplies these explicitly rather than Mediator reading an ambient global.
     */
    public static function configure(bool $debug, ?LoggerInterface $logger = null): void
    {
        self::$debug = $debug;
        self::$logger = $logger;
    }

    /**
     * Wire PHP's global error/exception/shutdown handlers to this class.
     */
    public static function register(): void
    {
        set_error_handler(self::handleError(...));
        set_exception_handler(static function (Throwable $exception): void {
            self::handleException($exception)->send();
        });
        register_shutdown_function(self::handleShutdown(...));
    }

    /**
     * PHP error handler: respects error_reporting()/@-suppression, converts anything else
     * into an ErrorException so it flows through the same handling as a thrown exception.
     *
     * @throws ErrorException
     */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Shutdown handler: catches fatal errors that set_error_handler never sees.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], self::FATAL_ERROR_TYPES, true)) {
            return;
        }

        self::handleException(
            new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
        )->send();
    }

    /**
     * Build the response for an exception: log it, then render dev or prod detail.
     * Callable directly (not just via register()) so callers like DB/Session can hand
     * Mediator a caught exception without going through the global handler machinery.
     */
    public static function handleException(Throwable $exception, ?Request $request = null): Response
    {
        $incidentId = bin2hex(random_bytes(4));

        self::$logger?->error($exception->getMessage(), [
            'exception' => $exception,
            'request_id' => $incidentId,
        ]);

        return self::$debug
            ? self::renderDev($exception, $incidentId, $request)
            : self::renderProd($exception, $incidentId);
    }

    private static function renderDev(Throwable $exception, string $incidentId, ?Request $request): Response
    {
        $sections = [
            self::devHeading($exception),
            self::devSourceExcerpt($exception),
            self::devStackFrames($exception),
            self::devMeta($incidentId, $request),
        ];

        $previous = $exception->getPrevious();
        $chain = [];

        while ($previous !== null) {
            $chain[] = self::devHeading($previous, causedBy: true);
            $previous = $previous->getPrevious();
        }

        $html = self::devPage(implode("\n", [...$sections, ...$chain]));

        return Response::htm($html, 500);
    }

    private static function devHeading(Throwable $exception, bool $causedBy = false): string
    {
        return sprintf(
            '<section class="frame"><h2>%s%s</h2><p class="message">%s</p><p class="location">%s:%d</p></section>',
            $causedBy ? 'Caused by: ' : '',
            self::escape($exception::class),
            self::escape($exception->getMessage()),
            self::escape($exception->getFile()),
            $exception->getLine()
        );
    }

    private static function devSourceExcerpt(Throwable $exception): string
    {
        $lines = @file($exception->getFile());

        if ($lines === false) {
            return '';
        }

        $errorLine = $exception->getLine();
        $start = max(1, $errorLine - self::SOURCE_EXCERPT_RADIUS);
        $end = min(count($lines), $errorLine + self::SOURCE_EXCERPT_RADIUS);

        $rows = [];

        for ($number = $start; $number <= $end; $number++) {
            $rows[] = sprintf(
                '<div class="%s"><span class="ln">%d</span>%s</div>',
                $number === $errorLine ? 'excerpt-line current' : 'excerpt-line',
                $number,
                self::escape(rtrim($lines[$number - 1] ?? '', "\n"))
            );
        }

        return '<pre class="excerpt">' . implode('', $rows) . '</pre>';
    }

    private static function devStackFrames(Throwable $exception): string
    {
        $items = [];

        foreach ($exception->getTrace() as $index => $frame) {
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '{closure}');
            $location = isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? '?') : '[internal function]';

            $items[] = sprintf(
                '<li>#%d %s <span class="location">%s</span></li>',
                $index,
                self::escape($call . '()'),
                self::escape($location)
            );
        }

        return '<ol class="trace">' . implode('', $items) . '</ol>';
    }

    private static function devMeta(string $incidentId, ?Request $request): string
    {
        $summary = $request === null ? 'n/a' : $request->method() . ' ' . $request->path();

        return sprintf(
            '<section class="meta"><p>Request ID: <code>%s</code></p><p>Request: <code>%s</code></p></section>',
            self::escape($incidentId),
            self::escape($summary)
        );
    }

    private static function devPage(string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>Clarity — Unhandled Exception</title>'
            . '<style>' . self::devStyles() . '</style></head><body>' . $body . '</body></html>';
    }

    private static function devStyles(): string
    {
        return 'body{font:14px/1.5 ui-monospace,monospace;background:#1e1e2e;color:#cdd6f4;margin:0;padding:2rem}'
            . 'h2{color:#f38ba8;margin:0 0 .25rem}'
            . '.message{font-size:1.1rem;margin:0 0 .5rem}'
            . '.location{color:#94e2d5;margin:0 0 1rem}'
            . '.excerpt{background:#181825;border-radius:6px;padding:.5rem 0;overflow-x:auto;margin:0 0 1rem}'
            . '.excerpt-line{padding:0 1rem;white-space:pre}'
            . '.excerpt-line.current{background:#45475a;color:#f9e2af}'
            . '.ln{display:inline-block;width:3rem;color:#6c7086;user-select:none}'
            . '.trace{margin:0 0 1rem;padding-left:1.5rem}'
            . '.trace li{margin-bottom:.25rem}'
            . '.trace .location{color:#6c7086;display:block}'
            . '.meta code{color:#a6e3a1}';
    }

    private static function renderProd(Throwable $exception, string $incidentId): Response
    {
        return Response::json([
            'error' => 'An unexpected error occurred.',
            'incident_id' => $incidentId,
        ], 500);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Clear configuration. For test isolation between requests — process-lifetime static
     * state otherwise.
     */
    public static function reset(): void
    {
        self::$debug = false;
        self::$logger = null;
    }
}
