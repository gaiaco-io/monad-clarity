<?php

declare(strict_types=1);

namespace Gaia\Clarity\Utils;

/**
 * Recursively masks sensitive values (passwords, tokens, API keys) out of arrays
 * before they reach a log line. Used by Middlewares\Logger and anything else that
 * might otherwise write secrets to disk.
 *
 * @package Gaia\Clarity\Utils
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Redactor
{
    private const MASK = '[REDACTED]';

    private const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'private_key',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
    ];

    private function __construct()
    {
    }

    /**
     * Return a copy of $data with sensitive values masked. Matching is case- and
     * separator-insensitive by substring, so `X-Api-Key`, `api_key`, and
     * `openai_api_key` all match `api_key`.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $sensitiveKeys
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, array $sensitiveKeys = self::DEFAULT_SENSITIVE_KEYS): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = self::redact($value, $sensitiveKeys);
                continue;
            }

            $redacted[$key] = self::isSensitiveKey((string) $key, $sensitiveKeys) ? self::MASK : $value;
        }

        return $redacted;
    }

    private static function isSensitiveKey(string $key, array $sensitiveKeys): bool
    {
        $normalized = self::normalize($key);

        foreach ($sensitiveKeys as $sensitive) {
            if (str_contains($normalized, self::normalize($sensitive))) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $key): string
    {
        return str_replace('-', '_', strtolower($key));
    }
}
