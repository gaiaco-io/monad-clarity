<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Console;

use Monad\Clarity\Console\Arguments;
use PHPUnit\Framework\TestCase;

final class ArgumentsTest extends TestCase
{
    public function testParsesPositionalArguments(): void
    {
        $arguments = Arguments::parse(['UserController', 'extra']);

        self::assertSame('UserController', $arguments->argument(0));
        self::assertSame('extra', $arguments->argument(1));
        self::assertSame(['UserController', 'extra'], $arguments->allArguments());
    }

    public function testMissingArgumentReturnsDefault(): void
    {
        $arguments = Arguments::parse([]);

        self::assertNull($arguments->argument(0));
        self::assertSame('fallback', $arguments->argument(0, 'fallback'));
    }

    public function testParsesKeyValueOptions(): void
    {
        $arguments = Arguments::parse(['--file=seed.php', '--steps=2']);

        self::assertSame('seed.php', $arguments->option('file'));
        self::assertSame('2', $arguments->option('steps'));
        self::assertTrue($arguments->hasOption('file'));
        self::assertFalse($arguments->hasOption('missing'));
    }

    public function testParsesBooleanFlagOptions(): void
    {
        $arguments = Arguments::parse(['--force']);

        self::assertTrue($arguments->option('force'));
        self::assertTrue($arguments->hasOption('force'));
    }

    public function testMissingOptionReturnsDefault(): void
    {
        $arguments = Arguments::parse([]);

        self::assertNull($arguments->option('file'));
        self::assertSame('default.php', $arguments->option('file', 'default.php'));
    }

    public function testMixesPositionalArgumentsAndOptions(): void
    {
        $arguments = Arguments::parse(['database/migrations/x.sql', '--context=reporting']);

        self::assertSame('database/migrations/x.sql', $arguments->argument(0));
        self::assertSame('reporting', $arguments->option('context'));
    }

    public function testRawTokensPreservesEveryTokenUnsplit(): void
    {
        $tokens = ['--filter=Foo', '--group=slow'];

        self::assertSame($tokens, Arguments::parse($tokens)->rawTokens());
    }
}
