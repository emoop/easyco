<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * One return of an order, as its `returned` history row states it: when it was
 * recorded (the goods came back), when the customer announced it (entered by staff,
 * null when nobody entered it), and — only when the order was delivered — how many
 * whole days after the delivery each of the two happened.
 */
final class OrderReturnFact
{
    public function __construct(
        public readonly string $eventId,
        public readonly ?string $transactionId,
        public readonly DateTimeImmutable $recordedAt,
        public readonly ?DateTimeImmutable $announcedAt,
        public readonly ?int $daysFromDeliveryToAnnounced,
        public readonly ?int $daysFromDeliveryToRecorded,
    ) {
    }
}
