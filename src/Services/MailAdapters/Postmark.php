<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\MailAdapters;

use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\Mail;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\Header;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\SentMessage;

/**
 * Postmark, via `POST /email`.
 *
 * Two things here are unlike the other five adapters. Postmark takes its recipient fields as
 * **comma-separated strings** rather than arrays, and — the trap — it can answer **HTTP 200
 * carrying a non-zero `ErrorCode`**. A 2xx is therefore not sufficient evidence of success,
 * and an adapter that trusted the status alone would return a SentMessage with a null id for
 * a message Postmark had refused. `assertAccepted()` is the second gate.
 *
 * @package Monad\Clarity\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Postmark extends Mail
{
    use SpeaksHttpApi;

    private const ENDPOINT = 'https://api.postmarkapp.com/email';

    /**
     * Postmark error codes that are the message's fault rather than the account's, so a pool
     * stops instead of proving the same point against six more providers. 300 is a malformed
     * address or missing field; 406 is a recipient Postmark has deactivated for hard bouncing,
     * which every other provider would also refuse.
     */
    private const MESSAGE_FAULT_CODES = [300, 406];

    /**
     * @param string $serverToken A Postmark *server* token — not an account token.
     * @param string $messageStream Postmark routes by stream; 'outbound' is the transactional
     *     default every account has. Sending transactional mail down a broadcast stream is a
     *     deliverability problem rather than an error, so it is named here rather than assumed.
     */
    public function __construct(
        private readonly string $serverToken,
        private readonly HttpClient $httpClient,
        private readonly string $messageStream = 'outbound',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function mailerName(): string
    {
        return 'postmark';
    }

    public function send(Message $message): SentMessage
    {
        $response = $this->postJson(self::ENDPOINT, $this->payloadFor($message), [
            'X-Postmark-Server-Token' => $this->serverToken,
            'Accept' => 'application/json',
        ]);

        $this->assertSuccessful($response);

        $body = $this->decodeJsonBody($response);

        $this->assertAccepted($body);

        return SentMessage::delivered(
            $this->mailerName(),
            isset($body['MessageID']) && is_string($body['MessageID']) ? $body['MessageID'] : null,
            $body
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Message $message): array
    {
        $payload = [
            'From' => Header::formatAddress($message->from),
            'To' => Header::formatAddressList($message->to),
            'Subject' => $message->subject,
            'MessageStream' => $this->messageStream,
        ];

        if ($message->cc !== []) {
            $payload['Cc'] = Header::formatAddressList($message->cc);
        }

        if ($message->bcc !== []) {
            $payload['Bcc'] = Header::formatAddressList($message->bcc);
        }

        if ($message->replyTo !== null) {
            $payload['ReplyTo'] = Header::formatAddress($message->replyTo);
        }

        if ($message->hasText()) {
            $payload['TextBody'] = $message->text;
        }

        if ($message->hasHtml()) {
            $payload['HtmlBody'] = $message->html;
        }

        if ($message->headers !== []) {
            $payload['Headers'] = array_map(
                static fn (string $name, string $value): array => ['Name' => $name, 'Value' => $value],
                array_keys($message->headers),
                array_values($message->headers)
            );
        }

        // Postmark takes exactly one tag, not a list. Sending only the first would silently
        // drop the rest, so more than one is refused: a tag that vanished is a reporting
        // result nobody can explain six months later.
        if (count($message->tags) > 1) {
            throw MailException::message(sprintf(
                'Postmark accepts a single tag and this message carries %d (%s). Send one, or use '
                . 'Headers for the rest — silently keeping the first would make a tag disappear.',
                count($message->tags),
                implode(', ', $message->tags)
            ));
        }

        if ($message->tags !== []) {
            $payload['Tag'] = $message->tags[0];
        }

        if ($message->hasAttachments()) {
            $payload['Attachments'] = array_map(self::attachmentPayload(...), $message->attachments);
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private static function attachmentPayload(Attachment $attachment): array
    {
        $payload = [
            'Name' => $attachment->filename,
            'Content' => base64_encode($attachment->contents),
            'ContentType' => $attachment->contentType,
        ];

        if ($attachment->contentId !== null) {
            $payload['ContentID'] = 'cid:' . $attachment->contentId;
        }

        return $payload;
    }

    /**
     * Postmark's second failure channel: a 200 whose body carries a non-zero `ErrorCode`.
     *
     * @param array<string, mixed> $body
     * @throws MailException if the body reports an error.
     */
    private function assertAccepted(array $body): void
    {
        $code = isset($body['ErrorCode']) && is_numeric($body['ErrorCode']) ? (int) $body['ErrorCode'] : 0;

        if ($code === 0) {
            return;
        }

        $description = isset($body['Message']) && is_string($body['Message'])
            ? $body['Message']
            : 'no description given';

        throw new MailException(
            sprintf('postmark accepted the request but refused the message (code %d): %s', $code, $description),
            in_array($code, self::MESSAGE_FAULT_CODES, true) ? FailureScope::Message : FailureScope::Mailer
        );
    }

    private function scopeFromErrorBody(int $status, string $body): ?FailureScope
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['ErrorCode']) || !is_numeric($decoded['ErrorCode'])) {
            return null;
        }

        return in_array((int) $decoded['ErrorCode'], self::MESSAGE_FAULT_CODES, true)
            ? FailureScope::Message
            : FailureScope::Mailer;
    }
}
