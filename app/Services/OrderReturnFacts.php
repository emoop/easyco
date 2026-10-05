<?php

namespace App\Services;

use App\Settings\StoreTimezone;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The return facts of ONE order (shipping-domain-design.md §7.2.6: facts, not rules):
 * when it was delivered, and each return it had. Nothing here is a verdict — no deadline,
 * no "late", no "within the window": a shop's own policy reads these numbers.
 *
 * Instants (`deliveredAt`, a return's `recordedAt`) are UTC, as stored. Every DAY COUNT is in
 * CALENDAR DAYS of the STORE timezone — the day of the later date minus the day of the earlier
 * one — which is what a shop's own "14 days" means. A count is negative when the later date
 * falls on an earlier day (a delivery recorded late); it is never clamped.
 */
final class OrderReturnFacts
{
    /** @param list<OrderReturnFact> $returns oldest first */
    public function __construct(
        public readonly ?DateTimeImmutable $deliveredAt,
        public readonly array $returns,
        public readonly DateTimeZone $zone,
    ) {
    }

    /** The store-local calendar day ('Y-m-d') of the delivery; null when never delivered. */
    public function deliveredOn(): ?string
    {
        return $this->deliveredAt?->setTimezone($this->zone)->format('Y-m-d');
    }

    /** Calendar days (store timezone) from the delivery day to the day of $asOf; null when never delivered. */
    public function daysSinceDelivery(DateTimeImmutable $asOf): ?int
    {
        $delivered = $this->deliveredOn();

        return $delivered === null ? null : StoreTimezone::daysBetween($delivered, $asOf->setTimezone($this->zone)->format('Y-m-d'));
    }
}
