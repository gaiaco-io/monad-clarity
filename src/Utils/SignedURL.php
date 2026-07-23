<?php

declare(strict_types=1);

namespace Gaia\Clarity\Utils;

/**
 * Expiring, tamper-evident URLs (e.g. temporary file download links, unsubscribe links)
 * signed with HMAC. The expiry is part of the signed payload, so it cannot be extended
 * by an attacker who only controls the query string.
 *
 * @package Gaia\Clarity\Utils
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class SignedURL
{
    private const EXPIRES_PARAM = 'expires';
    private const SIGNATURE_PARAM = 'signature';

    private function __construct()
    {
    }

    /**
     * Sign a URL, valid for $ttlSeconds from now.
     */
    public static function sign(string $url, int $ttlSeconds, string $secret): string
    {
        [$base, $query] = self::split($url);

        $query[self::EXPIRES_PARAM] = (string) (time() + $ttlSeconds);
        $query[self::SIGNATURE_PARAM] = HMAC::sign(self::canonicalize($base, $query), $secret);

        return $base . '?' . http_build_query($query);
    }

    /**
     * Verify a signed URL: signature must match and the URL must not have expired.
     */
    public static function verify(string $signedUrl, string $secret): bool
    {
        [$base, $query] = self::split($signedUrl);

        if (!isset($query[self::EXPIRES_PARAM], $query[self::SIGNATURE_PARAM])) {
            return false;
        }

        $signature = $query[self::SIGNATURE_PARAM];
        unset($query[self::SIGNATURE_PARAM]);

        if (!ctype_digit($query[self::EXPIRES_PARAM]) || (int) $query[self::EXPIRES_PARAM] < time()) {
            return false;
        }

        return HMAC::verify(self::canonicalize($base, $query), $signature, $secret);
    }

    /**
     * Split a URL into its base (scheme/host/path) and query parameters.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function split(string $url): array
    {
        $parts = parse_url($url);

        $base = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
            . ($parts['host'] ?? '')
            . ($parts['path'] ?? '');

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return [$base, $query];
    }

    /**
     * Build the exact string that gets signed. Parameters are sorted by key so that
     * query-string ordering never affects the signature.
     */
    private static function canonicalize(string $base, array $query): string
    {
        ksort($query);

        return $base . '?' . http_build_query($query);
    }
}
