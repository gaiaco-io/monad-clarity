<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services;

use finfo;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * File upload handling and storage, with pluggable adapters (filesystem default at
 * `/storage/userfiles`, S3 optional) — a pure storage service with no database coupling.
 * Any metadata an application wants to persist about a stored file (owner, parent
 * record, ordering, ...) is the application's own table and concern, not Clarity's; no
 * table for this is defined in `DDL.sql`, unlike `sessions`/`caches`.
 *
 * MIME type is always detected from file content (`fileinfo`), never from the
 * client-supplied `Content-Type` — that header is exactly as trustworthy as a filename
 * extension, i.e. not at all (ReleaseNotes §19.2.3). An uploaded file must pass both the
 * extension allowlist AND a content-sniffed MIME type consistent with that extension.
 *
 * The S3 adapter accepts any object exposing putObject/deleteObject/doesObjectExist —
 * the real `Aws\S3\S3Client` method shapes — rather than depending on aws/aws-sdk-php,
 * so using the real SDK needs no adapter translation, and tests can use a plain fake.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Files
{
    public const ADAPTER_FILESYSTEM = 'filesystem';
    public const ADAPTER_S3 = 's3';

    private const DEFAULT_MAX_SIZE_BYTES = 10_485_760;

    /** @var array<string, list<string>> extension => content-sniffed MIME types it may be */
    private const DEFAULT_ALLOWED_EXTENSIONS = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    /**
     * @param array<string, list<string>> $allowedExtensions
     */
    public function __construct(
        private readonly string $adapter,
        private readonly ?string $basePath = null,
        private readonly ?object $s3Client = null,
        private readonly ?string $s3Bucket = null,
        private readonly array $allowedExtensions = self::DEFAULT_ALLOWED_EXTENSIONS,
        private readonly int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES,
    ) {
        if (!in_array($adapter, [self::ADAPTER_FILESYSTEM, self::ADAPTER_S3], true)) {
            throw new InvalidArgumentException(sprintf('Unknown storage adapter "%s".', $adapter));
        }

        if ($adapter === self::ADAPTER_FILESYSTEM && $basePath === null) {
            throw new InvalidArgumentException('Filesystem adapter requires $basePath.');
        }

        if ($adapter === self::ADAPTER_S3 && ($s3Client === null || $s3Bucket === null)) {
            throw new InvalidArgumentException('S3 adapter requires $s3Client and $s3Bucket.');
        }
    }

    /**
     * @return array{path: string, mimeType: string, size: int, public: bool}
     */
    public function store(UploadedFileInterface $file, bool $public = true, ?string $directory = null): array
    {
        self::assertUploadOk($file);

        $contents = self::readContents($file);
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';
        $extension = self::extensionOf((string) $file->getClientFilename());

        $this->assertAllowedExtensionAndMime($extension, $mimeType);
        $this->assertWithinSizeLimit($file->getSize());

        $path = ($directory !== null ? trim($directory, '/') . '/' : '') . self::generateSafeName($extension);

        match ($this->adapter) {
            self::ADAPTER_FILESYSTEM => $this->storeToFilesystem($file, $path, $public),
            self::ADAPTER_S3 => $this->storeToS3($contents, $path, $mimeType, $public),
        };

        $stored = ['path' => $path, 'mimeType' => $mimeType, 'size' => strlen($contents), 'public' => $public];

        Event::dispatch(Event::FILE_UPLOADED, $stored);

        return $stored;
    }

    /**
     * @param list<UploadedFileInterface> $files
     * @return list<array{path: string, mimeType: string, size: int, public: bool}>
     */
    public function storeMultiple(array $files, bool $public = true, ?string $directory = null): array
    {
        return array_map(fn (UploadedFileInterface $file) => $this->store($file, $public, $directory), $files);
    }

    public function delete(string $path): bool
    {
        return match ($this->adapter) {
            self::ADAPTER_FILESYSTEM => self::deleteFromFilesystem($this->absolutePath($path)),
            self::ADAPTER_S3 => $this->deleteFromS3($path),
        };
    }

    public function exists(string $path): bool
    {
        return match ($this->adapter) {
            self::ADAPTER_FILESYSTEM => is_file($this->absolutePath($path)),
            self::ADAPTER_S3 => (bool) $this->s3Client->doesObjectExist($this->s3Bucket, $path),
        };
    }

    private function storeToFilesystem(UploadedFileInterface $file, string $relativePath, bool $public): void
    {
        $absolutePath = $this->absolutePath($relativePath);
        $directory = dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create storage directory "%s".', $directory));
        }

        // moveTo() is upload-aware: move_uploaded_file() for a real HTTP upload (the
        // atomic-enough move that function exists for), or a stream copy + rename
        // otherwise — e.g. a file built directly from a stream, as in tests.
        $file->moveTo($absolutePath);
        chmod($absolutePath, $public ? 0644 : 0600);
    }

    private function storeToS3(string $contents, string $path, string $mimeType, bool $public): void
    {
        $this->s3Client->putObject([
            'Bucket' => $this->s3Bucket,
            'Key' => $path,
            'Body' => $contents,
            'ContentType' => $mimeType,
            'ACL' => $public ? 'public-read' : 'private',
        ]);
    }

    private static function deleteFromFilesystem(string $absolutePath): bool
    {
        return !is_file($absolutePath) || unlink($absolutePath);
    }

    private function deleteFromS3(string $path): bool
    {
        $this->s3Client->deleteObject(['Bucket' => $this->s3Bucket, 'Key' => $path]);

        return true;
    }

    private function absolutePath(string $relativePath): string
    {
        return rtrim((string) $this->basePath, '/') . '/' . ltrim($relativePath, '/');
    }

    private static function readContents(UploadedFileInterface $file): string
    {
        $stream = $file->getStream();
        $stream->rewind();

        return $stream->getContents();
    }

    private static function assertUploadOk(UploadedFileInterface $file): void
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf('Upload failed with error code %d.', $file->getError()));
        }
    }

    private static function extensionOf(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    private function assertAllowedExtensionAndMime(string $extension, string $mimeType): void
    {
        if (!isset($this->allowedExtensions[$extension])) {
            throw new InvalidArgumentException(sprintf('File extension ".%s" is not allowed.', $extension));
        }

        if (!in_array($mimeType, $this->allowedExtensions[$extension], true)) {
            throw new InvalidArgumentException(
                sprintf('File content (detected as "%s") does not match its ".%s" extension.', $mimeType, $extension)
            );
        }
    }

    private function assertWithinSizeLimit(?int $size): void
    {
        if ($size === null || $size <= 0 || $size > $this->maxSizeBytes) {
            throw new InvalidArgumentException(sprintf('File size must be between 1 and %d bytes.', $this->maxSizeBytes));
        }
    }

    private static function generateSafeName(string $extension): string
    {
        return bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    }
}
