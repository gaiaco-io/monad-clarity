<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\Mail;

use InvalidArgumentException;
use Monad\Clarity\Services\Mail\Address;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    public function testHoldsEmailAndOptionalName(): void
    {
        $address = new Address('someone@example.com', 'Someone');

        self::assertSame('someone@example.com', $address->email);
        self::assertSame('Someone', $address->name);
    }

    public function testTrimsAndTreatsBlankNameAsAbsent(): void
    {
        $address = new Address('  someone@example.com  ', '   ');

        self::assertSame('someone@example.com', $address->email);
        self::assertNull($address->name);
    }

    public function testRejectsMalformedAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"not-an-address" is not a valid email address.');

        new Address('not-an-address');
    }

    /**
     * Security-critical: a newline in either field forges a header. See Header::assertNoInjection.
     */
    #[DataProvider('injectionVectors')]
    public function testRejectsHeaderInjection(string $email, ?string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line break or NUL byte');

        new Address($email, $name);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function injectionVectors(): iterable
    {
        yield 'LF in name' => ['someone@example.com', "Someone\nBcc: attacker@evil.test"];
        yield 'CR in name' => ['someone@example.com', "Someone\rBcc: attacker@evil.test"];
        yield 'CRLF in name' => ['someone@example.com', "Someone\r\nBcc: attacker@evil.test"];
        yield 'NUL in name' => ['someone@example.com', "Someone\0Bcc: attacker@evil.test"];
        yield 'LF in email' => ["someone@example.com\nBcc: attacker@evil.test", null];
    }

    public function testDomainIsLowercased(): void
    {
        self::assertSame('example.com', (new Address('Someone@EXAMPLE.com'))->domain());
    }

    public function testFormatsBareAddressWhenUnnamed(): void
    {
        self::assertSame('someone@example.com', (new Address('someone@example.com'))->format());
    }

    public function testFormatsNameAndAddress(): void
    {
        self::assertSame(
            'Someone <someone@example.com>',
            (new Address('someone@example.com', 'Someone'))->format()
        );
    }

    public function testQuotesDisplayNameContainingSpecials(): void
    {
        self::assertSame(
            '"Doe, Jane" <jane@example.com>',
            (new Address('jane@example.com', 'Doe, Jane'))->format()
        );
    }

    public function testEscapesQuotesInsideDisplayName(): void
    {
        self::assertSame(
            '"Jane \"JJ\" Doe" <jane@example.com>',
            (new Address('jane@example.com', 'Jane "JJ" Doe'))->format()
        );
    }
}
