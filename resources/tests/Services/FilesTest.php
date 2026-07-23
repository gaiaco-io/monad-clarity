<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services;

use Gaia\Clarity\Services\Files;
use InvalidArgumentException;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final class FilesTest extends TestCase
{
    private string $storageDirectory;

    #[After]
    public function cleanUp(): void
    {
        if (!isset($this->storageDirectory) || !is_dir($this->storageDirectory)) {
            return;
        }

        foreach (glob($this->storageDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->storageDirectory);
    }

    private function filesystemAdapter(int $maxSizeBytes = 10_485_760): Files
    {
        $this->storageDirectory = sys_get_temp_dir() . '/clarity-files-test-' . bin2hex(random_bytes(8));

        return new Files(adapter: Files::ADAPTER_FILESYSTEM, basePath: $this->storageDirectory, maxSizeBytes: $maxSizeBytes);
    }

    private static function uploadedTextFile(string $content = 'hello world', string $clientFilename = 'note.txt'): UploadedFileInterface
    {
        return new UploadedFile(Stream::create($content), strlen($content), UPLOAD_ERR_OK, $clientFilename, 'text/plain');
    }

    public function testStoreSavesFileAndReturnsMetadata(): void
    {
        $files = $this->filesystemAdapter();

        $result = $files->store(self::uploadedTextFile('hello world', 'note.txt'));

        self::assertStringEndsWith('.txt', $result['path']);
        self::assertSame('text/plain', $result['mimeType']);
        self::assertSame(11, $result['size']);
        self::assertTrue($result['public']);
        self::assertTrue($files->exists($result['path']));
    }

    public function testStoreDetectsMimeTypeFromContentNotClientHeader(): void
    {
        $files = $this->filesystemAdapter();

        // Client claims image/png; content is plain text. A .txt extension with
        // text/plain content is legitimately allowed regardless of the (ignored) header.
        $upload = new UploadedFile(Stream::create('just text'), 9, UPLOAD_ERR_OK, 'file.txt', 'image/png');

        $result = $files->store($upload);

        self::assertSame('text/plain', $result['mimeType']);
    }

    public function testStoreRejectsContentMismatchedWithExtension(): void
    {
        $files = $this->filesystemAdapter();

        // .jpg extension, but the actual bytes are plain text — not a real JPEG.
        $upload = new UploadedFile(Stream::create('not actually a jpeg'), 19, UPLOAD_ERR_OK, 'photo.jpg', 'image/jpeg');

        $this->expectException(InvalidArgumentException::class);

        $files->store($upload);
    }

    public function testStoreRejectsDisallowedExtension(): void
    {
        $files = $this->filesystemAdapter();

        $upload = new UploadedFile(Stream::create('#!/bin/sh'), 9, UPLOAD_ERR_OK, 'script.sh', 'text/plain');

        $this->expectException(InvalidArgumentException::class);

        $files->store($upload);
    }

    public function testStoreRejectsOversizedFile(): void
    {
        $files = $this->filesystemAdapter(maxSizeBytes: 5);

        $this->expectException(InvalidArgumentException::class);

        $files->store(self::uploadedTextFile('this is longer than five bytes'));
    }

    public function testStoreRejectsFailedUpload(): void
    {
        $files = $this->filesystemAdapter();

        $upload = new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_NO_FILE, 'note.txt', 'text/plain');

        $this->expectException(RuntimeException::class);

        $files->store($upload);
    }

    public function testStoreMultipleStoresEachFile(): void
    {
        $files = $this->filesystemAdapter();

        $results = $files->storeMultiple([
            self::uploadedTextFile('first', 'a.txt'),
            self::uploadedTextFile('second', 'b.txt'),
        ]);

        self::assertCount(2, $results);
        self::assertTrue($files->exists($results[0]['path']));
        self::assertTrue($files->exists($results[1]['path']));
    }

    public function testPublicFileIsWorldReadable(): void
    {
        $files = $this->filesystemAdapter();

        $result = $files->store(self::uploadedTextFile(), public: true);

        self::assertSame(0644, fileperms($this->storageDirectory . '/' . $result['path']) & 0777);
    }

    public function testPrivateFileIsOwnerOnlyReadable(): void
    {
        $files = $this->filesystemAdapter();

        $result = $files->store(self::uploadedTextFile(), public: false);

        self::assertSame(0600, fileperms($this->storageDirectory . '/' . $result['path']) & 0777);
    }

    public function testDeleteRemovesStoredFile(): void
    {
        $files = $this->filesystemAdapter();
        $result = $files->store(self::uploadedTextFile());

        self::assertTrue($files->delete($result['path']));
        self::assertFalse($files->exists($result['path']));
    }

    public function testDeleteOfMissingFileIsIdempotent(): void
    {
        self::assertTrue($this->filesystemAdapter()->delete('never-existed.txt'));
    }

    public function testExistsReturnsFalseForUnknownPath(): void
    {
        self::assertFalse($this->filesystemAdapter()->exists('nope.txt'));
    }

    public function testConstructorRejectsUnknownAdapter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Files(adapter: 'ftp');
    }

    public function testConstructorRequiresBasePathForFilesystemAdapter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Files(adapter: Files::ADAPTER_FILESYSTEM);
    }

    public function testConstructorRequiresClientAndBucketForS3Adapter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Files(adapter: Files::ADAPTER_S3);
    }

    public function testS3AdapterStoresViaPutObjectWithCorrectVisibility(): void
    {
        $s3 = new FakeS3Client();
        $files = new Files(adapter: Files::ADAPTER_S3, s3Client: $s3, s3Bucket: 'my-bucket');

        $result = $files->store(self::uploadedTextFile('hello s3', 'note.txt'), public: false);

        self::assertArrayHasKey($result['path'], $s3->objects);
        self::assertSame('hello s3', $s3->objects[$result['path']]['Body']);
        self::assertSame('private', $s3->objects[$result['path']]['ACL']);
    }

    public function testS3AdapterDeleteAndExists(): void
    {
        $s3 = new FakeS3Client();
        $files = new Files(adapter: Files::ADAPTER_S3, s3Client: $s3, s3Bucket: 'my-bucket');

        $result = $files->store(self::uploadedTextFile());

        self::assertTrue($files->exists($result['path']));

        $files->delete($result['path']);

        self::assertFalse($files->exists($result['path']));
    }
}

/**
 * Minimal fake matching the real Aws\S3\S3Client method shapes Files actually calls
 * (putObject/deleteObject/doesObjectExist) — lets the S3 adapter be tested without the
 * AWS SDK or real S3 credentials.
 */
final class FakeS3Client
{
    /** @var array<string, array{Body: string, ContentType: string, ACL: string}> */
    public array $objects = [];

    public function putObject(array $args): void
    {
        $this->objects[$args['Key']] = [
            'Body' => $args['Body'],
            'ContentType' => $args['ContentType'],
            'ACL' => $args['ACL'],
        ];
    }

    public function deleteObject(array $args): void
    {
        unset($this->objects[$args['Key']]);
    }

    public function doesObjectExist(string $bucket, string $key): bool
    {
        return isset($this->objects[$key]);
    }
}
