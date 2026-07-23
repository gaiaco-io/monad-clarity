<?php

namespace Gaia\Clarity\Services;

use Throwable;

/**
 * Mediator class for handling exceptions gracefully. Class may output either the error stack or
 * a user friendly message, depending on the ENV_MODE.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

abstract class Mediator
{
    public static function handleException(Throwable $exception): void
    {
        $reference = bin2hex(random_bytes(4));
        self::logException($exception, $reference);

        $env_mode = strtolower(trim(
            defined('ENV_MODE')
                ? (string) ENV_MODE
                : ((string) getenv('ENV_MODE') ?: ((string) getenv('ENV_PRODUCTION') === '0' ? 'development' : 'production'))
        ));

        $is_debug = in_array($env_mode, ['0', 'development', 'dev', 'local'], true);

        if ($is_debug) {
            // Output the error stack for development mode
            echo '<pre>';
            echo htmlspecialchars($exception->getMessage());
            echo "\n";
            echo htmlspecialchars($exception->getTraceAsString());
            echo '</pre>';
            exit;
        }

        die('An error occurred. Reference: ' . $reference);
    }

    public static function handleUserMessage(string $message): void
    {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Persist exception details for operators
     */
    private static function logException(Throwable $exception, string $reference): void
    {
        $log_dir = defined('PATH') && isset(PATH['error_log'])
            ? PATH['error_log']
            : dirname(__DIR__, 2) . '/storage/logs/error/';

        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0775, true);
        }

        $log_file = rtrim($log_dir, '/') . '/app.log';
        $timestamp = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $payload = sprintf(
            "[%s] [ref:%s] %s in %s:%d\n%s\n\n",
            $timestamp,
            $reference,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        @file_put_contents($log_file, $payload, FILE_APPEND);
    }
}
