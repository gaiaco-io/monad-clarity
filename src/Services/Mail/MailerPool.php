<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

use InvalidArgumentException;

/**
 * Several mailers in priority order, the first healthy one taking the message.
 *
 * ```php
 * $mail = new MailerPool([$postmark, $resend, $smtp]);
 * $sent = $mail->send($message);
 *
 * $sent->mailer;       // 'resend' — who actually took it
 * $sent->failedOver(); // true — worth alerting on
 * ```
 *
 * **A pool extends `Mail`, so it is a mailer.** Application code holds one type whether it
 * was handed one adapter or seven, and "is multi-mailer enabled?" is answered by which object
 * `config/mail.php` constructs rather than by any flag in here (ReleaseNotes_1.6.0.md §2.6).
 * A pool may hold another pool, if an application wants tiers.
 *
 * **Priority is array order.** Not an integer to be sorted: the list reads top to bottom in
 * the order it will be tried, which is the only ordering a reader could infer anyway.
 *
 * What it does *not* do is retry a mailer (§2.15) or promise exactly-once delivery. A member
 * that accepts the message and then times out before its acknowledgement is read is
 * indistinguishable from one that never got it, so the pool advances and the recipient may
 * receive the message twice: **at least once, not exactly once** (§2.5). Applications that
 * must not double-send — an invoice, a one-time code that invalidates the last — should send
 * through a single adapter and handle the failure themselves.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MailerPool extends \Monad\Clarity\Services\Mail
{
    /** @var list<\Monad\Clarity\Services\Mail> */
    private readonly array $mailers;

    /**
     * @param list<\Monad\Clarity\Services\Mail> $mailers In the order they will be tried.
     */
    public function __construct(array $mailers)
    {
        foreach ($mailers as $mailer) {
            if (!$mailer instanceof \Monad\Clarity\Services\Mail) {
                throw new InvalidArgumentException(sprintf(
                    'Every member of a MailerPool must be a Services\Mail; got %s.',
                    get_debug_type($mailer)
                ));
            }
        }

        if ($mailers === []) {
            throw new InvalidArgumentException(
                'A MailerPool needs at least one mailer. An empty pool would accept every message '
                . 'and send none of them, and it would do so silently — so it is refused here rather '
                . 'than at the first send, which might be weeks later.'
            );
        }

        // Duplicate names are deliberately allowed (§2.2): a primary and a standby account at
        // one provider is a legitimate pool, and is exactly what an application configures
        // when a sending domain is rate-limited. Members are identified by position, never
        // by name.
        $this->mailers = array_values($mailers);
    }

    /**
     * Composed from its members, because the only place a pool's own name appears is an
     * attempt recorded by an *outer* pool — where 'pool' alone would say nothing about which
     * of two nested pools had failed.
     */
    public function mailerName(): string
    {
        return sprintf('pool(%s)', implode('+', array_map(
            static fn (\Monad\Clarity\Services\Mail $mailer): string => $mailer->mailerName(),
            $this->mailers
        )));
    }

    /**
     * Offer the message to each mailer in turn until one takes it.
     *
     * @throws MailException scoped `Message` the moment a mailer says the message itself is
     *     wrong — every later member would say the same, so none is tried. Scoped `Mailer`
     *     when every member has been tried and none could take it.
     */
    public function send(Message $message): SentMessage
    {
        /** @var list<Attempt> $attempts */
        $attempts = [];

        foreach ($this->mailers as $mailer) {
            try {
                // Only MailException is caught. A TypeError, or any other bug inside an
                // adapter, is not a delivery failure and must not be quietly failed over to
                // six more mailers — that would turn one broken adapter into a defect that
                // never surfaces until every member is broken.
                $sent = $mailer->send($message);
            } catch (MailException $failure) {
                $attempts[] = Attempt::failed($mailer->mailerName(), $failure);

                if ($failure->scope === FailureScope::Message) {
                    throw $this->messageIsWrong($mailer, $failure, $attempts);
                }

                continue;
            }

            return $this->succeeded($sent, $attempts);
        }

        throw $this->everyMailerFailed($attempts);
    }

    /**
     * Fold the winner's own result into the trail of everyone tried before it.
     *
     * The member's `attempts` are spliced in rather than replaced by a synthesised success,
     * so a nested pool's internal trail survives into the outer one — and `mailer` stays the
     * leaf that really sent, not the pool that delegated.
     *
     * @param list<Attempt> $attempts
     */
    private function succeeded(SentMessage $sent, array $attempts): SentMessage
    {
        $theirs = $sent->attempts !== []
            ? $sent->attempts
            // A SentMessage built by hand may carry no trail; the invariant that `attempts`
            // ends with the mailer that succeeded is this class's to keep either way.
            : [Attempt::succeeded($sent->mailer)];

        return new SentMessage(
            $sent->mailer,
            $sent->providerMessageId,
            [...$attempts, ...$theirs],
            $sent->raw
        );
    }

    /**
     * @param list<Attempt> $attempts
     */
    private function messageIsWrong(
        \Monad\Clarity\Services\Mail $mailer,
        MailException $failure,
        array $attempts,
    ): MailException {
        $remaining = count($this->mailers) - count($attempts);

        return new MailException(
            sprintf(
                '%s refused the message itself, so the %s not tried: every mailer would refuse it '
                . 'for the same reason, and asking them would cost a round trip each to reach the '
                . 'same answer. %s',
                $mailer->mailerName(),
                $remaining === 1 ? 'remaining mailer was' : sprintf('remaining %d mailers were', $remaining),
                $failure->getMessage()
            ),
            FailureScope::Message,
            $failure
        );
    }

    /**
     * @param list<Attempt> $attempts
     */
    private function everyMailerFailed(array $attempts): MailException
    {
        $reasons = array_map(
            static fn (Attempt $attempt): string => sprintf('%s: %s', $attempt->mailer, $attempt->reason()),
            $attempts
        );

        return new MailException(
            sprintf(
                'All %d mailers in the pool failed, in order — %s',
                count($attempts),
                implode(' | ', $reasons)
            ),
            FailureScope::Mailer,
            // The first failure, not the last: it is the primary mailer's, and the primary is
            // the one whose health the operator is actually being told about.
            $attempts[0]->failure
        );
    }

    /**
     * The members, in the order they are tried.
     *
     * @return list<\Monad\Clarity\Services\Mail>
     */
    public function mailers(): array
    {
        return $this->mailers;
    }
}
