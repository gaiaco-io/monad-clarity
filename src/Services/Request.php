<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services;

use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Incoming HTTP request. Input is parsed, never implicitly normalised or escaped —
 * normalisation happens only where explicitly requested, and output escaping is the
 * responsibility of whatever renders a value into a given context (View, Response).
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Request
{
    private ?array $decodedJson = null;
    private bool $jsonDecoded = false;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $input
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files
     * @param array<string, mixed>|null $jsonBag Pre-parsed JSON data bag, set by the
     *     Jsonify middleware when it ran. Null means Jsonify did not run — json() then
     *     lazy-parses $rawBody itself, per CrossRepoContracts.md §6.
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $input,
        private readonly array $server,
        private readonly array $cookies,
        private readonly array $files,
        private readonly string $rawBody,
        private readonly ?array $jsonBag = null,
        private readonly int $jsonMaxDepth = 512,
    ) {
    }

    /**
     * Build a Request from the current PHP superglobals.
     */
    public static function capture(): self
    {
        return self::fromArrays(
            query: $_GET,
            input: $_POST,
            server: $_SERVER,
            cookies: $_COOKIE,
            files: $_FILES,
            rawBody: (string) file_get_contents('php://input'),
        );
    }

    /**
     * Build a Request from explicit arrays, primarily for testing without superglobals.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $input
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files Raw $_FILES-shaped array.
     */
    public static function fromArrays(
        array $query = [],
        array $input = [],
        array $server = [],
        array $cookies = [],
        array $files = [],
        string $rawBody = '',
    ): self {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $path = rtrim((string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');

        return new self(
            method: $method,
            path: $path === '' ? '/' : $path,
            query: $query,
            input: $input,
            server: $server,
            cookies: $cookies,
            files: self::normalizeFiles($files),
            rawBody: $rawBody,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    /**
     * Read the JSON request body. With no $key, returns the fully decoded value (which
     * per Jsonify's contract may be any valid JSON value, not just an object/array).
     * With a $key, dot-notation navigates into a decoded object/array.
     *
     * @throws JsonException if the body is present but not valid JSON.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $decoded = $this->decodeJson();

        if ($key === null) {
            return $decoded ?? $default;
        }

        if (!is_array($decoded)) {
            return $default;
        }

        $value = $decoded;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function header(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));

        if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            return $this->server[$key] ?? null;
        }

        return $this->server['HTTP_' . $key] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function file(string $name): ?UploadedFileInterface
    {
        $file = $this->files[$name] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): ?string
    {
        return $this->header('User-Agent');
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->input);
    }

    public function toPsr7(): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        $psrRequest = $factory->createServerRequest($this->method, $this->uriString(), $this->server)
            ->withQueryParams($this->query)
            ->withParsedBody($this->input)
            ->withCookieParams($this->cookies)
            ->withUploadedFiles($this->files)
            ->withBody($factory->createStream($this->rawBody));

        foreach ($this->headerLines() as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        return $psrRequest;
    }

    public static function fromPsr7(ServerRequestInterface $psrRequest): static
    {
        $body = (string) $psrRequest->getBody();
        $server = $psrRequest->getServerParams();
        $server['REQUEST_METHOD'] ??= $psrRequest->getMethod();
        $server['REQUEST_URI'] ??= (string) $psrRequest->getUri();

        foreach ($psrRequest->getHeaders() as $name => $values) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
        }

        return new static(
            method: strtoupper($psrRequest->getMethod()),
            path: rtrim($psrRequest->getUri()->getPath(), '/') ?: '/',
            query: $psrRequest->getQueryParams(),
            input: (array) $psrRequest->getParsedBody(),
            server: $server,
            cookies: $psrRequest->getCookieParams(),
            files: self::flattenPsrUploadedFiles($psrRequest->getUploadedFiles()),
            rawBody: $body,
        );
    }

    /**
     * Return a copy of this request carrying a pre-parsed JSON data bag, as set by the
     * Jsonify middleware once it runs (Phase 5). Not part of the stable API surface yet.
     */
    public function withJsonBag(?array $bag): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->input,
            $this->server,
            $this->cookies,
            $this->files,
            $this->rawBody,
            $bag,
            $this->jsonMaxDepth,
        );
    }

    private function decodeJson(): mixed
    {
        if ($this->jsonBag !== null) {
            return $this->jsonBag;
        }

        if ($this->jsonDecoded) {
            return $this->decodedJson;
        }

        $this->jsonDecoded = true;

        if (trim($this->rawBody) === '') {
            return $this->decodedJson = null;
        }

        return $this->decodedJson = json_decode(
            $this->rawBody,
            associative: true,
            depth: $this->jsonMaxDepth,
            flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
        );
    }

    private function uriString(): string
    {
        $host = (string) ($this->server['HTTP_HOST'] ?? '');
        $https = (string) ($this->server['HTTPS'] ?? '');
        $authority = $host !== '' ? (($https !== '' && $https !== 'off') ? 'https' : 'http') . '://' . $host : '';

        $query = http_build_query($this->query);

        return $authority . $this->path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * @return array<string, string>
     */
    private function headerLines(): array
    {
        $headers = [];

        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[str_replace('_', '-', $key)] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * Normalise PHP's raw $_FILES structure (including the multi-dimensional shape used
     * for `name="photos[]"` inputs) into name => UploadedFileInterface|list<UploadedFileInterface>.
     *
     * @param array<string, mixed> $files
     * @return array<string, UploadedFileInterface|list<UploadedFileInterface>>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $descriptor) {
            if (!is_array($descriptor['name'] ?? null)) {
                $normalized[$field] = self::buildUploadedFile(
                    $descriptor['tmp_name'] ?? '',
                    (int) ($descriptor['size'] ?? 0),
                    (int) ($descriptor['error'] ?? UPLOAD_ERR_NO_FILE),
                    $descriptor['name'] ?? null,
                    $descriptor['type'] ?? null,
                );

                continue;
            }

            $entries = [];

            foreach ($descriptor['name'] as $index => $name) {
                $entries[] = self::buildUploadedFile(
                    $descriptor['tmp_name'][$index] ?? '',
                    (int) ($descriptor['size'][$index] ?? 0),
                    (int) ($descriptor['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                    $name,
                    $descriptor['type'][$index] ?? null,
                );
            }

            $normalized[$field] = $entries;
        }

        return $normalized;
    }

    private static function buildUploadedFile(
        string $tmpName,
        int $size,
        int $error,
        ?string $clientFilename,
        ?string $clientMediaType
    ): UploadedFileInterface {
        return new UploadedFile($tmpName, $size, $error, $clientFilename, $clientMediaType);
    }

    /**
     * @param array<string, mixed> $uploadedFiles
     * @return array<string, UploadedFileInterface|list<UploadedFileInterface>>
     */
    private static function flattenPsrUploadedFiles(array $uploadedFiles): array
    {
        $flattened = [];

        foreach ($uploadedFiles as $key => $value) {
            $flattened[$key] = $value;
        }

        return $flattened;
    }
}
