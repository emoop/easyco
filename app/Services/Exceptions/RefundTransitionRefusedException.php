<?php

namespace App\Services\Exceptions;

use EasyCo\Payment\Exceptions\InvalidRefundTransitionException;
use RuntimeException;

/**
 * Paying out or cancelling an OWED refund was refused (shipping-domain-design.md
 * §7.2.16), by name. The reason is one of the domain's own
 * (InvalidRefundTransitionException: not_owed, payout_in_future,
 * bank_reference_required, reason_required, actor_required) or one of this
 * service's: `refund_not_found`, `refund_lines_unlinked` (an OWED refund whose
 * goods lines cannot be matched to the return that created it — nothing is
 * guessed), `storno_mismatch` (the refund's recorded lines disagree with the
 * ledger). The message is a translated sentence (orders.refund_transition.*); the
 * whole operation has been rolled back when this is thrown.
 */
final class RefundTransitionRefusedException extends RuntimeException
{
    public const REFUND_NOT_FOUND = 'refund_not_found';

    public const LINES_UNLINKED = 'refund_lines_unlinked';

    public const STORNO_MISMATCH = 'storno_mismatch';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(__('orders.refund_transition.'.$reason), 0, $previous);
    }

    public static function fromDomain(InvalidRefundTransitionException $e): self
    {
        return new self($e->reason, $e);
    }
}
