<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A change to a subscription that has been agreed but has not happened yet.
 *
 * This is the object that makes the cancellation trap visible. When a customer cancels, the
 * gateway does not end the subscription — the customer has paid through the end of the
 * period, so the subscription stays active and a cancellation is scheduled for the date that
 * period ends. A merchant who revokes access on the click has taken away something already
 * paid for; one who waits for the status to change has it right.
 *
 * $resumeAt only ever accompanies a pause, and only when the merchant named a date. A pause
 * with no resume date runs until the merchant resumes it by hand.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class ScheduledChange
{
    public function __construct(
        public ScheduledChangeAction $action,
        public DateTimeImmutable $effectiveAt,
        public ?DateTimeImmutable $resumeAt = null,
    ) {
        if ($resumeAt !== null && $action !== ScheduledChangeAction::Pause) {
            throw new InvalidArgumentException(sprintf(
                'Only a scheduled pause carries a resume date; this one is a %s.',
                $action->value
            ));
        }
    }
}
