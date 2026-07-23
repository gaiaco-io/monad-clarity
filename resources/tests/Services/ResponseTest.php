<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services;

use Gaia\Clarity\Services\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResponseTest extends TestCase
{
    private string $tempFile;

    #[After]
    public function cleanUpTempFile(): void
    {
        if (isset($this->tempFile) && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testJsonEncodesDataDirectlyWithoutHtmlEscaping(): void
    {
        $response = Response::json(['name' => 'Marshal & <Co>']);

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('{"name":"Marshal & <Co>"}', $response->content());
    }

    public function testJsonAcceptsCustomStatus(): void
    {
        self::assertSame(201, Response::json(['id' => 1], 201)->status());
    }

    public function testJsonDoesNotWrapDataInAnEnvelope(): void
    {
        // Legacy behaviour wrapped everything in {"status":..,"message":..,"data":..}.
        // The new contract sends exactly what's given.
        $response = Response::json(['a' => 1]);

        self::assertSame(['a' => 1], json_decode($response->content(), true));
    }

    public function testHtmSetsHtmlContentType(): void
    {
        $response = Response::htm('<p>hi</p>');

        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('<p>hi</p>', $response->content());
    }

    public function testTextSetsPlainTextContentType(): void
    {
        $response = Response::text('hello');

        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('hello', $response->content());
    }

    public function testRedirectSetsLocationHeaderAndStatus(): void
    {
        $response = Response::redirect('/dashboard');

        self::assertSame(302, $response->status());
        self::assertSame('/dashboard', $response->header('Location'));
    }

    public function testRedirectAcceptsCustomStatus(): void
    {
        self::assertSame(301, Response::redirect('/new', 301)->status());
    }

    public function testRedirectRejectsInvalidStatusCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::redirect('/dashboard', 200);
    }

    public function testNoContentReturns204WithEmptyBody(): void
    {
        $response = Response::noContent();

        self::assertSame(204, $response->status());
        self::assertSame('', $response->content());
    }

    public function testDownloadThrowsWhenFileMissing(): void
    {
        $this->expectException(RuntimeException::class);

        Response::download('/no/such/file.pdf');
    }

    public function testDownloadSetsContentDispositionAndLength(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'clarity-download-');
        file_put_contents($this->tempFile, 'file contents');

        $response = Response::download($this->tempFile, 'report.txt');

        self::assertStringContainsString('attachment; filename="report.txt"', $response->header('Content-Disposition'));
        self::assertSame((string) strlen('file contents'), $response->header('Content-Length'));
        self::assertSame('file contents', $response->content());
    }

    public function testWithHeaderReturnsNewInstanceLeavingOriginalUnchanged(): void
    {
        $original = Response::htm('body');
        $withHeader = $original->withHeader('X-Custom', 'value');

        self::assertNull($original->header('X-Custom'));
        self::assertSame('value', $withHeader->header('X-Custom'));
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $response = Response::htm('body');

        self::assertSame('text/html; charset=utf-8', $response->header('content-type'));
    }

    public function testStreamInvokesCallbackOnSend(): void
    {
        $called = false;
        $response = Response::stream(function () use (&$called) {
            $called = true;
            echo 'streamed';
        });

        self::assertSame('', $response->content());

        ob_start();
        $response->send();
        $output = ob_get_clean();

        self::assertTrue($called);
        self::assertSame('streamed', $output);
    }

    public function testSendEmitsBodyForNonStreamResponse(): void
    {
        ob_start();
        Response::text('hello world')->send();
        $output = ob_get_clean();

        self::assertSame('hello world', $output);
    }

    public function testToPsr7CarriesStatusHeadersAndBody(): void
    {
        $psr7 = Response::json(['a' => 1], 201)->toPsr7();

        self::assertSame(201, $psr7->getStatusCode());
        self::assertSame('application/json', $psr7->getHeaderLine('Content-Type'));
        self::assertSame('{"a":1}', (string) $psr7->getBody());
    }
}
