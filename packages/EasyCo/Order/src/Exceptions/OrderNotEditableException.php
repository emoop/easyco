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

    /** The status the order stood in when the refused edit was attempted. */
    public function status(): OrderStatus
    {
        return $this->status;
    }
}
