<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services;

use Gaia\Clarity\Services\Request;
use JsonException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class RequestTest extends TestCase
{
    public function testMethodAndPathAreParsedFromServer(): void
    {
        $request = Request::fromArrays(server: [
            'REQUEST_METHOD' => 'post',
            'REQUEST_URI' => '/users/42?foo=bar',
        ]);

        self::assertSame('POST', $request->method());
        self::assertSame('/users/42', $request->path());
    }

    public function testRootPathNormalizesToSlash(): void
    {
        $request = Request::fromArrays(server: ['REQUEST_URI' => '/']);

        self::assertSame('/', $request->path());
    }

    public function testQueryAndInputAccessors(): void
    {
        $request = Request::fromArrays(query: ['page' => '2'], input: ['email' => 'a@b.com']);

        self::assertSame('2', $request->query('page'));
        self::assertNull($request->query('missing'));
        self::assertSame('fallback', $request->query('missing', 'fallback'));
        self::assertSame('a@b.com', $request->input('email'));
    }

    public function testAllMergesQueryAndInput(): void
    {
        $request = Request::fromArrays(query: ['a' => '1'], input: ['b' => '2']);

        self::assertSame(['a' => '1', 'b' => '2'], $request->all());
    }

    public function testHeaderReadsFromServerSuperglobalConvention(): void
    {
        $request = Request::fromArrays(server: [
            'HTTP_AUTHORIZATION' => 'Bearer abc',
            'CONTENT_TYPE' => 'application/json',
        ]);

        self::assertSame('Bearer abc', $request->header('Authorization'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertNull($request->header('X-Missing'));
    }

    public function testCookieAccessor(): void
    {
        $request = Request::fromArrays(cookies: ['mid' => 'abc123']);

        self::assertSame('abc123', $request->cookie('mid'));
        self::assertNull($request->cookie('missing'));
    }

    public function testIpAndUserAgent(): void
    {
        $request = Request::fromArrays(server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'clarity-test/1.0',
        ]);

        self::assertSame('203.0.113.9', $request->ip());
        self::assertSame('clarity-test/1.0', $request->userAgent());
    }

    public function testJsonReturnsFullyDecodedValueWithoutKey(): void
    {
        $request = Request::fromArrays(rawBody: '{"customer":{"name":"Marshal"}}');

        self::assertSame(['customer' => ['name' => 'Marshal']], $request->json());
    }

    public function testJsonDotNotationNavigatesNestedKeys(): void
    {
        $request = Request::fromArrays(rawBody: '{"customer":{"name":"Marshal"}}');

        self::assertSame('Marshal', $request->json('customer.name'));
    }

    public function testJsonReturnsDefaultForMissingKey(): void
    {
        $request = Request::fromArrays(rawBody: '{"customer":{"name":"Marshal"}}');

        self::assertSame('fallback', $request->json('customer.email', 'fallback'));
    }

    public function testJsonReturnsNullForEmptyBody(): void
    {
        $request = Request::fromArrays(rawBody: '');

        self::assertNull($request->json());
        self::assertSame('fallback', $request->json('anything', 'fallback'));
    }

    public function testJsonThrowsOnMalformedBody(): void
    {
        $request = Request::fromArrays(rawBody: '{not valid json');

        $this->expectException(JsonException::class);

        $request->json();
    }

    public function testJsonAcceptsAnyValidJsonValueNotJustObjects(): void
    {
        $request = Request::fromArrays(rawBody: '[1,2,3]');

        self::assertSame([1, 2, 3], $request->json());
    }

    public function testWithJsonBagPreemptsLazyParsingOfRawBody(): void
    {
        $request = Request::fromArrays(rawBody: 'not json at all')
            ->withJsonBag(['from' => 'jsonify']);

        self::assertSame('jsonify', $request->json('from'));
    }

    public function testFileReturnsUploadedFileInterfaceForSingleUpload(): void
    {
        $request = Request::fromArrays(files: [
            'avatar' => [
                'name' => 'photo.png',
                'type' => 'image/png',
                'tmp_name' => '/tmp/php1234',
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
            ],
        ]);

        $file = $request->file('avatar');

        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame('photo.png', $file->getClientFilename());
        self::assertSame(1024, $file->getSize());
    }

    public function testFileReturnsNullWhenFieldIsMultiUploadArray(): void
    {
        $request = Request::fromArrays(files: [
            'photos' => [
                'name' => ['a.png', 'b.png'],
                'type' => ['image/png', 'image/png'],
                'tmp_name' => ['/tmp/a', '/tmp/b'],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [10, 20],
            ],
        ]);

        self::assertNull($request->file('photos'));
    }

    public function testFileReturnsNullWhenFieldMissing(): void
    {
        self::assertNull(Request::fromArrays()->file('avatar'));
    }

    public function testToPsr7RoundTripsThroughFromPsr7(): void
    {
        $request = Request::fromArrays(
            query: ['page' => '2'],
            input: ['email' => 'a@b.com'],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/users?page=2',
                'HTTP_HOST' => 'example.test',
                'HTTP_X_TEST' => 'header-value',
            ],
            cookies: ['mid' => 'abc'],
        );

        $psr7 = $request->toPsr7();

        self::assertSame('POST', $psr7->getMethod());
        self::assertSame('example.test', $psr7->getUri()->getHost());
        self::assertSame(['page' => '2'], $psr7->getQueryParams());
        self::assertSame('header-value', $psr7->getHeaderLine('X-Test'));

        $roundTripped = Request::fromPsr7($psr7);

        self::assertSame('POST', $roundTripped->method());
        self::assertSame('/users', $roundTripped->path());
        self::assertSame('2', $roundTripped->query('page'));
        self::assertSame('header-value', $roundTripped->header('X-Test'));
    }
}
