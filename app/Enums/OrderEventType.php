<?php

namespace App\Enums;

/**
 * The six facts an order's own history records — order-lifecycle-design.md
 * §6.1's `order_events.type`, and the single vocabulary
 * App\Services\OrderEventRecorder writes and OrderAdminReader::forOrder()
 * reads back, so "which kind of fact is this" is never a stringly-typed guess
 * at either end (the same reason App\Enums\CatalogLookupKind exists).
 *
 * WHAT EACH VALUE MEANS, and what it carries in from_status/to_status:
 * - STATUS_CHANGED    — a §5.1 transition ran. from_status and to_status are
 *                       both set; that pair is the whole point of the row.
 * - PAYMENT_CONFIRMED — the money was recorded as received (§4.3). No status
 *                       moves, so both statuses are NULL — the same shape every
 *                       "a real event, no transition" case takes (§6.1).
 * - RETURNED          — goods came back (§7.2): `transaction_id` points at the
 *                       return's own Transaction rather than copying its lines.
 * - REFUNDED          — money went back (§7.3). Both statuses NULL.
 * - PAYMENT_VOIDED    — a pending payment row was voided and reissued (§7.3).
 * - NOTE_ADDED        — an internal note an operator left on the order: the one
 *                       value that is not a change to the order at all. Both
 *                       statuses NULL, and `reason` holds the note itself.
 * - EDITED            — order-editing-design.md §9: an OrderEditor edit ran.
 *                       Both statuses NULL (an edit is not a transition,
 *                       §2), `transaction_id` points at the edit's own new
 *                       Transaction, `reason` holds the operator's own
 *                       optional note.
 * - TRACKING_RECORDED — order-editing-design.md §3: a courier tracking
 *                       number was entered or corrected while `shipped`.
 *                       Both statuses NULL, `reason` holds the tracking
 *                       number itself verbatim. A correction writes a
 *                       second row rather than rewriting the first
 *                       (append-only) — the admin view shows the latest one.
 *
 * STORED AS A PLAIN STRING COLUMN, like every other enum column in this schema
 * (`orders.status`, `operational_sales_sale_lines.type`) — never a native DB
 * enum.
 */
enum OrderEventType: string
{
    case STATUS_CHANGED = 'status_changed';
    case PAYMENT_CONFIRMED = 'payment_confirmed';
    case RETURNED = 'returned';
    case REFUNDED = 'refunded';
    case REFUND_OWED = 'refund_owed';
    case PAYMENT_VOIDED = 'payment_voided';
    case NOTE_ADDED = 'note_added';
    case EDITED = 'edited';
    case TRACKING_RECORDED = 'tracking_recorded';
}
