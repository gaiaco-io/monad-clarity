<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

use InvalidArgumentException;

/**
 * The rules for putting an application-supplied value into a mail header: reject what would
 * forge one, and encode what cannot be written literally.
 *
 * Both halves exist because `Message` is assembled from user data — a display name, a
 * subject carrying a username, a reference the customer typed. Headers are separated by
 * CRLF, so a single unescaped newline in any of those turns one header into two, and the
 * second is entirely the attacker's: a `Bcc:` of their choosing, a rewritten `Reply-To`, a
 * forged `From`. That defeats §2.12's Bcc guarantee by a route MimeMessage alone cannot
 * close, which is why the guard lives at construction of every value object rather than at
 * the point a header is finally written.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Header
{
    /**
     * Characters that terminate or split a header. NUL is included because it truncates in
     * C-implemented layers below PHP — an injected header after one may be invisible here
     * and present on the wire.
     */
    private const FORBIDDEN = ["\r", "\n", "\0"];

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException if $value contains CR, LF, or NUL.
     */
    public static function assertNoInjection(string $value, string $field): void
    {
        foreach (self::FORBIDDEN as $character) {
            if (str_contains($value, $character)) {
                throw new InvalidArgumentException(sprintf(
                    'The %s contains a line break or NUL byte. A header holding one would be read as '
                    . 'two headers, letting whatever follows it forge a Bcc, Reply-To or From — so it '
                    . 'is refused here rather than sanitised into something you did not write.',
                    $field
                ));
            }
        }
    }

    /**
     * Encode a header value as an RFC 2047 base64 encoded-word when it is not plain ASCII,
     * and return it untouched when it is.
     *
     * A subject with an em-dash or a display name with an accent cannot be written literally
     * into a header — the result is either mojibake or an invalid message, depending on how
     * forgiving the receiver is. Encoded-words are never folded internally: an encoded-word
     * split across a line is not decodable, so this returns one unbroken token.
     *
     * Only MIME output needs this. The JSON APIs take UTF-8 natively and must receive the
     * raw string.
     */
    public static function encodeWord(string $value): string
    {
        if (self::isAscii($value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * An address in RFC 5322 form: `Display Name <someone@example.com>`, or the bare address
     * when there is no display name.
     *
     * $encode applies RFC 2047 to the display name, for MIME output. The JSON adapters pass
     * false and send UTF-8 as-is.
     */
    public static function formatAddress(Address $address, bool $encode = false): string
    {
        if ($address->name === null) {
            return $address->email;
        }

        $name = $encode ? self::encodeWord($address->name) : $address->name;

        // An encoded-word is already a bare token and must not be quoted; quoting it would
        // make receivers treat it as a literal string rather than decoding it.
        if ($name !== $address->name || self::isAtomSafe($name)) {
            return sprintf('%s <%s>', $name, $address->email);
        }

        return sprintf('"%s" <%s>', addcslashes($name, '"\\'), $address->email);
    }

    /**
     * @param list<Address> $addresses
     */
    public static function formatAddressList(array $addresses, bool $encode = false): string
    {
        return implode(', ', array_map(
            static fn (Address $address): string => self::formatAddress($address, $encode),
            $addresses
        ));
    }

    private static function isAscii(string $value): bool
    {
        return preg_match('/[\x80-\xFF]/', $value) !== 1;
    }

    /**
     * Whether a display name can appear unquoted — no RFC 5322 "specials", no leading or
     * trailing whitespace.
     */
    private static function isAtomSafe(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]+$/', $name) === 1
            && trim($name) === $name;
    }
}
