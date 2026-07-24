<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Utils;

use Monad\Clarity\Utils\SignedURL;
use PHPUnit\Framework\TestCase;

final class SignedURLTest extends TestCase
{
    public function testVerifyTrueForFreshlySignedUrl(): void
    {
        $url = SignedURL::sign('https://example.com/download/report.pdf', 3600, 'secret');

        self::assertTrue(SignedURL::verify($url, 'secret'));
    }

    public function testVerifyFalseForExpiredUrl(): void
    {
        $url = SignedURL::sign('https://example.com/download/report.pdf', -1, 'secret');

        self::assertFalse(SignedURL::verify($url, 'secret'));
    }

    public function testVerifyFalseForWrongSecret(): void
    {
        $url = SignedURL::sign('https://example.com/download/report.pdf', 3600, 'secret');

        self::assertFalse(SignedURL::verify($url, 'wrong-secret'));
    }

    public function testVerifyFalseWhenSignatureIsTampered(): void
    {
        $url = SignedURL::sign('https://example.com/download/report.pdf', 3600, 'secret');
        $tampered = preg_replace('/signature=[^&]+/', 'signature=deadbeef', $url);

        self::assertFalse(SignedURL::verify($tampered, 'secret'));
    }

    public function testVerifyFalseWhenExpiryIsExtendedByAttacker(): void
    {
        $url = SignedURL::sign('https://example.com/download/report.pdf', -1, 'secret');
        $extended = preg_replace('/expires=\d+/', 'expires=' . (time() + 3600), $url);

        self::assertFalse(SignedURL::verify($extended, 'secret'));
    }

    public function testVerifyFalseWhenPathIsTampered(): void
    {
        $url = SignedURL::sign('https://example.com/download/report.pdf', 3600, 'secret');
        $tampered = str_replace('report.pdf', 'confidential.pdf', $url);

        self::assertFalse(SignedURL::verify($tampered, 'secret'));
    }

    public function testVerifyTrueRegardlessOfQueryParamOrder(): void
    {
        $url = SignedURL::sign('https://example.com/download?file=report.pdf', 3600, 'secret');

        $parts = parse_url($url);
        parse_str($parts['query'], $query);
        $base = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];

        // Rebuild the query string with parameters in reverse key order.
        $reordered = $base . '?' . http_build_query(array_reverse($query, true));

        self::assertTrue(SignedURL::verify($reordered, 'secret'));
    }

    public function testVerifyFalseWhenSignatureIsMissing(): void
    {
        $url = SignedURL::sign('https://example.com/download/report.pdf', 3600, 'secret');
        $stripped = preg_replace('/&signature=[^&]+/', '', $url);

        self::assertFalse(SignedURL::verify($stripped, 'secret'));
    }

    public function testSignWorksWithRelativeUrls(): void
    {
        $url = SignedURL::sign('/download/report.pdf', 3600, 'secret');

        self::assertTrue(SignedURL::verify($url, 'secret'));
    }
}
