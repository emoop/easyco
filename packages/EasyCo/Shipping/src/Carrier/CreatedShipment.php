<?php

namespace EasyCo\Shipping\Carrier;

/** What a courier answered to a create: its ids and the waybill number(s). Shape only (COUR-1a); the shipments domain uses it from COUR-3a. */
final class CreatedShipment
{
    /** @param list<string> $parcelIds the courier's parcel ids / numbers, first = the waybill number where it has one */
    public function __construct(
        public readonly string $carrierCode,
        public readonly string $idempotencyReference,
        public readonly string $externalId,
        public readonly string $trackingNumber,
        public readonly array $parcelIds = [],
    ) {
        CarrierValue::nonEmpty(self::class, 'carrierCode', $carrierCode, 64);
        CarrierValue::nonEmpty(self::class, 'idempotencyReference', $idempotencyReference, ShipmentRequest::MAX_REFERENCE_LENGTH);
        CarrierValue::nonEmpty(self::class, 'externalId', $externalId, 64);
        CarrierValue::nonEmpty(self::class, 'trackingNumber', $trackingNumber, 128);
    }
}
