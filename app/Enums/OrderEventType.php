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
    case PAYMENT_VOIDED = 'payment_voided';
    case NOTE_ADDED = 'note_added';
}
