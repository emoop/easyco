<?php

namespace App\Services;

use App\Settings\StoreCountry;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Matching\ZoneDestination;
use EasyCo\Shipping\Matching\ZoneMatcher;
use EasyCo\Shipping\ShippingMethod;

/**
 * The ACTIVE methods of the store country's BROAD zone: the one zone the matcher gives for the store's own country with NO settlement and NO postcode (a settlement- or postcode-narrowed zone can never match a destination
 * that carries neither). The one place that rule lives: the cart's free-shipping hint (CartFreeShippingHint, shipping stage 3e §5.1) and the card's «Безплатна доставка» threshold (FreeShippingThreshold, P7-2) both read it,
 * so the two can never disagree about which zone they talk about. Never throws: no store country, no matching zone or no active method is an empty list.
 */
final class StoreBroadZoneMethods
{
    public function __construct(
        private readonly StoreCountry $storeCountry,
        private readonly SettlementNormalizerResolver $normalizers,
        private readonly ShippingZoneRepository $zones,
        private readonly ShippingMethodRepository $methods,
        private readonly ZoneMatcher $zoneMatcher,
    ) {
    }

    /** @return list<ShippingMethod> */
    public function methods(): array
    {
        $country = $this->storeCountry->currentOrNull();

        if ($country === null) {
            return [];
        }

        $match = $this->zoneMatcher->match(
            $this->zones->allOrdered(),
            new ZoneDestination($country, null, null, false),
            $this->normalizers->forCurrentLocale(),
        );

        if (! $match->isMatched()) {
            return [];
        }

        return $this->methods->forZone((string) $match->zone()->id(), activeOnly: true);
    }
}
