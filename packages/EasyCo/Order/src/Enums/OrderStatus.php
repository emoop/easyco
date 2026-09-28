<?php

namespace EasyCo\Order\Enums;

/**
 * Order lifecycle status. `order-lifecycle-design.md` is the design: §1 defines
 * the six values below, §2.1 is the human-readable mirror of the transition
 * matrix whose only home is the map in this file.
 *
 * WHAT EACH VALUE MEANS (one line each, §1):
 * - PLACED    — the order exists and no merchant has touched it yet.
 * - CONFIRMED — the merchant has accepted the order and is preparing it.
 * - SHIPPED   — the goods have left the merchant's hands: handed to a carrier,
 *               or on their way to a courier office.
 * - DELIVERED — the customer has the goods.
 * - CANCELLED — the order was called off, or the parcel was refused or lost,
 *               before it reached the customer. Terminal.
 * - REFUNDED  — the goods reached the customer and then came back: every
 *               remaining unit is back. Terminal.
 *
 * THIS ENUM STATES WHICH MOVES ARE LEGAL AND PERFORMS NONE. `canTransitionTo()`
 * and `isTerminal()` read one private map and change nothing: the mutator that
 * changes a status belongs on `Order`, and the transaction that wraps it belongs
 * to the app layer (design doc §5) — this file answers "is that move legal?" and
 * nothing else. `OrderStatusTest` walks all 36 (from, to) pairs against §2.1's
 * table, so the prose and this map cannot drift apart silently.
 *
 * NO VALUE IS EVER ADDED FOR A MONEY FACT. "Has the money arrived?" is
 * `Payment.status`'s question and "is this line's own financial fact settled?"
 * is `SaleLine.status`'s — three statuses, three questions, none derivable from
 * another, which is why `Order.status` answers only *has the merchant fulfilled
 * this order?*. `fulfilled` is the removed counter-example: one word for both
 * "prepared, but still in my shop" (where a cancellation is still honest) and
 * "gone, in someone else's hands" (where it is not), so CONFIRMED and SHIPPED
 * split it — and no row still holding the retired word is ever rewritten by
 * guesswork (`2026_09_28_000001_guard_no_fulfilled_orders`).
 */
enum OrderStatus: string
{
    case PLACED = 'placed';
    case CONFIRMED = 'confirmed';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    /**
     * The one transition map: every status value => the statuses a transition
     * may move it to, and nothing else. Seven moves in total — no
     * self-transition, no skipping a step, no backwards move, nothing out of a
     * terminal status — which is exactly the seven rows of §2.1's table.
     *
     * @var array<string, list<self>>
     */
    private const TRANSITIONS = [
        self::PLACED->value => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED->value => [self::SHIPPED, self::CANCELLED],
        self::SHIPPED->value => [self::DELIVERED, self::CANCELLED],
        self::DELIVERED->value => [self::REFUNDED],
        self::CANCELLED->value => [],
        self::REFUNDED->value => [],
    ];

    /**
     * May a status change to `$to`? The seven legal moves answer true; every
     * other pair of the 6×6 — the six same-status pairs included — false.
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->value], true);
    }

    /**
     * CANCELLED and REFUNDED are the two ways an order ends: no move leaves
     * either. Read from the same map as `canTransitionTo()` rather than from a
     * second list, so a terminal status cannot be one that still has an exit.
     */
    public function isTerminal(): bool
    {
        return self::TRANSITIONS[$this->value] === [];
    }
}
