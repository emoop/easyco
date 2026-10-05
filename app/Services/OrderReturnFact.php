<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * One return of an order, as its `returned` history row states it: when it was recorded
 * (the goods came back; an instant, UTC), the CALENDAR DAY the customer announced it
 * ('Y-m-d', no time, no timezone — null when nobody entered it), and — only when the order
 * was delivered — the calendar days (store timezone) from the delivery day to each. Negative
 * when that day precedes the delivery day; never clamped.
 */
final class OrderReturnFact
{
    public function __construct(
        public readonly string $eventId,
        public readonly ?string $transactionId,
        public readonly DateTimeImmutable $recordedAt,
        public readonly ?string $announcedOn,
        public readonly ?int $daysFromDeliveryToAnnounced,
        public readonly ?int $daysFromDeliveryToRecorded,
    ) {
    }
}
