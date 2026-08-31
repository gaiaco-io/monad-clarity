<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\MailAdapters;

use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\Mail;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\Header;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\SentMessage;

/**
 * Resend, via `POST /emails`.
 *
 * The plainest of the six: a bearer token, arrays where Postmark takes strings, and a
 * response that is just `{"id": "..."}`. Tags are name/value pairs rather than bare strings,
 * so a Message's tag becomes `{name: <tag>, value: <tag>}` — Resend requires both halves and
 * inventing a distinction the shared contract does not carry would be worse than repeating it.
 *
 * @package Monad\Clarity\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Resend extends Mail
{
    use SpeaksHttpApi;

    private const ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function mailerName(): string
    {
        return 'resend';
    }

    public function send(Message $message): SentMessage
    {
        $response = $this->postJson(self::ENDPOINT, $this->payloadFor($message), [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ]);

        $this->assertSuccessful($response);

        $body = $this->decodeJsonBody($response);

        return SentMessage::delivered(
            $this->mailerName(),
            isset($body['id']) && is_string($body['id']) ? $body['id'] : null,
            $body
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Message $message): array
    {
        $payload = [
            'from' => Header::formatAddress($message->from),
            'to' => array_map(static fn ($a): string => Header::formatAddress($a), $message->to),
            'subject' => $message->subject,
        ];

        if ($message->cc !== []) {
            $payload['cc'] = array_map(static fn ($a): string => Header::formatAddress($a), $message->cc);
        }

        if ($message->bcc !== []) {
            $payload['bcc'] = array_map(static fn ($a): string => Header::formatAddress($a), $message->bcc);
        }

        if ($message->replyTo !== null) {
            $payload['reply_to'] = Header::formatAddress($message->replyTo);
        }

        if ($message->hasText()) {
            $payload['text'] = $message->text;
        }

        if ($message->hasHtml()) {
            $payload['html'] = $message->html;
        }

        if ($message->headers !== []) {
            $payload['headers'] = $message->headers;
        }

        if ($message->tags !== []) {
            $payload['tags'] = array_map(
                static fn (string $tag): array => ['name' => $tag, 'value' => $tag],
                $message->tags
            );
        }

        if ($message->hasAttachments()) {
            $payload['attachments'] = array_map(self::attachmentPayload(...), $message->attachments);
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private static function attachmentPayload(Attachment $attachment): array
    {
        $payload = [
            'filename' => $attachment->filename,
            'content' => base64_encode($attachment->contents),
            'content_type' => $attachment->contentType,
        ];

        if ($attachment->contentId !== null) {
            $payload['content_id'] = $attachment->contentId;
        }

        return $payload;
    }
}
