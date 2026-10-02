<?php

namespace App\Services\Exceptions;

use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * A refund would break one of the hard caps of shipping-domain-design.md §7.2.2.
 * Thrown inside the order-locked transaction, before any refund write, so the
 * whole operation (goods lines, restock) rolls back and nothing is recorded.
 *
 * The message is a translated sentence (orders.refund_caps.*, en + bg) naming
 * WHICH cap and the room left; the caller (runOrderAction) shows it as a refusal
 * notice, never a 500. It extends InvalidArgumentException because the refund
 * path has always refused an over-refund with one, so existing callers that
 * catch that keep working.
 */
final class RefundCapExceededException extends InvalidArgumentException
{
    public const LINE = 'line';

    public const SHIPPING = 'shipping';

    public const TOTAL = 'total';

    public const DEDUCTION = 'deduction';

    /** The shipping reduction of a partial return on a PENDING payment (§7.2.4). */
    public const PENDING_SHIPPING = 'pending_shipping';

    private function __construct(
        private readonly string $cap,
        private readonly Money $room,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forCap(string $cap, Money $room, ?string $saleLineId = null): self
    {
        $room = $room->isNegative() ? Money::zero($room->currency()) : $room;

        return new self($cap, $room, __('orders.refund_caps.'.$cap, [
            'room' => $room->decimalValue().' '.$room->currency()->code(),
            'line' => $saleLineId ?? '',
        ]));
    }

    /** One of the cap constants. */
    public function cap(): string
    {
        return $this->cap;
    }

    /** What can still be refunded under this cap (never negative). */
    public function room(): Money
    {
        return $this->room;
    }
}
