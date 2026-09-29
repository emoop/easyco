<?php

namespace App\Enums;

/**
 * Why App\Services\OrderStatusChanger refused a transition that
 * EasyCo\Order\Enums\OrderStatus itself would allow — order-lifecycle-
 * design.md §2.2's R9 is the first of these: a service-owned guard reading a
 * fact the aggregate cannot see (§5.2 step 4), never a domain invariant, so
 * it lives here rather than beside InvalidOrderTransitionException in the
 * Order package.
 *
 * GREW BY ONE REAL GUARD AT A TIME — no speculative set for guards that do
 * not exist yet. R9's BANK_TRANSFER_NOT_SETTLED was the first; ORDER_NOT_
 * CANCELLABLE/ORDER_NOT_RETURNABLE (order-lifecycle-design.md §5.2 step 2,
 * its own stage 6b-ii part 2) are the second and third, one per legal-status
 * guard `OrderStatusChanger::cancel()`/`recordReturn()` add on top of
 * `OrderStatus`'s own matrix — each adds its own case and its own lang key
 * in the same commit, exactly as App\Enums\OrderEventType's own NOTE_ADDED
 * case was added when a real writer needed it, not ahead of one.
 *
 * RENDERED BY App\Services\Exceptions\OrderTransitionRefusedException's own
 * $reason, through one translatable key group — `orders.refusal_reasons.*`
 * (lang/en/orders.php, lang/bg/orders.php) — mirroring App\Enums\
 * OrderEventType's own `orders.event_type_options.*` pattern: the enum is
 * the exhaustive list, the lang group is its label, and a case with no entry
 * in either language is a gap a label-parity test can catch.
 */
enum OrderRefusalReason: string
{
    case BANK_TRANSFER_NOT_SETTLED = 'bank_transfer_not_settled';

    /**
     * cancel() called while the order's locked status is not one of
     * placed/confirmed/shipped — a status-set guard the aggregate itself
     * cannot enforce for a call that resolves to an EMPTY return (no
     * Order mutator runs at all in that case, so nothing there would
     * refuse it) — order-lifecycle-design.md §5.2 step 2, §7.2.
     */
    case ORDER_NOT_CANCELLABLE = 'order_not_cancellable';

    /**
     * recordReturn() called while the order's locked status is not
     * shipped/delivered — same reasoning as ORDER_NOT_CANCELLABLE above,
     * for the sibling operation.
     */
    case ORDER_NOT_RETURNABLE = 'order_not_returnable';
}
