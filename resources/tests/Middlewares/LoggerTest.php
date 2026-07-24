<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Middlewares;

use DateTimeZone;
use Monad\Clarity\Middlewares\Logger;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use RuntimeException;

final class LoggerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/clarity-logger-test-' . bin2hex(random_bytes(8));
    }

    #[After]
    public function cleanUpLogDirectory(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    private function path(string $filename = 'app.log'): string
    {
        return $this->directory . '/' . $filename;
    }

    public function testLogWritesExpectedLineToFile(): void
    {
        $logger = new Logger(channel: 'app', path: $this->path());

        $logger->error('Something broke');

        $contents = file_get_contents($this->path());

        self::assertStringContainsString('app.ERROR: Something broke', $contents);
    }

    public function testMessagePlaceholdersAreInterpolatedFromContext(): void
    {
        $logger = new Logger(channel: 'app', path: $this->path());

        $logger->info('User {id} logged in', ['id' => 42]);

        self::assertStringContainsString('User 42 logged in', file_get_contents($this->path()));
    }

    public function testSensitiveContextValuesAreRedacted(): void
    {
        $logger = new Logger(channel: 'app', path: $this->path());

        $logger->warning('Login attempt', ['username' => 'marshal', 'password' => 'hunter2']);

        $contents = file_get_contents($this->path());

        self::assertStringContainsString('marshal', $contents);
        self::assertStringNotContainsString('hunter2', $contents);
        self::assertStringContainsString('[REDACTED]', $contents);
    }

    public function testRequestIdAndUserIdAreTopLevelNotNestedInContext(): void
    {
        $logger = new Logger(channel: 'app', path: $this->path(), json: true);

        $logger->info('Request handled', ['request_id' => 'req-123', 'user_id' => 'user-456']);

        $entry = json_decode(trim(file_get_contents($this->path())), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('req-123', $entry['request_id']);
        self::assertSame('user-456', $entry['user_id']);
        self::assertArrayNotHasKey('request_id', $entry['context']);
        self::assertArrayNotHasKey('user_id', $entry['context']);
    }

    public function testExceptionContextIsDescribedInOutput(): void
    {
        $logger = new Logger(channel: 'app', path: $this->path(), json: true);

        $logger->critical('Unhandled exception', ['exception' => new RuntimeException('boom')]);

        $entry = json_decode(trim(file_get_contents($this->path())), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(RuntimeException::class, $entry['exception']['class']);
        self::assertSame('boom', $entry['exception']['message']);
    }

    public function testJsonModeProducesOneValidJsonObjectPerLine(): void
    {
        $logger = new Logger(channel: 'db', path: $this->path('db.log'), json: true);

        $logger->error('Query failed');
        $logger->error('Another failure');

        $lines = array_filter(explode(PHP_EOL, file_get_contents($this->path('db.log'))));

        self::assertCount(2, $lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('db', $decoded['channel']);
            self::assertSame('ERROR', $decoded['level']);
        }
    }

    public function testTimestampUsesConfiguredTimezone(): void
    {
        $logger = new Logger(channel: 'app', path: $this->path(), json: true, timezone: new DateTimeZone('+05:00'));

        $logger->info('Timestamped');

        $entry = json_decode(trim(file_get_contents($this->path())), true, flags: JSON_THROW_ON_ERROR);

        self::assertStringEndsWith('+05:00', $entry['timestamp']);
    }

    public function testUnknownLogLevelThrows(): void
    {
        $logger = new Logger(channel: 'app', path: $this->path());

        $this->expectException(InvalidArgumentException::class);

        $logger->log('not-a-real-level', 'message');
    }

    public function testWritingCreatesMissingLogDirectory(): void
    {
        $nested = $this->directory . '/nested/deeper';
        $logger = new Logger(channel: 'app', path: $nested . '/app.log');

        $logger->info('Created on demand');

        self::assertFileExists($nested . '/app.log');

        // Clean up the extra nesting this test created.
        unlink($nested . '/app.log');
        rmdir($nested);
        rmdir($this->directory . '/nested');
    }

    public function testRotatesWhenFileExceedsThreshold(): void
    {
        $logger = new Logger(channel: 'app', path: $this->path(), maxBytesBeforeRotation: 10, maxRotatedFiles: 2);

        $logger->info('first entry, long enough to exceed the tiny rotation threshold');
        $logger->info('second entry');

        self::assertFileExists($this->path() . '.1');
        self::assertStringContainsString('second entry', file_get_contents($this->path()));
        self::assertStringContainsString('first entry', file_get_contents($this->path() . '.1'));

        unlink($this->path() . '.1');
    }
}
