<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\MailAdapters;

use InvalidArgumentException;
use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\Mail;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\Header;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\SentMessage;
use Monad\Clarity\Utils\CryptographicToken;

/**
 * Mailgun, via `POST /v3/{domain}/messages`.
 *
 * The one adapter of the six that is not a JSON API, and it diverges in four ways at once:
 * HTTP **Basic** auth as `api:{key}`; a **form** body rather than JSON; recipient fields
 * **repeated once per address** instead of sent as an array; and a sending domain that is
 * part of the *path*, with the EU region on a different host altogether. All four are
 * constructor or encoding concerns, which is why this one is written last — everything it
 * shares with the others is already proven by then.
 *
 * `HttpClient` sends a raw string body, so the multipart document is built here: boundary,
 * per-part headers, CRLF discipline, terminating delimiter. Only when the message has
 * attachments — a plain send uses `application/x-www-form-urlencoded`, which is smaller and
 * has no boundary to get wrong.
 *
 * @package Monad\Clarity\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Mailgun extends Mail
{
    use SpeaksHttpApi;

    public const REGION_US = 'https://api.mailgun.net';
    public const REGION_EU = 'https://api.eu.mailgun.net';

    private const CRLF = "\r\n";

    /**
     * @param string $domain The verified sending domain, e.g. 'mg.example.com'.
     * @param string $baseUri REGION_US or REGION_EU. An EU-resident account addressed at the
     *     US host authenticates and then reports an unknown domain, which reads as a
     *     credential problem rather than the region mistake it is.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $domain,
        private readonly HttpClient $httpClient,
        private readonly string $baseUri = self::REGION_US,
        private readonly int $timeoutSeconds = 30,
    ) {
        if (trim($domain) === '') {
            throw new InvalidArgumentException('Mailgun requires the verified sending domain.');
        }
    }

    public function mailerName(): string
    {
        return 'mailgun';
    }

    public function send(Message $message): SentMessage
    {
        $fields = $this->fieldsFor($message);

        [$contentType, $body] = $message->hasAttachments()
            ? self::multipartBody($fields, $message->attachments)
            : ['application/x-www-form-urlencoded', self::urlEncodedBody($fields)];

        $response = $this->dispatch(
            'POST',
            sprintf('%s/v3/%s/messages', $this->baseUri, rawurlencode($this->domain)),
            [
                'Content-Type' => $contentType,
                'Authorization' => 'Basic ' . base64_encode('api:' . $this->apiKey),
                'Accept' => 'application/json',
            ],
            $body
        );

        $this->assertSuccessful($response);

        $decoded = $this->decodeJsonBody($response);

        return SentMessage::delivered(
            $this->mailerName(),
            isset($decoded['id']) && is_string($decoded['id']) ? trim($decoded['id'], '<>') : null,
            $decoded
        );
    }

    /**
     * Mailgun repeats a field per recipient rather than taking an array, so this returns a
     * list of name/value pairs and not a map — `to` legitimately appears more than once.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function fieldsFor(Message $message): array
    {
        $fields = [['from', Header::formatAddress($message->from)]];

        foreach ($message->to as $address) {
            $fields[] = ['to', Header::formatAddress($address)];
        }

        foreach ($message->cc as $address) {
            $fields[] = ['cc', Header::formatAddress($address)];
        }

        foreach ($message->bcc as $address) {
            $fields[] = ['bcc', Header::formatAddress($address)];
        }

        $fields[] = ['subject', $message->subject];

        if ($message->replyTo !== null) {
            $fields[] = ['h:Reply-To', Header::formatAddress($message->replyTo)];
        }

        if ($message->hasText()) {
            $fields[] = ['text', (string) $message->text];
        }

        if ($message->hasHtml()) {
            $fields[] = ['html', (string) $message->html];
        }

        foreach ($message->headers as $name => $value) {
            $fields[] = ['h:' . $name, $value];
        }

        foreach ($message->tags as $tag) {
            $fields[] = ['o:tag', $tag];
        }

        return $fields;
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     */
    private static function urlEncodedBody(array $fields): string
    {
        return implode('&', array_map(
            static fn (array $field): string => rawurlencode($field[0]) . '=' . rawurlencode($field[1]),
            $fields
        ));
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     * @param list<Attachment> $attachments
     * @return array{0: string, 1: string}
     */
    private static function multipartBody(array $fields, array $attachments): array
    {
        $boundary = self::boundary($fields, $attachments);
        $body = '';

        foreach ($fields as [$name, $value]) {
            $body .= '--' . $boundary . self::CRLF
                . sprintf('Content-Disposition: form-data; name="%s"', $name) . self::CRLF
                . self::CRLF
                . $value . self::CRLF;
        }

        foreach ($attachments as $attachment) {
            // Mailgun distinguishes the two by field name, not by a disposition parameter:
            // an inline part must be posted as "inline" for cid: references to resolve.
            $field = $attachment->isInline() ? 'inline' : 'attachment';

            $body .= '--' . $boundary . self::CRLF
                . sprintf(
                    'Content-Disposition: form-data; name="%s"; filename="%s"',
                    $field,
                    $attachment->filename
                ) . self::CRLF
                . 'Content-Type: ' . $attachment->contentType . self::CRLF
                . self::CRLF
                . $attachment->contents . self::CRLF;
        }

        $body .= '--' . $boundary . '--' . self::CRLF;

        return ['multipart/form-data; boundary=' . $boundary, $body];
    }

    /**
     * A boundary that appears in none of the content it separates. Attachment bytes are
     * posted raw here rather than base64-encoded, so unlike MimeMessage's parts this really
     * can contain arbitrary binary — the check is doing work, not being defensive.
     *
     * @param list<array{0: string, 1: string}> $fields
     * @param list<Attachment> $attachments
     */
    private static function boundary(array $fields, array $attachments): string
    {
        do {
            $boundary = 'MonadClarity' . CryptographicToken::generate(16);
            $collides = false;

            foreach ($fields as [, $value]) {
                if (str_contains($value, $boundary)) {
                    $collides = true;
                    break;
                }
            }

            foreach ($attachments as $attachment) {
                if (str_contains($attachment->contents, $boundary)) {
                    $collides = true;
                    break;
                }
            }
        } while ($collides);

        return $boundary;
    }
}
