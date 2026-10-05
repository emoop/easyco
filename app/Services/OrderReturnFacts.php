<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * The return facts of ONE order (shipping-domain-design.md §7.2.6: facts, not rules):
 * when it was delivered, and each return it had. Nothing here is a verdict — no deadline,
 * no "late", no "within the window": a shop's own policy reads these numbers.
 *
 * Every date is an instant in UTC, as stored; turning it into the merchant's calendar
 * day is the screen's job. Day counts are WHOLE 24-hour periods (negative if a date
 * precedes delivery), counted on the instants themselves — not calendar days in any
 * time zone.
 */
final class OrderReturnFacts
{
    /** @param list<OrderReturnFact> $returns oldest first */
    public function __construct(
        public readonly ?DateTimeImmutable $deliveredAt,
        public readonly array $returns,
    ) {
    }

    /** Whole 24-hour periods since the delivery, as of $asOf; null when the order was never delivered. */
    public function daysSinceDelivery(DateTimeImmutable $asOf): ?int
    {
        return $this->deliveredAt === null ? null : self::wholeDays($this->deliveredAt, $asOf);
    }

    public static function wholeDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 86400);
    }
}
