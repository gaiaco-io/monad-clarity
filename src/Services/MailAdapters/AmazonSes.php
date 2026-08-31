<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\MailAdapters;

use ArrayAccess;
use Monad\Clarity\Services\Mail;
use Monad\Clarity\Services\Mail\Address;
use Monad\Clarity\Services\Mail\FailureScope;
use Monad\Clarity\Services\Mail\Header;
use Monad\Clarity\Services\Mail\MailException;
use Monad\Clarity\Services\Mail\Message;
use Monad\Clarity\Services\Mail\MimeMessage;
use Monad\Clarity\Services\Mail\SentMessage;
use Throwable;

/**
 * Amazon SES v2, through an injected client rather than a signed request of our own.
 *
 * `$client` is any object exposing `sendEmail(array $args)` — the real `Aws\SesV2Client`
 * method shape — so the genuine SDK needs no translation and a test needs a plain fake.
 * `Services\Files` accepts an `S3Client`-shaped object on exactly these terms, and
 * ReleaseNotes_1.6.0.md §2.14 chose to follow it rather than sign requests in-house: SigV4
 * canonicalisation is a security protocol with several ways to be subtly wrong that surface
 * as an opaque `403` rather than a clear error, and it would have to stay correct for the
 * life of a major version. **No `aws/aws-sdk-php` entry is added to `composer.json`.**
 *
 * Two differences from every other adapter follow from that choice, and both are deliberate:
 * there is **no `$httpClient`** — the injected client owns the transport — and **no
 * `$timeoutSeconds`**, because it owns the timeouts too, and accepting one this class could
 * not enforce would be a lie in the signature.
 *
 * @package Monad\Clarity\Services\MailAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class AmazonSes extends Mail
{
    private const CHARSET = 'UTF-8';

    /**
     * SES error codes that name something wrong with *this message*. Everything else —
     * throttling, a paused account, an unverified identity, rejected credentials — is the
     * mailer's, per §2.4.
     *
     * `MessageRejected` is deliberately absent. It is SES's catch-all for both a rejected
     * body and an unverified sending identity, and a code spanning the two takes §2.4's
     * default for the ambiguous, which is `Mailer`.
     */
    private const MESSAGE_FAULT_CODES = [
        'InvalidParameterValue',
        'InvalidParameter',
        'ValidationException',
        'BadRequestException',
    ];

    /**
     * @param object $client Any object with `sendEmail(array $args)`. Required and
     *     non-nullable, unlike `Files`' `?object $s3Client` — Files has a filesystem adapter
     *     to fall back to, so null is a mode there. Here it would just be a broken mailer.
     * @param ?string $configurationSetName An SES configuration set, for event publishing.
     */
    public function __construct(
        private readonly object $client,
        private readonly ?string $configurationSetName = null,
    ) {
    }

    public function mailerName(): string
    {
        return 'amazon_ses';
    }

    public function send(Message $message): SentMessage
    {
        // Built and validated before the try, so that neither a MailException of our own nor
        // a TypeError from this library's own payload construction can be caught below and
        // reported to a pool as a provider failure.
        $arguments = $this->argumentsFor($message);

        try {
            $result = $this->client->sendEmail($arguments);
        } catch (Throwable $e) {
            throw $this->failureFor($e);
        }

        return SentMessage::delivered($this->mailerName(), self::messageIdIn($result));
    }

    /**
     * @return array<string, mixed>
     */
    private function argumentsFor(Message $message): array
    {
        $arguments = [
            'FromEmailAddress' => Header::formatAddress($message->from),
            'Destination' => array_filter([
                'ToAddresses' => self::addresses($message->to),
                'CcAddresses' => self::addresses($message->cc),
                'BccAddresses' => self::addresses($message->bcc),
            ]),
            'Content' => $this->contentFor($message),
        ];

        if ($message->replyTo !== null) {
            $arguments['ReplyToAddresses'] = [Header::formatAddress($message->replyTo)];
        }

        if ($this->configurationSetName !== null) {
            $arguments['ConfigurationSetName'] = $this->configurationSetName;
        }

        if ($message->tags !== []) {
            $arguments['EmailTags'] = array_map(
                fn (string $tag): array => ['Name' => $this->assertTagIsSendable($tag), 'Value' => $tag],
                $message->tags
            );
        }

        return $arguments;
    }

    /**
     * `Simple` where SES can express the message, `Raw` where only RFC 5322 can.
     *
     * Attachments force `Raw` because `Simple` has no way to carry them. **Custom headers do
     * too**: SES gained a `Simple.Headers` field, but it is narrower than RFC 5322 and
     * newer than much of the tooling around it, so routing through `Raw` whenever headers are
     * present buys one fidelity guarantee instead of two partial ones. `MimeMessage` is
     * already the authority on those bytes, and it is the reason that class was built in
     * phase 1 rather than alongside SMTP.
     *
     * @return array<string, mixed>
     */
    private function contentFor(Message $message): array
    {
        if ($message->hasAttachments() || $message->headers !== []) {
            return ['Raw' => ['Data' => MimeMessage::build($message)]];
        }

        $body = [];

        if ($message->hasText()) {
            $body['Text'] = ['Data' => (string) $message->text, 'Charset' => self::CHARSET];
        }

        if ($message->hasHtml()) {
            $body['Html'] = ['Data' => (string) $message->html, 'Charset' => self::CHARSET];
        }

        return [
            'Simple' => [
                'Subject' => ['Data' => $message->subject, 'Charset' => self::CHARSET],
                'Body' => $body,
            ],
        ];
    }

    /**
     * SES tag names are restricted to letters, digits, underscore and dash, where the other
     * five mailers take any string. Caught here with a message naming the tag, rather than
     * left to surface as an opaque `InvalidParameterValue` — in a pool the difference is
     * between "this tag is not valid for SES" and a message that five mailers accepted and
     * the sixth refused for no stated reason.
     */
    private function assertTagIsSendable(string $tag): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,256}$/', $tag) !== 1) {
            throw MailException::message(sprintf(
                'Amazon SES tags may contain only letters, digits, underscores and dashes, and "%s" '
                . 'does not. The other mailers accept any string, so this message would send '
                . 'everywhere except here.',
                $tag
            ));
        }

        return $tag;
    }

    /**
     * @param list<Address> $addresses
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_map(static fn (Address $a): string => Header::formatAddress($a), $addresses);
    }

    /**
     * The real SDK returns an `Aws\Result`, which is `ArrayAccess`; a fake is usually a plain
     * array. Both are read, and anything else yields null — a missing id is legitimate, and
     * guessing at a third shape would be inventing a contract nobody implements.
     */
    private static function messageIdIn(mixed $result): ?string
    {
        if (is_array($result)) {
            $id = $result['MessageId'] ?? null;
        } elseif ($result instanceof ArrayAccess && isset($result['MessageId'])) {
            $id = $result['MessageId'];
        } else {
            return null;
        }

        return is_string($id) ? $id : null;
    }

    /**
     * Translate whatever the client threw into a MailException a pool can act on.
     *
     * A `MailException` passes through unchanged: the tag guard above raises one scoped
     * `Message`, and re-wrapping it here would promote it to `Mailer` and send a message
     * SES correctly refused around six more providers.
     */
    private function failureFor(Throwable $e): MailException
    {
        if ($e instanceof MailException) {
            return $e;
        }

        $code = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';

        return new MailException(
            sprintf(
                'amazon_ses rejected the message%s: %s',
                $code === '' ? '' : sprintf(' (%s)', $code),
                $e->getMessage()
            ),
            in_array($code, self::MESSAGE_FAULT_CODES, true) ? FailureScope::Message : FailureScope::Mailer,
            $e
        );
    }
}
