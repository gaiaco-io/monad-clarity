<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use InvalidArgumentException;

/**
 * An amount expressed in a currency's smallest indivisible unit, paired with its ISO 4217
 * code — 1250 USD cents, or 1250 JPY yen.
 *
 * Minor units are the only representation Checkout accepts. A float cannot hold 0.1
 * exactly, and a decimal string invites a ×100 conversion that is simply wrong for the
 * zero-decimal currencies (JPY, KRW, VND) and the three-decimal ones (BHD, KWD, TND).
 * Integers sidestep the entire class of bug: the caller states what the gateway will
 * actually charge, and no arithmetic happens in between.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class Money
{
    public int $minorUnits;
    public string $currency;

    public function __construct(int $minorUnits, string $currency)
    {
        $currency = strtoupper($currency);

        if ($minorUnits < 0) {
            throw new InvalidArgumentException(
                sprintf('A Money amount cannot be negative; got %d. Refund amounts are positive values too.', $minorUnits)
            );
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Currency must be a three-letter ISO 4217 code, got "%s".', $currency)
            );
        }

        $this->minorUnits = $minorUnits;
        $this->currency = $currency;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minorUnits > $this->minorUnits) {
            throw new InvalidArgumentException(sprintf(
                'Subtracting %s from %s would produce a negative amount.',
                $other->describe(),
                $this->describe()
            ));
        }

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }

    public function multipliedBy(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException(sprintf('Cannot multiply an amount by a negative factor (%d).', $factor));
        }

        return new self($this->minorUnits * $factor, $this->currency);
    }

    /**
     * The amount as it reads in an exception or a log line — "1250 USD (minor units)".
     * Deliberately not a formatted display string: Checkout does not know the caller's
     * locale, and guessing one would produce a number a merchant could misread.
     */
    public function describe(): string
    {
        return sprintf('%d %s (minor units)', $this->minorUnits, $this->currency);
    }

    /**
     * @throws InvalidArgumentException if $other is in a different currency.
     */
    public function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine amounts in different currencies: %s and %s.',
                $this->currency,
                $other->currency
            ));
        }
    }
}
