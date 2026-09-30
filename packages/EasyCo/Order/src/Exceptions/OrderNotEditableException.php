<?php

namespace EasyCo\Order\Exceptions;

use EasyCo\Order\Enums\OrderStatus;
use RuntimeException;

/**
 * Thrown by Order's own edit mutators — reviseTotals(), reviseDelivery() —
 * when the order's current status is not placed/confirmed
 * (order-editing-design.md §1's E1: "editing is legal only while
 * Order.status is placed or confirmed"). Mirrors
 * InvalidOrderTransitionException's exact shape, for the same reason that
 * class states in its own docblock: a caller deciding whether a refusal is
 * a bug or a stale page reads status() instead of parsing a message.
 *
 * NOT InvalidOrderTransitionException itself, despite the shared shape —
 * an edit is not a transition (§2's own "status itself is untouched by
 * either mutator"): this refusal names ONE status, the order's own current
 * one, never a from/to pair, because there is no "to" for an edit to have
 * been refused toward.
 *
 * BASE CLASS: RuntimeException, the same choice
 * InvalidOrderTransitionException's own docblock argues for and the same
 * reasoning applies unchanged — a refused edit is a fact about this order
 * under this concurrency, not a programming error in the caller.
 */
final class OrderNotEditableException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly OrderStatus $status,
        private readonly bool $becauseOfSettledPayment = false,
    ) {
        parent::__construct($message);
    }

    public static function because(OrderStatus $status): self
    {
        return new self(
            "Order cannot be edited while its status is \"{$status->value}\": editing is legal only while placed or confirmed.",
            $status,
        );
    }

    /**
     * order-editing-design.md §6 (E3), stage 3b — the OTHER reason an edit is
     * refused: the status would allow it, but money has already been
     * captured, so the whole edit is refused (no refund-difference logic, no
     * top-up, ever). One exception type for both refusals so a caller that
     * only needs "this order cannot be edited right now" catches one class;
     * isBecauseOfSettledPayment() tells the two apart for a caller that words
     * them differently. status() is still the order's own current status.
     */
    public static function becausePaymentSettled(OrderStatus $status): self
    {
        return new self(
            "Order cannot be edited: a payment for it has already settled (order status \"{$status->value}\"). Editing is legal only before any money is captured.",
            $status,
            becauseOfSettledPayment: true,
        );
    }

    /** True when the refusal is E3's settled-payment gate rather than the status rule. */
    public function isBecauseOfSettledPayment(): bool
    {
        return $this->becauseOfSettledPayment;
    }

    /** The status the order stood in when the refused edit was attempted. */
    public function status(): OrderStatus
    {
        return $this->status;
    }
}
