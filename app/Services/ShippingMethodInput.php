<?php

namespace App\Services;

use EasyCo\Pricing\Money;
use EasyCo\Shipping\Enums\ShippingDeliveryType;

/**
 * What the shipping-method form (or any other caller) asks for, as plain values — the money already parsed into
 * Money (MoneyInput: nothing here is a float or free text), everything else as typed. ShippingMethodWriter validates
 * all of it again; this class decides nothing.
 *
 * Fields that do not belong to the chosen kind are IGNORED and CLEARED by the writer (a FREE method has no price, a
 * FLAT one no class amounts): changing the kind never fails because of what the old kind left behind.
 */
final class ShippingMethodInput
{
    /**
     * @param  string  $kind  flat | free | per_class | carrier
     * @param  string  $classMode  replace | adjust (PER_CLASS only)
     * @param  ?string  $courier  the courier group's display name (stage 5f); blank = none
     * @param  ?string  $deliveryType  address | office | locker | other; blank = none
     * @param  ?string  $destinationScope  address | pickup | any — the admin form's "Serves" Select (stage 6d,
     *         design 9.2.8); null/blank means the caller did NOT send one and the writer falls back to the old
     *         toggle mapping, so every pre-6d caller keeps its meaning
     * @param  array<int, array{class: mixed, amount: mixed}>  $classRates  rows of a class code and its amount (Money); a list, so a duplicate class is visible and refused
     * @param  ?list<string>  $freeDestinations  the kinds the free-shipping threshold applies to (stage 5g): address | office | locker.
     *         null = the caller states none: a NEW method gets the owner's default (ownerDefaultFreeDestinations()), an EXISTING one keeps its stored set
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly bool $active = true,
        public readonly ?Money $price = null,
        public readonly ?Money $freeAbove = null,
        public readonly string $classMode = 'replace',
        public readonly array $classRates = [],
        public readonly bool $requiresPickupPoint = false,
        public readonly ?string $carrierCode = null,
        public readonly ?string $courier = null,
        public readonly ?string $deliveryType = null,
        public readonly ?string $destinationScope = null,
        public readonly ?array $freeDestinations = null,
    ) {
    }

    /**
     * THE OWNER'S DEFAULT free set (stage 5g, design 9.3.2, decision Q1): office + locker, limited to the kinds the method serves;
     * when that leaves nothing (an address-only method), the served kinds themselves, so a threshold is never left that cannot apply.
     *
     * @param  list<ShippingDeliveryType>  $served
     * @return list<ShippingDeliveryType>
     */
    public static function ownerDefaultFreeDestinations(array $served): array
    {
        $preferred = array_values(array_filter($served, static fn (ShippingDeliveryType $kind): bool => in_array($kind, ShippingDeliveryType::pickupKinds(), true)));

        return $preferred === [] ? $served : $preferred;
    }
}
