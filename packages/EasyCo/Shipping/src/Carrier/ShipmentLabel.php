<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;

/**
 * The carrier's answer to a ShipmentRequest (shipping-domain-design.md §6): the
 * shipment it created and where to get the waybill.
 *
 *  - idempotencyReference: the request's reference, echoed back, so a caller
 *    can prove which request this answers (a retried request returns the SAME
 *    label, with the same tracking number).
 *  - trackingNumber: the carrier's waybill / parcel number; required.
 *  - labelUrl / labelPdf: where the printable waybill is, or its bytes; either,
 *    both or neither (some carriers hand the document out later).
 */
final class ShipmentLabel
{
    public function __construct(
        public readonly string $carrierCode,
        public readonly string $idempotencyReference,
        public readonly string $trackingNumber,
        public readonly ?string $labelUrl = null,
        public readonly ?string $labelPdf = null,
    ) {
        $class = self::class;

        CarrierValue::nonEmpty($class, 'carrierCode', $carrierCode, 64);
        CarrierValue::nonEmpty($class, 'idempotencyReference', $idempotencyReference, ShipmentRequest::MAX_REFERENCE_LENGTH);
        CarrierValue::nonEmpty($class, 'trackingNumber', $trackingNumber, 128);

        if ($labelUrl !== null && preg_match('#^https?://\S+$#D', $labelUrl) !== 1) {
            throw InvalidCarrierDataException::field($class, 'labelUrl', 'must be an http(s) URL');
        }

        if ($labelPdf !== null && $labelPdf === '') {
            throw InvalidCarrierDataException::field($class, 'labelPdf', 'must not be empty when given');
        }
    }
}
