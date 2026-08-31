<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

use RuntimeException;
use Throwable;

/**
 * Thrown by a mail adapter on misconfiguration, a network failure, a provider rejection, or
 * a provider response that cannot be parsed as the shape the adapter expects.
 *
 * Always carries a FailureScope, and never carries a default one: an adapter author who
 * forgets to classify a failure gets a type error at the throw site rather than a silently
 * wrong failover decision at 3am.
 *
 * **Never interpolate a credential, an `AUTH` command, or attachment bytes into one of
 * these messages.** `AUTH PLAIN` transmits base64 of the password, so an adapter that
 * echoes its command trace on failure writes that password into a log. Where a trace is
 * genuinely useful, run it through `Utils\Redactor` first.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MailException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly FailureScope $scope,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * This mailer could not take the message; a pool should try the next one.
     */
    public static function mailer(string $message, ?Throwable $previous = null): self
    {
        return new self($message, FailureScope::Mailer, $previous);
    }

    /**
     * The message is invalid everywhere; a pool should stop rather than repeat the failure
     * against every mailer it has.
     */
    public static function message(string $message, ?Throwable $previous = null): self
    {
        return new self($message, FailureScope::Message, $previous);
    }

    public function isMailerFault(): bool
    {
        return $this->scope === FailureScope::Mailer;
    }
}
