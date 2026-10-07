<?php

namespace App\Services;

use App\Settings\StoreCountry;
use EasyCo\Cart\Cart;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Matching\ZoneDestination;
use EasyCo\Shipping\Matching\ZoneMatcher;
use EasyCo\Shipping\Rating\RateLine;
use EasyCo\Shipping\Rating\RateRequest;
use EasyCo\Shipping\Rating\ShippingRateCalculator;

/**
 * The cart's free-shipping hint (shipping stage 3e, §5.1): computed BEFORE any
 * delivery address exists, from the ONE zone the matcher gives for the store's
 * own country with NO settlement and NO postcode — i.e. the country's BROAD zone,
 * because a settlement- or postcode-narrowed zone can never match a destination
 * that carries neither. It reuses the SAME readers the quote service uses
 * (ZoneMatcher, ShippingMethodRepository::forZone, ShippingRateCalculator), so
 * the sentence can never disagree with a real quote.
 *
 * The threshold basis is the goods-after-discount figure the cart totals already
 * use — passed IN, never recomputed. A null result is normal and never an error:
 * an empty cart, no store country, no matching zone, or no method with a
 * threshold all yield null.
 *
 * The shipping classes of the lines do NOT affect the hint (remaining is a
 * threshold minus the goods figure, independent of which class a line carries),
 * so no per-line variation read is made: a classless RateLine carries each line's
 * quantity to build a valid RateRequest.
 */
final class CartFreeShippingHint
{
    public function __construct(
        private readonly StoreCountry $storeCountry,
        private readonly SettlementNormalizerResolver $normalizers,
        private readonly ShippingZoneRepository $zones,
        private readonly ShippingMethodRepository $methods,
        private readonly ZoneMatcher $zoneMatcher,
        private readonly ShippingRateCalculator $calculator,
        private readonly FreeShippingHintReader $reader,
    ) {
    }

    public function forCart(Cart $cart, int $goodsAfterDiscountMinor, string $currency): ?FreeShippingHint
    {
        if ($cart->isEmpty()) {
            return null;
        }

        $country = $this->storeCountry->currentOrNull();

        if ($country === null) {
            return null;
        }

        $match = $this->zoneMatcher->match(
            $this->zones->allOrdered(),
            new ZoneDestination($country, null, null, false),
            $this->normalizers->forCurrentLocale(),
        );

        if (! $match->isMatched()) {
            return null;
        }

        $zoneMethods = $this->methods->forZone((string) $match->zone()->id(), activeOnly: true);

        if ($zoneMethods === []) {
            return null;
        }

        $rateLines = [];

        foreach ($cart->lines() as $line) {
            $rateLines[] = new RateLine(null, $line->quantity());
        }

        $rates = $this->calculator->ratesFor($zoneMethods, new RateRequest($currency, $goodsAfterDiscountMinor, $rateLines));

        return $this->reader->read($zoneMethods, $rates, $currency);
    }
}
