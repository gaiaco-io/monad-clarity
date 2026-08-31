<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\MailAdapters;

use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\Mail;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\Attachment;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\SentMessage;

/**
 * Mailtrap, via `POST /api/send` — or, against a sandbox inbox, `POST /api/send/{inboxId}`
 * on a different host entirely.
 *
 * The sandbox is a **named constructor**, not a boolean or a base-URI override, because it
 * is where a developer spends nearly all of their time with this provider and because the
 * failure it prevents is the expensive one: a `sandbox: true` flag left false in a staging
 * config sends real mail to real people. `Mailtrap::sandbox($token, $inboxId, $http)` cannot
 * be misread at the call site, and it cannot be constructed without the inbox it needs.
 *
 * @package Monad\Clarity\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Mailtrap extends Mail
{
    use SpeaksHttpApi;

    private const SEND_ENDPOINT = 'https://send.api.mailtrap.io/api/send';
    private const SANDBOX_ENDPOINT = 'https://sandbox.api.mailtrap.io/api/send/%s';

    private function __construct(
        private readonly string $apiToken,
        private readonly HttpClient $httpClient,
        private readonly string $endpoint,
        private readonly bool $isSandbox,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * The live sending API. This delivers to real recipients.
     */
    public static function sending(
        string $apiToken,
        HttpClient $httpClient,
        int $timeoutSeconds = 30,
    ): self {
        return new self($apiToken, $httpClient, self::SEND_ENDPOINT, false, $timeoutSeconds);
    }

    /**
     * A sandbox inbox. Nothing sent here reaches a real recipient.
     */
    public static function sandbox(
        string $apiToken,
        string $inboxId,
        HttpClient $httpClient,
        int $timeoutSeconds = 30,
    ): self {
        return new self(
            $apiToken,
            $httpClient,
            sprintf(self::SANDBOX_ENDPOINT, rawurlencode($inboxId)),
            true,
            $timeoutSeconds
        );
    }

    /**
     * Distinguished from the live adapter so a SentMessage's trail says which one took the
     * message. "It sent fine in staging" is a much shorter conversation when the record
     * names the sandbox.
     */
    public function mailerName(): string
    {
        return $this->isSandbox ? 'mailtrap_sandbox' : 'mailtrap';
    }

    public function send(Message $message): SentMessage
    {
        $response = $this->postJson($this->endpoint, $this->payloadFor($message), [
            'Api-Token' => $this->apiToken,
            'Accept' => 'application/json',
        ]);

        $this->assertSuccessful($response);

        $body = $this->decodeJsonBody($response);

        $ids = isset($body['message_ids']) && is_array($body['message_ids']) ? $body['message_ids'] : [];
        $first = $ids === [] ? null : reset($ids);

        return SentMessage::delivered(
            $this->mailerName(),
            is_string($first) ? $first : null,
            $body
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Message $message): array
    {
        $payload = [
            'from' => self::addressPayload($message->from),
            'to' => array_map(self::addressPayload(...), $message->to),
            'subject' => $message->subject,
        ];

        if ($message->cc !== []) {
            $payload['cc'] = array_map(self::addressPayload(...), $message->cc);
        }

        if ($message->bcc !== []) {
            $payload['bcc'] = array_map(self::addressPayload(...), $message->bcc);
        }

        if ($message->replyTo !== null) {
            $payload['reply_to'] = self::addressPayload($message->replyTo);
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

        // Mailtrap's "category" is a single string, as Postmark's Tag is, and it is refused
        // the same way rather than quietly keeping the first — see assertAtMostOneTag(). Two
        // adapters with the same limit must not give two different answers to one message,
        // or the behaviour depends on which member of a pool happened to take it.
        $this->assertAtMostOneTag($message);

        if ($message->tags !== []) {
            $payload['category'] = $message->tags[0];
        }

        if ($message->hasAttachments()) {
            $payload['attachments'] = array_map(self::attachmentPayload(...), $message->attachments);
        }

        return $payload;
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
