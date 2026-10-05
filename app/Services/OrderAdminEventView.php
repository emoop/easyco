<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * One row of an Order's admin history — order-lifecycle-design.md §6.3, §10
 * stage 3, built only by OrderAdminReader::forOrder() from the order_events
 * rows themselves.
 *
 * EVERY VALUE IS THE STORED ONE, READ BACK VERBATIM — type/fromStatus/
 * toStatus/reason are plain strings exactly as the row holds them, never
 * re-derived: §6.3's rule is that the timeline renders what the writer wrote, so
 * it can disagree with `orders.status` only by being LONGER, never by being
 * wrong about it. Typing `type` as a string (rather than App\Enums\
 * OrderEventType) is that same decision: this DTO displays history, and a row
 * written by an older version of the code must still render instead of throwing
 * on a value the current enum no longer has.
 *
 * staffName IS THE ROW'S OWN SNAPSHOT (§6.1) — never a join to `staff`, which is
 * what keeps the reader at one query. NULL means a console/job caller recorded
 * the event, and the View page's own "System" wording is where that shows up.
 *
 * occurredAt is the instant the fact happened, parsed from the column's real
 * TIMESTAMP type (the database, not this class, guarantees it parses).
 *
 * movedLines IS WHAT THE EVENT'S TRANSACTION MOVED, when it has one — one entry per
 * sale line of that transaction, read for ALL events in a single query by forOrder()
 * (never one per event). Each entry is `{kind: 'return'|'removed'|'added', name, sku,
 * quantity, attributes}`; see OrderAdminReader::movedLinesByTransaction(). [] for an
 * event with no transaction.
 *
 * INERT SO FAR: nothing renders this list yet — the timeline section is §10
 * stage 7, and §6.3's other half (the Orders LIST must never read events) is why
 * only forOrder() builds it.
 */
final class OrderAdminEventView
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $fromStatus,
        public readonly ?string $toStatus,
        public readonly ?string $reason,
        public readonly ?string $transactionId,
        public readonly ?string $staffName,
        public readonly DateTimeImmutable $occurredAt,
        /** @var list<array{kind: string, name: ?string, sku: ?string, quantity: int, attributes: list<string>}> */
        public readonly array $movedLines = [],
        /** The CALENDAR DAY ('Y-m-d', store timezone) the customer announced a return; a `returned` event only. */
        public readonly ?string $announcedReturnOn = null,
    ) {
    }
}
