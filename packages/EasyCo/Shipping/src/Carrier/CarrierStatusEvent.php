<?php

namespace EasyCo\Shipping\Carrier;

use DateTimeImmutable;

/** One tracking event as a courier reports it (shape only in COUR-1a; mapped to the shipment status in COUR-3b / COUR-5). */
final class CarrierStatusEvent
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $carrierEvent,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ?int $codCollectedMinor = null,
    ) {
        CarrierValue::nonEmpty(self::class, 'externalId', $externalId, 64);
        CarrierValue::nonEmpty(self::class, 'carrierEvent', $carrierEvent, 64);
    }
}
