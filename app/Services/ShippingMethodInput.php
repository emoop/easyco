<?php

namespace App\Services;

use EasyCo\Pricing\Money;

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
     * @param  array<int, array{class: mixed, amount: mixed}>  $classRates  rows of a class code and its amount (Money); a list, so a duplicate class is visible and refused
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
    ) {
    }
}
