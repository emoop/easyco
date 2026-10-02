<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;
use EasyCo\Shipping\ShippingCode;

/**
 * Everything a carrier needs to create ONE shipment and its label
 * (shipping-domain-design.md §6). Unlike ShippingContext this DOES carry the
 * recipient — a waybill cannot be written without one.
 *
 * THE IDEMPOTENCY REFERENCE. Every request carries one, built from the order id
 * and an attempt number: "<orderId>#<attempt>". It exists so that a retried call
 * (a timeout whose answer was lost, a double click, a queue retry) can never
 * create two paid shipments with the courier. See ShipmentLabelProvider for the
 * obligation this puts on every implementation. A new attempt number is a
 * deliberate new shipment (the first was cancelled or lost), chosen by the
 * merchant's action, never by an automatic retry.
 *
 * Delivery is EITHER to an office (pickupPointReference given, no street) OR to
 * an address (addressLine given, no pickup point) — both or neither is refused.
 */
final class ShipmentRequest
{
    public const MAX_REFERENCE_LENGTH = 64;

    public readonly string $idempotencyReference;

    public function __construct(
        public readonly string $orderId,
        public readonly int $attempt,
        public readonly string $carrierCode,
        public readonly string $serviceCode,
        public readonly string $recipientName,
        public readonly string $recipientPhone,
        public readonly string $countryCode,
        public readonly string $settlement,
        public readonly string $currency,
        public readonly int $goodsValueMinor,
        public readonly ?string $addressLine = null,
        public readonly ?string $pickupPointReference = null,
        public readonly ?string $postalCode = null,
        public readonly ?int $cashOnDeliveryMinor = null,
        public readonly ?int $weightGrams = null,
    ) {
        $class = self::class;

        CarrierValue::nonEmpty($class, 'orderId', $orderId, 40);

        if ($attempt < 1) {
            throw InvalidCarrierDataException::field($class, 'attempt', 'must be at least 1');
        }

        if (! ShippingCode::isValid($carrierCode)) {
            throw InvalidCarrierDataException::field($class, 'carrierCode', 'must be a valid carrier code');
        }

        CarrierValue::nonEmpty($class, 'serviceCode', $serviceCode, 64);
        CarrierValue::nonEmpty($class, 'recipientName', $recipientName);
        CarrierValue::nonEmpty($class, 'recipientPhone', $recipientPhone, 32);
        CarrierValue::country($class, 'countryCode', $countryCode);
        CarrierValue::nonEmpty($class, 'settlement', $settlement);
        CarrierValue::currency($class, 'currency', $currency);
        CarrierValue::nonNegative($class, 'goodsValueMinor', $goodsValueMinor);
        CarrierValue::nonNegativeOrNull($class, 'cashOnDeliveryMinor', $cashOnDeliveryMinor);
        CarrierValue::nonNegativeOrNull($class, 'weightGrams', $weightGrams);

        $hasAddress = $addressLine !== null && trim($addressLine) !== '';
        $hasPoint = $pickupPointReference !== null && trim($pickupPointReference) !== '';

        if ($hasAddress === $hasPoint) {
            throw InvalidCarrierDataException::field($class, 'addressLine/pickupPointReference', 'must be exactly one of the two');
        }

        $this->idempotencyReference = $orderId.'#'.$attempt;

        if (strlen($this->idempotencyReference) > self::MAX_REFERENCE_LENGTH) {
            throw InvalidCarrierDataException::field($class, 'idempotencyReference', 'must not be longer than '.self::MAX_REFERENCE_LENGTH.' characters');
        }
    }

    public function isPickupPoint(): bool
    {
        return $this->pickupPointReference !== null && trim($this->pickupPointReference) !== '';
    }
}
