<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Checkout;

/**
 * The complete lifecycle of a transaction: `pending` on creation (ReleaseNotes §9.6.2) and
 * the three terminal outcomes §9.6.5 enumerates. Exactly four cases — no gateway-specific
 * state leaks in here, because a status the ledger cannot interpret uniformly across nine
 * gateways is not a status, it is raw payload (which `raw` on each value object carries).
 *
 * A refund is deliberately NOT a status. §9.6.5 names only these four, and a partially
 * refunded transaction has no honest single-status answer — refunds are their own records
 * (`checkout_refunds`), so a transaction that succeeded stays `success` for its lifetime
 * while refunds accumulate against it.
 *
 * @package Monad\Clarity\Services\Checkout
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether this status can still change. Only `pending` can — the ledger uses this to
     * refuse status transitions away from an already-settled transaction.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
