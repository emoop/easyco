<?php

namespace App\Services;

use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Pricing\Money;

/**
 * Everything the refund dialogs (cancel, return, money-only) need to know about an order's money,
 * read ONCE when the dialog opens (refunds R3 part 2). Every room here comes from RefundCapGuard's
 * room readers — the very ones the service's caps use — so a form can never show a room the
 * service does not enforce. A room is correct as of the moment it was read; the service re-reads
 * under the order lock and is the final guard.
 *
 * `mode`: SETTLED (a refund record is created), PENDING (nothing was paid: goods are the computed
 * share, only a shipping reduction on a partial return, no deduction, no channel) or NONE (no
 * payment to speak of: nothing money-wise to ask).
 */
final class RefundFormContext
{
    public const SETTLED = 'settled';

    public const PENDING = 'pending';

    public const NONE = 'none';

    /**
     * @param  array<string, Money>  $lineRooms  settled only: original sale line id => goods still refundable on it (never negative)
     * @param  list<RefundChannel>  $channels  the payout channels the acting staff member may use (settled only)
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $currency,
        public readonly Money $orderShipping,
        public readonly ?Money $shippingRoom,
        public readonly ?Money $totalRoom,
        public readonly array $lineRooms,
        public readonly array $channels,
        public readonly ?RefundChannel $defaultChannel,
    ) {
    }

    public function isSettled(): bool
    {
        return $this->mode === self::SETTLED;
    }

    public function isPending(): bool
    {
        return $this->mode === self::PENDING;
    }

    /** A settled payment and no channel the staff member may pay out through: the dialog cannot submit. */
    public function hasNoChannel(): bool
    {
        return $this->isSettled() && $this->channels === [];
    }
}
