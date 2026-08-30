<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use InvalidArgumentException;

/**
 * How often a subscription charges: an interval and how many of them fall between charges.
 * Monthly is `Month, 1`; quarterly is `Month, 3`; a fortnightly plan is `Week, 2`.
 *
 * The same shape describes a free trial, so this class serves both — a fourteen-day trial is
 * `Day, 14`. That is not a coincidence to be tidied away behind a second class: a trial is a
 * period before the first charge, measured exactly as the period between charges is.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class BillingCycle
{
    public function __construct(
        public BillingInterval $interval,
        public int $frequency = 1,
    ) {
        if ($frequency < 1) {
            throw new InvalidArgumentException(sprintf(
                'A billing cycle repeats at least once per interval, so its frequency must be at least 1, got %d.',
                $frequency
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->interval === $other->interval && $this->frequency === $other->frequency;
    }

    /**
     * The cycle as it reads in an exception or a log line — "every month", "every 3 months".
     */
    public function describe(): string
    {
        return $this->frequency === 1
            ? sprintf('every %s', $this->interval->value)
            : sprintf('every %d %ss', $this->frequency, $this->interval->value);
    }
}
