<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

use InvalidArgumentException;

/**
 * One email, expressed the same way for all seven providers.
 *
 * **One Message is one email.** Three `to` addresses means three recipients who can see
 * each other, not three separate sends — SendGrid's `personalizations[]` array makes the
 * other reading expressible, so its adapter sends exactly one personalization holding
 * every recipient. An application wanting three private emails builds three Messages,
 * which is also the only way to give each its own body.
 *
 * Everything is validated here, once, rather than seven times in seven dialects. A
 * malformed recipient is `FailureScope::Message` at every provider, so discovering it at
 * construction saves a pool from proving that fact one round trip at a time.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class Message
{
    /**
     * Headers the message structure owns. Accepting an application-supplied value for any of
     * these would either duplicate a header MimeMessage already writes, or — for `Bcc` —
     * reintroduce exactly the disclosure §2.12 exists to prevent, by the front door this
     * time rather than through an injected newline.
     */
    private const RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'date', 'message-id',
        'mime-version', 'content-type', 'content-transfer-encoding', 'content-disposition',
    ];

    /** @var list<Address> */
    public array $to;

    /** @var list<Address> */
    public array $cc;

    /** @var list<Address> */
    public array $bcc;

    /** @var array<string, string> */
    public array $headers;

    /** @var list<Attachment> */
    public array $attachments;

    /** @var list<string> */
    public array $tags;

    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param array<string, string> $headers Extra headers, none of them structural.
     * @param list<Attachment> $attachments
     * @param list<string> $tags Provider-side labels for grouping and analytics. Providers
     *     disagree on the shape (Postmark takes one, SendGrid an array, Resend name/value
     *     pairs), so this is the common denominator and each adapter maps it.
     */
    public function __construct(
        public Address $from,
        array $to,
        public string $subject,
        public ?string $text = null,
        public ?string $html = null,
        array $cc = [],
        array $bcc = [],
        public ?Address $replyTo = null,
        array $headers = [],
        array $attachments = [],
        array $tags = [],
    ) {
        Header::assertNoInjection($subject, 'subject');

        $this->to = self::assertAddressList($to, 'to');
        $this->cc = self::assertAddressList($cc, 'cc');
        $this->bcc = self::assertAddressList($bcc, 'bcc');

        if ($this->to === []) {
            throw new InvalidArgumentException('A Message requires at least one "to" recipient.');
        }

        if (($text === null || trim($text) === '') && ($html === null || trim($html) === '')) {
            throw new InvalidArgumentException(
                'A Message requires a text body, an HTML body, or both. A message with neither is '
                . 'accepted by some providers and silently discarded by others.'
            );
        }

        $this->headers = self::assertHeaders($headers);
        $this->attachments = self::assertAttachments($attachments);
        $this->tags = self::assertTags($tags);
    }

    /**
     * Every address the envelope must carry, deduplicated by mailbox — what SMTP names in
     * `RCPT TO`, and the reason Bcc never needs to appear in a header to be delivered.
     *
     * @return list<Address>
     */
    public function recipients(): array
    {
        $seen = [];
        $recipients = [];

        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $address) {
            $key = strtolower($address->email);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $recipients[] = $address;
            }
        }

        return $recipients;
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    /**
     * @return list<Attachment>
     */
    public function inlineAttachments(): array
    {
        return array_values(array_filter(
            $this->attachments,
            static fn (Attachment $attachment): bool => $attachment->isInline()
        ));
    }

    /**
     * @return list<Attachment>
     */
    public function fileAttachments(): array
    {
        return array_values(array_filter(
            $this->attachments,
            static fn (Attachment $attachment): bool => !$attachment->isInline()
        ));
    }

    /**
     * @param array<mixed> $addresses
     * @return list<Address>
     */
    private static function assertAddressList(array $addresses, string $field): array
    {
        foreach ($addresses as $address) {
            if (!$address instanceof Address) {
                throw new InvalidArgumentException(sprintf(
                    'Every "%s" entry must be a Mail\Address; got %s. Addresses validate themselves, '
                    . 'which is what keeps a malformed recipient from reaching a provider.',
                    $field,
                    get_debug_type($address)
                ));
            }
        }

        return array_values($addresses);
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string>
     */
    private static function assertHeaders(array $headers): array
    {
        $validated = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new InvalidArgumentException(
                    'Extra headers must be a map of string header names to string values.'
                );
            }

            Header::assertNoInjection($name, sprintf('header name "%s"', $name));
            Header::assertNoInjection($value, sprintf('value of header "%s"', $name));

            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'The header name "%s" is not a valid RFC 5322 field name.',
                    $name
                ));
            }

            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" is part of the message structure and cannot be set as an extra header — '
                    . 'use the corresponding Message argument. Bcc in particular is carried by the '
                    . 'envelope and never written into the message, which is what keeps blind '
                    . 'recipients blind.',
                    $name
                ));
            }

            $validated[$name] = $value;
        }

        return $validated;
    }

    /**
     * @param array<mixed> $attachments
     * @return list<Attachment>
     */
    private static function assertAttachments(array $attachments): array
    {
        $contentIds = [];

        foreach ($attachments as $attachment) {
            if (!$attachment instanceof Attachment) {
                throw new InvalidArgumentException(sprintf(
                    'Every attachment must be a Mail\Attachment; got %s.',
                    get_debug_type($attachment)
                ));
            }

            if ($attachment->contentId === null) {
                continue;
            }

            if (isset($contentIds[$attachment->contentId])) {
                throw new InvalidArgumentException(sprintf(
                    'Two inline attachments share the content ID "%s". The HTML references one of '
                    . 'them by that id and cannot say which.',
                    $attachment->contentId
                ));
            }

            $contentIds[$attachment->contentId] = true;
        }

        return array_values($attachments);
    }

    /**
     * @param array<mixed> $tags
     * @return list<string>
     */
    private static function assertTags(array $tags): array
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                throw new InvalidArgumentException('Every tag must be a non-empty string.');
            }
        }

        return array_values($tags);
    }
}
