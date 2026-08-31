<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\MailAdapters;

use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\Mail;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\Header;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\SentMessage;

/**
 * SendGrid, via `POST /v3/mail/send`.
 *
 * Two divergences, both of which break an adapter written by analogy with the others:
 *
 * **A successful send returns `202` with an empty body.** There is nothing to decode, and
 * calling the trait's `decodeJsonBody()` here would fail on every *successful* send — which
 * is why the trait keeps posting and decoding as separate steps rather than offering one
 * convenient method the other five would use and this one would have to remember not to.
 * The provider's id arrives in the `X-Message-Id` *response header* instead.
 *
 * **`content[]` is order-significant.** `text/plain` must precede `text/html` or the API
 * rejects the payload outright. That is the same ordering RFC 2046 §5.1.4 requires of
 * multipart/alternative, for the same reason, so `MimeMessage` and this adapter agree.
 *
 * Recipients go in exactly **one** personalization (§2.12): one Message is one email whose
 * recipients can see each other. SendGrid's array makes the other reading expressible, and
 * an application wanting separate sends builds separate Messages.
 *
 * @package Monad\Clarity\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class SendGrid extends Mail
{
    use SpeaksHttpApi;

    private const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function mailerName(): string
    {
        return 'sendgrid';
    }

    public function send(Message $message): SentMessage
    {
        $response = $this->postJson(self::ENDPOINT, $this->payloadFor($message), [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ]);

        $this->assertSuccessful($response);

        // Deliberately no decodeJsonBody(): a successful send is 202 with an empty body.
        $messageId = $response->getHeaderLine('X-Message-Id');

        return SentMessage::delivered($this->mailerName(), $messageId === '' ? null : $messageId);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Message $message): array
    {
        $personalization = ['to' => array_map(self::addressPayload(...), $message->to)];

        if ($message->cc !== []) {
            $personalization['cc'] = array_map(self::addressPayload(...), $message->cc);
        }

        if ($message->bcc !== []) {
            $personalization['bcc'] = array_map(self::addressPayload(...), $message->bcc);
        }

        $payload = [
            'personalizations' => [$personalization],
            'from' => self::addressPayload($message->from),
            'subject' => $message->subject,
            'content' => self::contentPayload($message),
        ];

        if ($message->replyTo !== null) {
            $payload['reply_to'] = self::addressPayload($message->replyTo);
        }

        if ($message->headers !== []) {
            $payload['headers'] = $message->headers;
        }

        if ($message->tags !== []) {
            $payload['categories'] = $message->tags;
        }

        if ($message->hasAttachments()) {
            $payload['attachments'] = array_map(self::attachmentPayload(...), $message->attachments);
        }

        return $payload;
    }

    /**
     * text/plain before text/html — SendGrid rejects the other order.
     *
     * @return list<array{type: string, value: string}>
     */
    private static function contentPayload(Message $message): array
    {
        $content = [];

        if ($message->hasText()) {
            $content[] = ['type' => 'text/plain', 'value' => (string) $message->text];
        }

        if ($message->hasHtml()) {
            $content[] = ['type' => 'text/html', 'value' => (string) $message->html];
        }

        return $content;
    }

    /**
     * @return array<string, string>
     */
    private static function addressPayload(Address $address): array
    {
        $payload = ['email' => $address->email];

        if ($address->name !== null) {
            $payload['name'] = $address->name;
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private static function attachmentPayload(Attachment $attachment): array
    {
        $payload = [
            'content' => base64_encode($attachment->contents),
            'filename' => $attachment->filename,
            'type' => $attachment->contentType,
            'disposition' => $attachment->disposition(),
        ];

        if ($attachment->contentId !== null) {
            $payload['content_id'] = $attachment->contentId;
        }

        return $payload;
    }
}
