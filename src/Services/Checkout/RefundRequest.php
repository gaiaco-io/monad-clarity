<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

use InvalidArgumentException;

/**
 * A request to refund all or part of a settled transaction (ReleaseNotes §9.6.6).
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class RefundRequest
{
    /**
     * @param string $reference Either the checkout's reference or the payment's, whichever
     *        the merchant kept — adapters resolve one to the other when their gateway
     *        refunds against the payment rather than the checkout.
     * @param Money|null $amount Null refunds the full remaining amount. A partial refund
     *        names its amount; several may be issued against one transaction until the
     *        original total is exhausted.
     * @param string|null $idempotencyKey Guards against a double refund on retry. Defaults
     *        to a key derived from the reference and amount.
     */
    public function __construct(
        public string $reference,
        public ?Money $amount = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
        public int $timeoutSeconds = 30,
    ) {
        if (trim($reference) === '') {
            throw new InvalidArgumentException('A refund needs the reference of the transaction being refunded.');
        }

        if ($amount !== null && $amount->minorUnits === 0) {
            throw new InvalidArgumentException('A refund of zero is not a refund. Pass null to refund the full amount.');
        }

        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException(sprintf('timeoutSeconds must be at least 1, got %d.', $timeoutSeconds));
        }
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey ?? sprintf(
            'refund:%s:%s',
            $this->reference,
            $this->amount === null ? 'full' : $this->amount->minorUnits . $this->amount->currency
        );
    }
}
