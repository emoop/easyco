<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;

/**
 * What a carrier is told in order to quote a rate (shipping-domain-design.md §6):
 * where the parcel goes and what it is, as plain values.
 *
 * IT CARRIES NO CUSTOMER IDENTITY (no name, phone, email, street, account) AND NO
 * COST PRICES (no unit cost, no margin) — by design, and enforced by there being
 * no field for them. A rate does not need to know who is buying, and a carrier
 * must never see what the shop paid.
 *
 *  - countryCode, settlement, isPickupPoint: the destination's shape (§6).
 *  - currency, goodsValueMinor: the goods value AFTER discounts, the declared
 *    value couriers insure by. Zero is valid (a fully discounted cart).
 *  - cashOnDeliveryMinor — PROPOSED, NOT IN §6 AS WRITTEN: the amount the courier
 *    must collect from the recipient, or null when the order is prepaid. §6 reuses
 *    the goods value "for insurance and cash-on-delivery", but the two differ
 *    (a prepaid parcel is insured and collects nothing), and Bulgarian couriers
 *    price the cash-on-delivery service by the collected amount.
 *  - weightGrams, lengthMm, widthMm, heightMm — nullable: null means UNKNOWN, not
 *    zero (Variation's physical fields are optional, and a guessed zero would be
 *    priced as a weightless parcel). The three dimensions are all known or all
 *    unknown. §6 says "summed dimensions", which has no physical meaning for
 *    several items; these are the caller's best estimate of the parcel.
 */
final class ShippingContext
{
    public function __construct(
        public readonly string $countryCode,
        public readonly string $settlement,
        public readonly bool $isPickupPoint,
        public readonly string $currency,
        public readonly int $goodsValueMinor,
        public readonly ?int $cashOnDeliveryMinor = null,
        public readonly ?int $weightGrams = null,
        public readonly ?int $lengthMm = null,
        public readonly ?int $widthMm = null,
        public readonly ?int $heightMm = null,
    ) {
        $class = self::class;

        CarrierValue::country($class, 'countryCode', $countryCode);
        CarrierValue::nonEmpty($class, 'settlement', $settlement);
        CarrierValue::currency($class, 'currency', $currency);
        CarrierValue::nonNegative($class, 'goodsValueMinor', $goodsValueMinor);
        CarrierValue::nonNegativeOrNull($class, 'cashOnDeliveryMinor', $cashOnDeliveryMinor);
        CarrierValue::nonNegativeOrNull($class, 'weightGrams', $weightGrams);
        CarrierValue::nonNegativeOrNull($class, 'lengthMm', $lengthMm);
        CarrierValue::nonNegativeOrNull($class, 'widthMm', $widthMm);
        CarrierValue::nonNegativeOrNull($class, 'heightMm', $heightMm);

        $known = count(array_filter([$lengthMm, $widthMm, $heightMm], static fn (?int $d): bool => $d !== null));

        if ($known !== 0 && $known !== 3) {
            throw InvalidCarrierDataException::field($class, 'lengthMm/widthMm/heightMm', 'must be all given or all null');
        }
    }

    public function isCashOnDelivery(): bool
    {
        return $this->cashOnDeliveryMinor !== null;
    }

    public function hasDimensions(): bool
    {
        return $this->lengthMm !== null;
    }

    /**
     * A stable, canonical array of every field — the input of the quote cache key
     * (shipping-domain-design.md §6, "Caching of quotes"). Fixed key order, so two
     * equal contexts always serialize identically.
     *
     * @return array<string, int|string|bool|null>
     */
    public function toCanonicalArray(): array
    {
        return [
            'country' => $this->countryCode,
            'settlement' => $this->settlement,
            'pickup' => $this->isPickupPoint,
            'currency' => $this->currency,
            'goods' => $this->goodsValueMinor,
            'cod' => $this->cashOnDeliveryMinor,
            'weight' => $this->weightGrams,
            'length' => $this->lengthMm,
            'width' => $this->widthMm,
            'height' => $this->heightMm,
        ];
    }
}
