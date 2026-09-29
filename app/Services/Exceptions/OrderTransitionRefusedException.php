<?php

namespace App\Services\Exceptions;

use App\Enums\OrderRefusalReason;
use RuntimeException;

/**
 * Thrown by App\Services\OrderStatusChanger when a service-owned guard
 * refuses a transition that EasyCo\Order\Enums\OrderStatus's own matrix
 * would otherwise allow (order-lifecycle-design.md §5.2 step 4) — R9's
 * "the bank transfer must have arrived" is the first of these.
 *
 * IT CARRIES THE REASON AS A VALUE, NOT ONLY INSIDE A SENTENCE — the same
 * shape EasyCo\Order\Exceptions\InvalidOrderTransitionException already
 * takes for the domain's own refusals: a caller that has to render this for
 * a merchant, or branch on which guard fired, reads reason() instead of
 * parsing a message. Rendering the merchant-facing sentence itself is
 * App\Services\...\OrderTransitionRefusalMessage's job (mirroring
 * ProductDeletionRefusalMessage's own split between "the domain/service
 * carries the fact" and "the app layer words it") — not built in this
 * stage, since no caller renders a refusal yet (no Filament action exists
 * for these transitions).
 *
 * BASE CLASS: RuntimeException, the same choice
 * InvalidOrderTransitionException documents for the identical reason: this
 * describes a request that this order, under this state, cannot currently
 * be granted — a fact about the order, not a programming error in the
 * caller — never LogicException or InvalidArgumentException.
 */
final class OrderTransitionRefusedException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly OrderRefusalReason $reason,
    ) {
        parent::__construct($message);
    }

    /**
     * R9: ship() refused because the order's payment method is bank
     * transfer and none of its payments has settled yet
     * (order-lifecycle-design.md §2.2 R9).
     */
    public static function becauseBankTransferNotSettled(string $orderId): self
    {
        return new self(
            "Order \"{$orderId}\" cannot ship: its payment method is bank transfer and no payment has settled yet.",
            OrderRefusalReason::BANK_TRANSFER_NOT_SETTLED,
        );
    }

    /** Which guard refused the transition — the fact a caller renders or branches on. */
    public function reason(): OrderRefusalReason
    {
        return $this->reason;
    }
}
