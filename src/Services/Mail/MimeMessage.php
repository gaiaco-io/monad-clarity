<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Monad\Clarity\Utils\CryptographicToken;

/**
 * A Message rendered as an RFC 5322 document.
 *
 * Built in the first phase, before either caller exists, because two later ones need the
 * same bytes: `MailAdapters\Smtp` transmits this after `DATA`, and `MailAdapters\AmazonSes`
 * base64-encodes it into `Content.Raw.Data` whenever a message carries attachments, since
 * SES's `Simple` content shape cannot express them.
 *
 * **No `Bcc:` header is ever emitted** (ReleaseNotes_1.6.0.md §2.12). Blind recipients
 * travel in the SMTP envelope — `Message::recipients()` — and nowhere else. A `Bcc` header
 * in the transmitted document discloses every blind recipient to every other recipient,
 * which is the one failure of this service that is both silent and unrecoverable: by the
 * time anyone notices, the disclosure has already happened.
 *
 * Every body part is base64-encoded rather than quoted-printable. Base64 has no line-length
 * hazards, no trailing-whitespace rules, and cannot produce a line beginning with `.` for
 * SMTP to mangle — the whole class of encoding bug disappears for a size cost that is
 * irrelevant to transactional mail.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MimeMessage
{
    private const CRLF = "\r\n";

    private function __construct()
    {
    }

    /**
     * Render $message as a complete MIME document.
     *
     * @param ?DateTimeImmutable $sentAt The `Date:` header's value; defaults to now. Both
     *     this and the generated `Message-ID` exist because a raw SMTP send needs them:
     *     the API providers add their own, but a message arriving at a receiver without
     *     either is treated as spam by a good number of them.
     */
    public static function build(Message $message, ?DateTimeImmutable $sentAt = null): string
    {
        $entity = self::entity($message);

        $headers = [
            'Date' => ($sentAt ?? new DateTimeImmutable())->format(DateTimeInterface::RFC2822),
            'Message-ID' => sprintf('<%s@%s>', CryptographicToken::generate(16), $message->from->domain()),
            'From' => Header::formatAddress($message->from, encode: true),
            'To' => Header::formatAddressList($message->to, encode: true),
        ];

        if ($message->cc !== []) {
            $headers['Cc'] = Header::formatAddressList($message->cc, encode: true);
        }

        if ($message->replyTo !== null) {
            $headers['Reply-To'] = Header::formatAddress($message->replyTo, encode: true);
        }

        $headers['Subject'] = Header::encodeWord($message->subject);

        foreach ($message->headers as $name => $value) {
            $headers[$name] = Header::encodeWord($value);
        }

        $headers['MIME-Version'] = '1.0';

        return self::render([...$headers, ...$entity['headers']], $entity['body']);
    }

    /**
     * The message's body, as a MIME entity: its own Content-* headers plus its encoded
     * content. Assembled outermost-last, so each wrapper only has to know about the single
     * entity it is wrapping.
     *
     * @return array{headers: array<string, string>, body: string}
     */
    private static function entity(Message $message): array
    {
        $entity = self::alternative($message);

        // An inline image is referenced by the HTML that sits beside it, so multipart/related
        // must enclose both — putting the images in the outer multipart/mixed instead leaves
        // clients free to render the HTML without resolving its own cid: references.
        $inline = $message->inlineAttachments();

        if ($inline !== []) {
            $entity = self::multipart('related', [
                $entity,
                ...array_map(self::attachmentEntity(...), $inline),
            ]);
        }

        $files = $message->fileAttachments();

        if ($files !== []) {
            $entity = self::multipart('mixed', [
                $entity,
                ...array_map(self::attachmentEntity(...), $files),
            ]);
        }

        return $entity;
    }

    /**
     * The text and HTML bodies. Both present means multipart/alternative, with text first —
     * the order is the specification's, not a preference: RFC 2046 §5.1.4 has clients pick
     * the *last* part they can display, so text before HTML is what makes HTML win.
     *
     * @return array{headers: array<string, string>, body: string}
     */
    private static function alternative(Message $message): array
    {
        $text = $message->hasText() ? self::textEntity('text/plain', (string) $message->text) : null;
        $html = $message->hasHtml() ? self::textEntity('text/html', (string) $message->html) : null;

        if ($text !== null && $html !== null) {
            return self::multipart('alternative', [$text, $html]);
        }

        // Message refuses to exist without at least one body, so this cannot be reached — but
        // it is asserted rather than assumed, because the alternative to a clear failure here
        // is a null propagating into the caller's array access.
        return $text ?? $html ?? throw new LogicException(
            'A Message reached MimeMessage with neither a text nor an HTML body, which its own '
            . 'constructor forbids.'
        );
    }

    /**
     * @return array{headers: array<string, string>, body: string}
     */
    private static function textEntity(string $contentType, string $content): array
    {
        return [
            'headers' => [
                'Content-Type' => sprintf('%s; charset=UTF-8', $contentType),
                'Content-Transfer-Encoding' => 'base64',
            ],
            'body' => self::base64($content),
        ];
    }

    /**
     * @return array{headers: array<string, string>, body: string}
     */
    private static function attachmentEntity(Attachment $attachment): array
    {
        $headers = [
            'Content-Type' => sprintf('%s; name="%s"', $attachment->contentType, $attachment->filename),
            'Content-Transfer-Encoding' => 'base64',
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $attachment->disposition(),
                $attachment->filename
            ),
        ];

        if ($attachment->contentId !== null) {
            $headers['Content-ID'] = sprintf('<%s>', $attachment->contentId);
        }

        return ['headers' => $headers, 'body' => self::base64($attachment->contents)];
    }

    /**
     * Wrap entities in a multipart of the given subtype.
     *
     * @param list<array{headers: array<string, string>, body: string}> $parts
     * @return array{headers: array<string, string>, body: string}
     */
    private static function multipart(string $subtype, array $parts): array
    {
        $boundary = self::boundary($parts);

        $body = '';

        foreach ($parts as $part) {
            $body .= '--' . $boundary . self::CRLF
                . self::render($part['headers'], $part['body'])
                . self::CRLF;
        }

        $body .= '--' . $boundary . '--';

        return [
            'headers' => ['Content-Type' => sprintf('multipart/%s; boundary="%s"', $subtype, $boundary)],
            'body' => $body,
        ];
    }

    /**
     * A boundary that cannot occur inside the content it separates.
     *
     * Random rather than derived from a counter or `uniqid`, and then *verified* against
     * every part: a boundary appearing in a part's body truncates the message there, and
     * base64 content makes that vanishingly unlikely but not impossible to reason about.
     * Checking is cheaper than arguing about the probability.
     *
     * @param list<array{headers: array<string, string>, body: string}> $parts
     */
    private static function boundary(array $parts): string
    {
        do {
            $boundary = 'MonadClarity_' . CryptographicToken::generate(16);

            $collides = false;

            foreach ($parts as $part) {
                if (str_contains($part['body'], $boundary)) {
                    $collides = true;
                    break;
                }
            }
        } while ($collides);

        return $boundary;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function render(array $headers, string $body): string
    {
        $rendered = '';

        foreach ($headers as $name => $value) {
            $rendered .= $name . ': ' . $value . self::CRLF;
        }

        return $rendered . self::CRLF . $body;
    }

    /**
     * Base64, wrapped at 76 characters with CRLF, and no trailing break — RFC 2045 §6.8.
     */
    private static function base64(string $content): string
    {
        return rtrim(chunk_split(base64_encode($content), 76, self::CRLF), self::CRLF);
    }
}
