<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

/**
 * Proof that a message was accepted, and by whom.
 *
 * $attempts **includes the successful final attempt**, always as its last element — so
 * `count($sent->attempts)` is the number of mailers tried, and a single-adapter send
 * returns exactly one. A trail that excluded the winner would make the common case an empty
 * array and the count off by one in every reading of it.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class SentMessage
{
    /**
     * @param string $mailer The adapter that actually took the message — not necessarily the
     *     first one a pool was given.
     * @param ?string $providerMessageId The provider's own identifier, where it returns one.
     *     Null is legitimate: not every provider offers one on every path.
     * @param list<Attempt> $attempts Every mailer tried, in order, ending with the one that
     *     succeeded.
     * @param array<string, mixed> $raw The provider's decoded response, for anything this
     *     contract does not surface — an escape hatch, not a substitute for the fields above.
     */
    public function __construct(
        public string $mailer,
        public ?string $providerMessageId,
        public array $attempts,
        public array $raw = [],
    ) {
    }

    /**
     * A message sent first time, by the only mailer asked.
     */
    public static function delivered(
        string $mailer,
        ?string $providerMessageId,
        array $raw = [],
    ): self {
        return new self($mailer, $providerMessageId, [Attempt::succeeded($mailer)], $raw);
    }

    /**
     * Whether a pool had to pass over at least one mailer to send this — the signal worth
     * alerting on, since it means a configured provider is failing in production.
     */
    public function failedOver(): bool
    {
        return count($this->attempts) > 1;
    }

    /**
     * The mailers that could not take the message, in the order they were tried.
     *
     * @return list<Attempt>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (Attempt $attempt): bool => !$attempt->succeeded
        ));
    }
}
