<?php

declare(strict_types=1);

namespace Monad\Clarity\Middlewares;

use DateTimeImmutable;
use DateTimeZone;
use Monad\Clarity\Utils\Redactor;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * PSR-3 logger writing timezone-aware, rotated, redacted log lines to a single file.
 * The application wires up one instance per destination — `app.log`, `db.log`,
 * `timeline.log` — each with its own channel label and path (see `DeploymentTopology.md`).
 *
 * @package Monad\Clarity\Middlewares
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
class Logger extends AbstractLogger
{
    private const VALID_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly string $channel,
        private readonly string $path,
        private readonly bool $json = false,
        ?DateTimeZone $timezone = null,
        private readonly int $maxBytesBeforeRotation = 10_485_760,
        private readonly int $maxRotatedFiles = 5,
    ) {
        $this->timezone = $timezone ?? new DateTimeZone(date_default_timezone_get());
    }

    /**
     * @param array<string, mixed> $context May include `request_id`, `user_id`, and/or
     *     an `exception` (Throwable), which are promoted to top-level fields rather than
     *     nested under `context`. Everything else is passed through Redactor before writing.
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!in_array((string) $level, self::VALID_LEVELS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown log level "%s".', $level));
        }

        $requestId = $context['request_id'] ?? null;
        $userId = $context['user_id'] ?? null;
        $exception = $context['exception'] ?? null;
        unset($context['request_id'], $context['user_id'], $context['exception']);

        $entry = [
            'timestamp' => (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM),
            'channel' => $this->channel,
            'level' => strtoupper((string) $level),
            'message' => self::interpolate((string) $message, $context),
            'context' => Redactor::redact($context),
            'request_id' => $requestId,
            'user_id' => $userId,
            'exception' => $exception instanceof Throwable ? self::describeException($exception) : null,
        ];

        $this->write($this->json ? self::asJsonLine($entry) : self::asTextLine($entry));
    }

    /**
     * Replace PSR-3 `{placeholder}` tokens in $message with matching scalar/Stringable
     * values from $context, per the PSR-3 message interpolation convention.
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * @return array{class: string, message: string, file: string, line: int, trace: string}
     */
    private static function describeException(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function asJsonLine(array $entry): string
    {
        return json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function asTextLine(array $entry): string
    {
        $line = sprintf(
            '[%s] %s.%s: %s',
            $entry['timestamp'],
            $entry['channel'],
            $entry['level'],
            $entry['message']
        );

        if ($entry['request_id'] !== null) {
            $line .= ' request_id=' . $entry['request_id'];
        }

        if ($entry['user_id'] !== null) {
            $line .= ' user_id=' . $entry['user_id'];
        }

        if ($entry['context'] !== []) {
            $line .= ' ' . json_encode($entry['context'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        if ($entry['exception'] !== null) {
            $line .= sprintf(
                ' exception=%s("%s" at %s:%d)',
                $entry['exception']['class'],
                $entry['exception']['message'],
                $entry['exception']['file'],
                $entry['exception']['line']
            );
        }

        return $line . PHP_EOL;
    }

    private function write(string $line): void
    {
        $this->rotateIfNeeded();

        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Log directory "%s" does not exist and could not be created.', $directory));
        }

        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write to log file "%s".', $this->path));
        }
    }

    /**
     * Size-based rotation: when the current file exceeds the configured threshold,
     * shift path.(N-1) -> path.N down the chain and move path -> path.1, discarding
     * anything beyond $maxRotatedFiles.
     */
    private function rotateIfNeeded(): void
    {
        if (!is_file($this->path) || filesize($this->path) < $this->maxBytesBeforeRotation) {
            return;
        }

        $oldest = $this->path . '.' . $this->maxRotatedFiles;

        if (is_file($oldest)) {
            unlink($oldest);
        }

        for ($generation = $this->maxRotatedFiles - 1; $generation >= 1; $generation--) {
            $from = $this->path . '.' . $generation;

            if (is_file($from)) {
                rename($from, $this->path . '.' . ($generation + 1));
            }
        }

        rename($this->path, $this->path . '.1');
    }
}
