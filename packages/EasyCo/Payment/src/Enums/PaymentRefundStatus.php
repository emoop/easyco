<?php

namespace EasyCo\Payment\Enums;

/**
 * A PaymentRefund's state — shipping-domain-design.md §7.2.5.
 *
 * OFFLINE methods (cash on delivery, bank transfer) move through
 *  - OWED: the refund was decided and is dated, the money has NOT left yet.
 *    What every offline refund is created as.
 *  - PAID_OUT: the merchant confirmed he paid it back (R2).
 *  - CANCELLED: an OWED refund withdrawn before payout (R2). Moved no money.
 * ONLINE methods (a future provider, R5) move through
 *  - REQUESTED, then COMPLETED or FAILED. FAILED moved no money.
 *
 * Which of them COUNT toward the refund caps is a rule of its own, see
 * counting(): everything except CANCELLED and FAILED.
 */
enum PaymentRefundStatus: string
{
    case OWED = 'owed';
    case PAID_OUT = 'paid_out';
    case CANCELLED = 'cancelled';
    case REQUESTED = 'requested';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /**
     * The states in which a refund's money is spoken for — owed, paid out,
     * requested or completed (§7.2.2). A CANCELLED or FAILED refund moved no
     * money and frees its room.
     *
     * @return list<self>
     */
    public static function counting(): array
    {
        return [self::OWED, self::PAID_OUT, self::REQUESTED, self::COMPLETED];
    }

    public function counts(): bool
    {
        return in_array($this, self::counting(), true);
    }
}
