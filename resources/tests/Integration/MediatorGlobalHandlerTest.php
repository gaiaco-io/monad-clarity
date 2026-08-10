<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Integration;

use Monad\Clarity\Services\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Runs a real HTTP round trip against a fixture (fixtures/mediator-exception-server.php)
 * that calls Mediator::register() itself, so this exercises the actual global
 * set_exception_handler wiring end to end — not just handleException() called directly
 * with a $request unit tests hand it by construction. This is what proves the fix for
 * the reported bug: register()'s closure now negotiates format from the real request
 * instead of always rendering JSON.
 */
final class MediatorGlobalHandlerTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 18944;

    /** @var resource|null */
    private static $serverProcess = null;

    public static function setUpBeforeClass(): void
    {
        $fixture = __DIR__ . '/../fixtures/mediator-exception-server.php';

        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', self::HOST . ':' . self::PORT, $fixture],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        self::waitForServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
    }

    private static function waitForServer(): void
    {
        $deadline = microtime(true) + 3;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        self::fail('Fixture HTTP server did not start in time.');
    }

    private static function uri(): string
    {
        return 'http://' . self::HOST . ':' . self::PORT . '/dashboard';
    }

    public function testBrowserPageRequestGetsHtmlNotRawJson(): void
    {
        $response = (new HttpClient())->get(self::uri(), [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);

        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<!doctype html', $body);
        self::assertStringNotContainsString('{"error"', $body);
        self::assertStringNotContainsString('hunter2', $body);
    }

    public function testApiRequestStillGetsJson(): void
    {
        $response = (new HttpClient())->get(self::uri(), ['Accept' => 'application/json']);

        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('An unexpected error occurred.', $decoded['error']);
    }
}
