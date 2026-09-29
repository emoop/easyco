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
 * ONE CASE FOR NOW, DELIBERATELY — no speculative set for guards that do not
 * exist yet. A future one (e.g. a stage 6b return-quantity refusal) adds its
 * own case and its own lang key in the same commit, exactly as
 * App\Enums\OrderEventType's own NOTE_ADDED case was added when a real
 * writer needed it, not ahead of one.
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
}
