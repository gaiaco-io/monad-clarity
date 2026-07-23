<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services;

use Gaia\Clarity\Services\HttpClient;
use Gaia\Clarity\Services\HttpClientException;
use PHPUnit\Framework\TestCase;

/**
 * Runs a real HTTP round trip against a local PHP built-in server fixture
 * (resources/tests/fixtures/http-echo-server.php) so these tests never depend on the
 * real internet or a third-party service.
 */
final class HttpClientTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 18943;

    /** @var resource|null */
    private static $serverProcess = null;

    public static function setUpBeforeClass(): void
    {
        $fixture = __DIR__ . '/../fixtures/http-echo-server.php';

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

    private static function uri(string $path): string
    {
        return 'http://' . self::HOST . ':' . self::PORT . $path;
    }

    public function testGetReturnsResponseWithExpectedStatusAndBody(): void
    {
        $response = (new HttpClient())->get(self::uri('/status/200'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('status-200', (string) $response->getBody());
    }

    public function testGetReturnsCustomStatusCode(): void
    {
        $response = (new HttpClient())->get(self::uri('/status/404'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testPostSendsBodyAndHeaders(): void
    {
        $response = (new HttpClient())->post(self::uri('/echo'), 'hello world', ['X-Test' => 'clarity']);

        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('POST', $decoded['method']);
        self::assertSame('hello world', $decoded['body']);
        self::assertSame('clarity', $decoded['headers']['X-TEST']);
    }

    public function testPostJsonEncodesBodyAndSetsContentType(): void
    {
        $response = (new HttpClient())->postJson(self::uri('/echo'), ['name' => 'Marshal']);

        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('{"name":"Marshal"}', $decoded['body']);
        self::assertSame('application/json', $decoded['headers']['CONTENT-TYPE']);
    }

    public function testResponseHeadersAreCaptured(): void
    {
        $response = (new HttpClient())->get(self::uri('/echo'));

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testFollowsRedirectsByDefault(): void
    {
        $response = (new HttpClient())->get(self::uri('/redirect'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('redirected', (string) $response->getBody());
    }

    public function testDoesNotFollowRedirectsWhenDisabled(): void
    {
        $response = (new HttpClient(maxRedirects: 0))->get(self::uri('/redirect'));

        self::assertSame(302, $response->getStatusCode());
    }

    public function testThrowsOnConnectionRefused(): void
    {
        // Port 1 is a privileged port almost never bound to a listener — fast, reliable
        // "connection refused" without any real DNS lookup or network dependency.
        $this->expectException(HttpClientException::class);

        (new HttpClient())->get('http://127.0.0.1:1/');
    }

    public function testThrowsOnTimeout(): void
    {
        $this->expectException(HttpClientException::class);

        (new HttpClient(timeoutSeconds: 1))->get(self::uri('/slow'));
    }

    public function testWithTimeoutSecondsActuallyChangesTheEnforcedTimeout(): void
    {
        // Constructed with a generous timeout, then narrowed via withTimeoutSeconds() —
        // proves the clone's mutated value is what curl actually enforces, not just a
        // copied object that still behaves like the original.
        $client = (new HttpClient(timeoutSeconds: 30))->withTimeoutSeconds(1);

        $this->expectException(HttpClientException::class);

        $client->get(self::uri('/slow'));
    }

    public function testWithTimeoutSecondsPreservesASubclassAddedProperty(): void
    {
        // LLM adapters call withTimeoutSeconds() on whatever HttpClient they were given,
        // including test fakes that subclass HttpClient and carry their own state (e.g. a
        // canned responder). Reconstructing via `new static(...)` would only ever pass
        // HttpClient's own four constructor parameters, silently dropping that state —
        // this proves the clone-based implementation carries it through instead.
        $subclass = new class (99) extends HttpClient {
            public function __construct(public readonly int $marker)
            {
                parent::__construct();
            }
        };

        $copy = $subclass->withTimeoutSeconds(5);

        self::assertInstanceOf($subclass::class, $copy);
        self::assertSame(99, $copy->marker);
    }
}
