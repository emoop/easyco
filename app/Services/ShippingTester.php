<?php

namespace App\Services;

use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Matching\PostcodeNormalizer;
use EasyCo\Shipping\Matching\ZoneDestination;
use EasyCo\Shipping\Matching\ZoneMatcher;
use EasyCo\Shipping\Rating\RateDestination;
use EasyCo\Shipping\Rating\RateRequest;
use EasyCo\Shipping\Rating\ShippingRateCalculator;
use EasyCo\Shipping\ShippingMethod;

/**
 * "Try it" (shipping-domain-design.md §12.3.5): for an entered destination and a
 * hypothetical cart, it shows the matched zone (or the refusal) and each active
 * method with the price the CUSTOMER would be offered — computed through the SAME
 * pure pieces the quote service uses (`SettlementNormalizerResolver` →
 * `ZoneMatcher` over `allOrdered()` → `forZone(activeOnly: true)` →
 * `ShippingRateCalculator` → `FreeShippingHintReader`). It re-implements neither
 * matching nor pricing, issues no handle and writes nothing. A CARRIER method is
 * reported as "needs a carrier quote"; the tester never calls a carrier.
 */
final class ShippingTester
{
    public function __construct(
        private readonly SettlementNormalizerResolver $normalizers,
        private readonly ShippingZoneRepository $zones,
        private readonly ShippingMethodRepository $methods,
        private readonly ZoneMatcher $zoneMatcher,
        private readonly ShippingRateCalculator $calculator,
        private readonly FreeShippingHintReader $hints,
        private readonly ShippingMethodSummaryReader $summaries,
    ) {
    }

    /**
     * @param  list<\EasyCo\Shipping\Rating\RateLine>  $lines
     * @param  ?RateDestination  $destination  where the order goes (stage 5g-3): an address, a stated office or
     *         locker, or a pickup point whose kind is not given. It is handed to the SAME calculator the quote uses,
     *         so each method shows the price for THAT kind. null = the caller states none (an older caller, or a
     *         pickup flag only), priced as cautiously as today — no destination is stated, so a threshold counts
     *         only when it is free for every kind the method could go to.
     *
     * @throws \InvalidArgumentException when $lines is empty (a rate request needs at least one line)
     */
    public function run(
        string $countryCode,
        ?string $settlement,
        ?string $postcode,
        bool $isPickupPoint,
        int $goodsAfterDiscountMinor,
        array $lines,
        ?RateDestination $destination = null,
    ): ShippingTestResult {
        $currency = DefaultCurrency::get()->code();
        $normalizer = $this->normalizers->forCurrentLocale();

        $rawSettlement = $settlement !== null && trim($settlement) !== '' ? $settlement : null;
        $rawPostcode = $postcode !== null && trim($postcode) !== '' ? $postcode : null;

        // WHAT THE MATCHER SEES, shown to the merchant: the settlement through the store's
        // normalizer, the postcode through PostcodeNormalizer (and ignored for a pickup point).
        $shownSettlement = $rawSettlement === null ? '' : $normalizer->normalize($rawSettlement);
        $shownPostcode = $isPickupPoint || $rawPostcode === null ? '' : PostcodeNormalizer::normalize($rawPostcode);

        $match = $this->zoneMatcher->match(
            $this->zones->allOrdered(),
            new ZoneDestination($countryCode, $rawSettlement, $rawPostcode, $isPickupPoint),
            $normalizer,
        );

        if (! $match->isMatched()) {
            return new ShippingTestResult(
                $countryCode, $shownSettlement, $shownPostcode, $isPickupPoint, $goodsAfterDiscountMinor,
                $currency, null, null, $match->refusalReason(), [], null,
            );
        }

        $zone = $match->zone();
        $zoneMethods = $this->methods->forZone((string) $zone->id(), activeOnly: true);
        $rates = $this->calculator->ratesFor($zoneMethods, new RateRequest($currency, $goodsAfterDiscountMinor, $lines, $destination));
        $hint = $this->hints->read($zoneMethods, $rates, $currency);

        $byId = [];
        foreach ($zoneMethods as $method) {
            $byId[(string) $method->id()] = $method;
        }

        $testMethods = [];

        foreach ($rates as $rate) {
            $method = $byId[$rate->methodId];

            $testMethods[] = new ShippingTestMethod(
                $rate->methodId,
                $method->name(),
                $method->destinationScope()->value,
                $this->servesDestination($method, $destination, $isPickupPoint),
                $this->summaries->summary($method),
                $rate->needsQuote(),
                $rate->needsQuote() ? null : $rate->amountMinor(),
                $rate->freeAboveMinor,
                $rate->remainingToFreeMinor,
                $method->kind() === \EasyCo\Shipping\Enums\ShippingMethodKind::PER_CLASS ? $method->classMode()->value : null,
                $method->courier(),
            );
        }

        return new ShippingTestResult(
            $countryCode, $shownSettlement, $shownPostcode, $isPickupPoint, $goodsAfterDiscountMinor,
            $currency, (string) $zone->id(), $zone->name(), null, $testMethods, $hint,
        );
    }

    /**
     * Does this method serve the destination "Try it" is asking about? (stage 6d, 5g-3)
     *
     *  - a STATED kind (an office or a locker) — the method's own kind list, `servedKinds()`: an office-labelled
     *    method does not serve a locker;
     *  - a pickup point whose kind is NOT given — the scope's pickup rule (`servesPickupPoint(true)`, never
     *    cheaper/more generous than what is stated);
     *  - an address — the scope's address rule (`servesPickupPoint(false)`);
     *  - no destination stated at all — the old flag, exactly as before stage 5g-3.
     *
     * The rule itself is the domain's; this only chooses which of its four forms applies.
     */
    private function servesDestination(ShippingMethod $method, ?RateDestination $destination, bool $isPickupPoint): bool
    {
        return $destination === null
            ? $method->servesPickupPoint($isPickupPoint)
            : $method->servesKind($destination->kind, $destination->isPickupPoint);
    }
}
