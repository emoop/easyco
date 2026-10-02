<?php

namespace EasyCo\Shipping\Matching;

use InvalidArgumentException;

/**
 * Where a delivery goes, as the ZoneMatcher needs it and nothing more — a plain
 * value, so this package imports nothing from Address, Order or Cart.
 *
 *  - countryCode: required, uppercase ISO 3166-1 alpha-2 (shape checked here;
 *    every address and order carries one, a pickup point included — owner
 *    decision D1);
 *  - settlement: a street address's city or a pickup point's settlement;
 *    null when unknown;
 *  - postcode: a street address's postal code, null when it has none;
 *  - isPickupPoint: a pickup point has NO postcode, so any postcode given with
 *    this flag is ignored by the matcher (a postcodes-only zone can never match
 *    a pickup point).
 */
final class ZoneDestination
{
    public function __construct(
        public readonly string $countryCode,
        public readonly ?string $settlement = null,
        public readonly ?string $postcode = null,
        public readonly bool $isPickupPoint = false,
    ) {
        if (preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1) {
            throw new InvalidArgumentException("ZoneDestination countryCode must be an uppercase ISO 3166-1 alpha-2 code, got \"{$countryCode}\".");
        }
    }
}
