<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Utils;

use Gaia\Clarity\Utils\Encryption;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptionTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $key = Encryption::generateKey();
        $ciphertext = Encryption::encrypt('the eagle flies at midnight', $key);

        self::assertSame('the eagle flies at midnight', Encryption::decrypt($ciphertext, $key));
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $key = Encryption::generateKey();

        self::assertNotSame(
            Encryption::encrypt('same plaintext', $key),
            Encryption::encrypt('same plaintext', $key)
        );
    }

    public function testDecryptFailsWithWrongKey(): void
    {
        $ciphertext = Encryption::encrypt('secret', Encryption::generateKey());

        $this->expectException(RuntimeException::class);

        Encryption::decrypt($ciphertext, Encryption::generateKey());
    }

    public function testDecryptFailsWhenCiphertextIsTampered(): void
    {
        $key = Encryption::generateKey();
        $ciphertext = base64_decode(Encryption::encrypt('secret payload', $key), true);

        // Flip the last byte of the ciphertext body (after the 12-byte IV + 16-byte tag).
        $tampered = $ciphertext;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0xFF);

        $this->expectException(RuntimeException::class);

        Encryption::decrypt(base64_encode($tampered), $key);
    }

    public function testDecryptFailsWhenAuthenticationTagIsTampered(): void
    {
        $key = Encryption::generateKey();
        $decoded = base64_decode(Encryption::encrypt('secret payload', $key), true);

        // Tag occupies bytes [12, 28) — flip one bit inside it.
        $tampered = $decoded;
        $tampered[15] = chr(ord($tampered[15]) ^ 0xFF);

        $this->expectException(RuntimeException::class);

        Encryption::decrypt(base64_encode($tampered), $key);
    }

    public function testDecryptFailsWhenIvIsTampered(): void
    {
        $key = Encryption::generateKey();
        $decoded = base64_decode(Encryption::encrypt('secret payload', $key), true);

        $tampered = $decoded;
        $tampered[0] = chr(ord($tampered[0]) ^ 0xFF);

        $this->expectException(RuntimeException::class);

        Encryption::decrypt(base64_encode($tampered), $key);
    }

    public function testDecryptRejectsMalformedPayload(): void
    {
        $this->expectException(RuntimeException::class);

        Encryption::decrypt('not-valid-base64-ciphertext!!', Encryption::generateKey());
    }

    public function testEncryptRejectsWrongKeyLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Encryption::encrypt('plaintext', 'too-short-key');
    }

    public function testDecryptRejectsWrongKeyLength(): void
    {
        $ciphertext = Encryption::encrypt('plaintext', Encryption::generateKey());

        $this->expectException(InvalidArgumentException::class);

        Encryption::decrypt($ciphertext, 'too-short-key');
    }

    public function testGenerateKeyReturns32Bytes(): void
    {
        self::assertSame(32, strlen(Encryption::generateKey()));
    }
}
