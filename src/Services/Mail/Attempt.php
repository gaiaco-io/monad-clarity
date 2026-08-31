<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

/**
 * What one mailer did when a pool handed it a message.
 *
 * With no delivery table anywhere in Clarity (ReleaseNotes_1.6.0.md §2.8), the trail of
 * these on a SentMessage is the *only* record that failover happened. A pool that has been
 * quietly falling through to its third mailer for a week has turned an outage into an
 * invisible degradation, and the bill arrives when the third one fails too.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class Attempt
{
    private function __construct(
        public string $mailer,
        public bool $succeeded,
        public ?MailException $failure,
    ) {
    }

    public static function succeeded(string $mailer): self
    {
        return new self($mailer, true, null);
    }

    public static function failed(string $mailer, MailException $failure): self
    {
        return new self($mailer, false, $failure);
    }

    /**
     * Why this mailer was passed over, or null if it was the one that sent.
     */
    public function reason(): ?string
    {
        return $this->failure?->getMessage();
    }
}
