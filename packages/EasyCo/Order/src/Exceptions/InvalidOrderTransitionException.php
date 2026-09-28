<?php

namespace EasyCo\Order\Exceptions;

use EasyCo\Order\Enums\OrderStatus;
use RuntimeException;

/**
 * Thrown by Order's own transition mutators — confirm(), ship(),
 * deliver(), cancel(), refund() — when the move one of them performs is
 * refused: the current status may not move there, or it is already the
 * status the caller asked for. Both refusals come from one place,
 * Order::transitionTo(); the rule behind them is
 * OrderStatus::canTransitionTo()'s single map, whose human-readable mirror
 * is order-lifecycle-design.md §2.1, and §5.1 is where the mutators and
 * these two refusals are designed.
 *
 * IT CARRIES THE PAIR AS VALUES, NOT ONLY INSIDE A SENTENCE: a caller that
 * has to render a refusal for a merchant, or decide whether a refusal is a
 * bug or a double-click, reads from()/to() instead of parsing a message.
 * The message itself stays English, like every other exception's in this
 * codebase (ProductNotDeletableException's docblock makes the same point
 * about its reasons): logs, dumps and support tickets carry it, and
 * localised text is the application layer's job.
 *
 * BASE CLASS: RuntimeException — the base every sibling exception in
 * Inventory/Catalog uses (InsufficientStockException,
 * CannotPublishEmptyVariableProductException), and the one
 * Media\InvalidMediaStateTransitionException extends, the only other
 * transition refusal in the codebase. Deliberately NOT LogicException,
 * which is what Order::assignId() throws for a second id: §5.1 says this
 * exception exists so that no caller has to interpret a LogicException's
 * text, because a refused transition is a fact about this order under this
 * concurrency rather than a programming error in the caller. Not
 * InvalidArgumentException either — that is the class Order's
 * construction-time assertions throw for a malformed value, a different
 * kind of wrong.
 */
final class InvalidOrderTransitionException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly OrderStatus $from,
        private readonly OrderStatus $to,
    ) {
        parent::__construct($message);
    }

    /**
     * The current status may not move to $to: no pair outside §2.1's seven
     * legal moves is ever performed. Every same-status pair lands in the
     * other factory below, which has the clearer message.
     */
    public static function becauseCannotTransition(OrderStatus $from, OrderStatus $to): self
    {
        return new self(
            "Order cannot move from \"{$from->value}\" to \"{$to->value}\": that is not one of the legal transitions.",
            $from,
            $to,
        );
    }

    /**
     * The order is already in the requested status. The matrix makes every
     * same-status pair illegal, so this is the same refusal, with a message
     * that names the actual situation: an operator whose click was a
     * double-click reads "already confirmed" rather than "cannot move from
     * confirmed to confirmed".
     */
    public static function becauseAlreadyInStatus(OrderStatus $status): self
    {
        return new self(
            "Order is already \"{$status->value}\": no transition was performed, and none is legal.",
            $status,
            $status,
        );
    }

    /** The status the order stood in when the refused move was attempted. */
    public function from(): OrderStatus
    {
        return $this->from;
    }

    /**
     * The status the refused move asked for. Equal to from() for the
     * "already in that status" refusal — the same status twice is the
     * honest answer, because that is the pair the matrix refused.
     */
    public function to(): OrderStatus
    {
        return $this->to;
    }
}
