<?php

namespace App\Services;

use App\Services\Exceptions\AddressIncompleteForCheckoutException;
use App\Services\Exceptions\AddressNotFoundForCheckoutException;
use App\Services\Exceptions\ShippingQuoteRefusedException;
use App\Services\Exceptions\ShippingQuoteFilterException;
use EasyCo\Cart\Cart;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Shipping\Carrier\CallBudget;
use EasyCo\Shipping\Carrier\ShippingContext;
use EasyCo\Shipping\Carrier\ShippingQuote;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Matching\PostcodeNormalizer;
use EasyCo\Shipping\Matching\ZoneDestination;
use EasyCo\Shipping\Matching\ZoneMatcher;
use EasyCo\Shipping\Rating\MethodRate;
use EasyCo\Shipping\Rating\RateLine;
use EasyCo\Shipping\Rating\RateRequest;
use EasyCo\Shipping\Rating\ShippingRateCalculator;
use EasyCo\Shipping\ShippingMethod;

/**
 * The shipping quote (shipping-domain-design.md §6.7, stage 3d part 1): for a cart
 * and a destination, every active method of the matched zone with its price, each
 * price carrying a handle checkout can verify later.
 *
 * WRITES NOTHING TO THE DATABASE AND OPENS NO TRANSACTION. (It writes the cache:
 * carrier answers and handles — nothing else.) The CART IS RESOLVED BY THE CALLER,
 * server-side; this service never takes a client-supplied cart token. A saved
 * address is read through AddressResolver::resolveExisting(), the one place the
 * "belongs to this account, has a country" rules live.
 *
 * TWO STEPS, deliberately separate:
 *   offers()       -> the complete, priced list of methods (QuoteOffers);
 *   issueHandles() -> a handle for each method that finally has a price.
 * Stage 3d part 2 inserts the merchant filter BETWEEN them, so a handle is never
 * issued for a price the filter then changes. quote() is the two in a row.
 *
 * Steps of offers(): CartPricing in preview mode (an unpriced line is skipped, as
 * the cart does, and does not count toward the threshold) for goodsAfterDiscount;
 * the lines' shipping classes and weights in ONE query; the store's settlement
 * normalizer; ZoneMatcher; ShippingRateCalculator for the local methods; CARRIER
 * methods through CarrierQuoteCache -> CarrierCallGuard with an explicit budget.
 * A carrier problem never throws: that method is "unavailable" with a reason and
 * the local methods still answer.
 */
final class ShippingQuoteService
{
    public function __construct(
        private readonly CartPricing $cartPricing,
        private readonly VariationRepository $variations,
        private readonly SettlementNormalizerResolver $normalizers,
        private readonly ShippingZoneRepository $zones,
        private readonly ShippingMethodRepository $methods,
        private readonly ZoneMatcher $zoneMatcher,
        private readonly ShippingRateCalculator $calculator,
        private readonly CarrierQuoteCache $carrierQuotes,
        private readonly QuoteHandleStore $handles,
        private readonly AddressResolver $addressResolver,
        private readonly FreeShippingHintReader $freeShippingHint,
    ) {
    }

    /**
     * @param string|null $accountId the customer (for the promotion's per-customer rules); null for a guest
     *
     * @throws ShippingQuoteRefusedException
     */
    public function quote(Cart $cart, ?string $accountId, QuoteDestination $destination): ShippingQuoteResult
    {
        return $this->issueHandles($this->applyQuotesFilter($this->offers($cart, $accountId, $destination), $destination));
    }

    /**
     * The merchant hook `shipping.quotes` (shipping-domain-design.md §6.8): a filter over the offered methods,
     * run BETWEEN building the list and issuing handles, so every handle binds the FINAL amount.
     *
     * A filter may REMOVE a method or CHANGE ITS AMOUNT (never below 0). It cannot add a method that is not
     * an active method of the matched zone, rename one, change its kind, service or currency, or turn an
     * unavailable method into a priced one (or the reverse): everything else in its output is refused, by name,
     * with ShippingQuoteFilterException. The original order is kept (the first is preselected), whatever order
     * the filter returned. With no listener, the list passes through untouched. The free-shipping hint is
     * recomputed from the final list (it carries no amount, so an amount change does not move it).
     *
     * @throws ShippingQuoteFilterException
     */
    public function applyQuotesFilter(QuoteOffers $offers, QuoteDestination $destination): QuoteOffers
    {
        $filtered = Hook::apply('shipping.quotes', $offers->methods, [
            'cart_id' => $offers->cartId,
            'currency' => $offers->currency,
            'goods_after_discount_minor' => $offers->goodsAfterDiscountMinor,
            'zone_id' => $offers->zoneId,
            'country' => $destination->countryCode,
            'settlement' => $destination->settlement,
            'is_pickup_point' => $destination->isPickupPoint(),
        ]);

        $final = $this->validatedFilterOutput($offers->methods, $filtered);

        // The hint was computed before the filter: recompute it from what is finally offered (stage 4b, §9.1.7),
        // so it never names a method the customer cannot choose. No query: the facts are on the quotes. The facts
        // are read from the method's own PRE-filter quote, in its original order: a filter that rebuilds a quote
        // (to change its amount) carries no threshold facts, and the hint is goods against the threshold, not price.
        $surviving = array_flip(array_map(static fn (MethodQuote $quote): string => $quote->methodId, $final));
        $remaining = array_values(array_filter($offers->methods, static fn (MethodQuote $quote): bool => isset($surviving[$quote->methodId])));

        return $offers->withMethods($final)->withFreeShippingHint($this->freeShippingHint->readFromQuotes($remaining, $offers->currency));
    }

    /**
     * @param  list<MethodQuote>  $original
     * @return list<MethodQuote> the accepted methods, in the original order
     *
     * @throws ShippingQuoteFilterException
     */
    private function validatedFilterOutput(array $original, mixed $filtered): array
    {
        if (! is_array($filtered)) {
            throw ShippingQuoteFilterException::because(ShippingQuoteFilterException::NOT_A_LIST);
        }

        $byId = [];
        $order = [];

        foreach ($original as $index => $method) {
            $byId[$method->methodId] = $method;
            $order[$method->methodId] = $index;
        }

        $accepted = [];

        foreach ($filtered as $item) {
            if (! $item instanceof MethodQuote) {
                throw ShippingQuoteFilterException::because(ShippingQuoteFilterException::INVALID_ITEM);
            }

            $before = $byId[$item->methodId] ?? throw ShippingQuoteFilterException::because(ShippingQuoteFilterException::UNKNOWN_METHOD, $item->methodId);

            if (isset($accepted[$item->methodId])) {
                throw ShippingQuoteFilterException::because(ShippingQuoteFilterException::DUPLICATE_METHOD, $item->methodId);
            }

            if ($item->isAvailable() !== $before->isAvailable()) {
                throw ShippingQuoteFilterException::because(ShippingQuoteFilterException::AVAILABILITY_CHANGED, $item->methodId);
            }

            if ($item->amountMinor !== null && $item->amountMinor < 0) {
                throw ShippingQuoteFilterException::because(ShippingQuoteFilterException::NEGATIVE_AMOUNT, $item->methodId);
            }

            // The scope is the method row's: a filter that rebuilt the quote the pre-6b way carries none (null = not stated, restored
            // below), but one that STATES another scope is refused like any other changed field.
            if ($item->name !== $before->name || $item->kind !== $before->kind || $item->requiresPickupPoint !== $before->requiresPickupPoint
                || ($item->destinationScope !== null && $item->destinationScope !== $before->destinationScope)
                || $item->currency !== $before->currency || $item->serviceCode !== $before->serviceCode
                || $item->unavailableReason !== $before->unavailableReason || $item->handle !== null) {
                throw ShippingQuoteFilterException::because(ShippingQuoteFilterException::CHANGED_FIELD, $item->methodId);
            }

            // The courier group facts are the method row's, whatever a filter rebuilt (stage 5f).
            $accepted[$item->methodId] = $item->withGrouping($before->courier, $before->deliveryType)->withDestination($before->destinationScope, $before->servesDestination);
        }

        uasort($accepted, static fn (MethodQuote $a, MethodQuote $b): int => $order[$a->methodId] <=> $order[$b->methodId]);

        return array_values($accepted);
    }

    /**
     * Quote to one of the customer's saved addresses, scoped to their account.
     *
     * @throws ShippingQuoteRefusedException address_not_found (unknown OR another account's), address_incomplete, and the rest
     */
    public function quoteForSavedAddress(Cart $cart, string $accountId, string $addressId): ShippingQuoteResult
    {
        return $this->quote($cart, $accountId, $this->destinationOfSavedAddress($accountId, $addressId));
    }

    /** @throws ShippingQuoteRefusedException */
    public function offersForSavedAddress(Cart $cart, string $accountId, string $addressId): QuoteOffers
    {
        return $this->offers($cart, $accountId, $this->destinationOfSavedAddress($accountId, $addressId));
    }

    /** @throws ShippingQuoteRefusedException */
    public function offers(Cart $cart, ?string $accountId, QuoteDestination $destination): QuoteOffers
    {
        if ($cart->isEmpty()) {
            throw new ShippingQuoteRefusedException(ShippingQuoteRefusedException::EMPTY_CART);
        }

        $cartId = (string) $cart->id();
        $currency = DefaultCurrency::get()->code();

        // 1. Goods after discount, from the ONE shared calculation (unpriced lines skipped).
        $pricing = $this->cartPricing->price($cart, $accountId, $currency, UnpricedLines::SKIP, includeUnitCost: false);
        $pricedLines = $pricing->pricedLines();

        if ($pricedLines === []) {
            throw new ShippingQuoteRefusedException(ShippingQuoteRefusedException::NO_PRICED_LINES);
        }

        // 2. Shipping classes and weights of the priced lines: ONE query.
        $variations = $this->variations->findByIds(array_map(static fn ($line): string => $line->variationId(), $pricedLines));
        $rateLines = [];
        $weight = 0;
        $weightKnown = true;

        foreach ($pricedLines as $line) {
            $variation = $variations[$line->variationId()] ?? null;
            $rateLines[] = new RateLine($variation?->shippingClass(), $line->quantity());

            if ($variation?->weightGrams() === null) {
                $weightKnown = false;
            } else {
                $weight += $variation->weightGrams() * $line->quantity();
            }
        }

        // 3. The zone.
        $normalizer = $this->normalizers->forCurrentLocale();
        $match = $this->zoneMatcher->match(
            $this->zones->allOrdered(),
            new ZoneDestination($destination->countryCode, $destination->settlement, $destination->postcode, $destination->isPickupPoint()),
            $normalizer,
        );

        if (! $match->isMatched()) {
            throw new ShippingQuoteRefusedException(ShippingQuoteRefusedException::NO_ZONE_FOR_DESTINATION);
        }

        $zone = $match->zone();
        $goodsMinor = $pricing->goodsAfterDiscount()->minorValue();

        // 4. The zone's active methods: local ones priced here, CARRIER ones asked.
        $zoneMethods = $this->methods->forZone((string) $zone->id(), activeOnly: true);
        $byId = [];

        foreach ($zoneMethods as $method) {
            $byId[(string) $method->id()] = $method;
        }

        $rates = $this->calculator->ratesFor($zoneMethods, new RateRequest($currency, $goodsMinor, $rateLines));
        $context = $this->contextFor($destination, $currency, $goodsMinor, $weightKnown ? $weight : null);
        $offered = [];

        foreach ($rates as $rate) {
            $method = $byId[$rate->methodId];
            $offered[] = $rate->needsQuote() ? $this->carrierMethod($method, $rate, $context, $destination->isPickupPoint()) : MethodQuote::priced(
                $rate->methodId, $method->name(), $method->kind()->value, $method->requiresPickupPoint(), $currency, $rate->amountMinor(), null,
                $rate->freeAboveMinor, $rate->remainingToFreeMinor, $method->courier(), $method->deliveryType()?->value,
                $method->destinationScope()->value, $method->servesPickupPoint($destination->isPickupPoint()),
            );
        }

        return new QuoteOffers(
            $cartId,
            $currency,
            $goodsMinor,
            (string) $zone->id(),
            $zone->name(),
            self::pricingHashFor($destination, $normalizer->normalize((string) $destination->settlement), (string) $zone->id(), $goodsMinor, $currency, $cart),
            $offered,
            $this->freeShippingHint->read($zoneMethods, $rates, $currency),
        );
    }

    /** Step two: a handle for every offered method that has a price. Nothing is issued for an unavailable one. */
    public function issueHandles(QuoteOffers $offers): ShippingQuoteResult
    {
        $methods = [];

        foreach ($offers->methods as $method) {
            $methods[] = $method->isAvailable()
                ? $method->withHandle($this->handles->issue($offers->cartId, $method->methodId, (int) $method->amountMinor, $method->currency, $offers->pricingHash, $method->serviceCode))
                : $method;
        }

        return new ShippingQuoteResult($offers->cartId, $offers->currency, $offers->goodsAfterDiscountMinor, $offers->zoneId, $offers->zoneName, $offers->pricingHash, $methods, $offers->freeShippingHint);
    }

    /**
     * The hash of everything that priced a quote (shipping-domain-design.md §6.7): the destination as NORMALIZED
     * (the settlement by the store's normalizer, the postcode by PostcodeNormalizer, ignored for a pickup point,
     * exactly as ZoneMatcher reads them), the matched zone, goods after discount and currency, every cart line
     * with its quantity (in a fixed order), and the promotion code. Checkout recomputes it the same way.
     */
    public static function pricingHashFor(QuoteDestination $destination, string $normalizedSettlement, string $zoneId, int $goodsAfterDiscountMinor, string $currency, Cart $cart): string
    {
        $lines = [];

        foreach ($cart->lines() as $line) {
            $lines[] = [$line->variationId(), $line->quantity()];
        }

        usort($lines, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return hash('sha256', json_encode([
            'v' => 1,
            'country' => $destination->countryCode,
            'settlement' => $normalizedSettlement,
            'postcode' => $destination->isPickupPoint() || $destination->postcode === null ? '' : PostcodeNormalizer::normalize($destination->postcode),
            'pickup' => $destination->isPickupPoint(),
            'zone' => $zoneId,
            'goods' => $goodsAfterDiscountMinor,
            'currency' => $currency,
            'lines' => $lines,
            'promotion' => $cart->appliedPromotionCode(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @throws ShippingQuoteRefusedException */
    private function destinationOfSavedAddress(string $accountId, string $addressId): QuoteDestination
    {
        try {
            $address = $this->addressResolver->resolveExisting($addressId, $accountId);
        } catch (AddressNotFoundForCheckoutException $e) {
            throw new ShippingQuoteRefusedException(ShippingQuoteRefusedException::ADDRESS_NOT_FOUND, $e);
        } catch (AddressIncompleteForCheckoutException $e) {
            throw new ShippingQuoteRefusedException(ShippingQuoteRefusedException::ADDRESS_INCOMPLETE, $e);
        }

        return QuoteDestination::fromAddress($address);
    }

    private function contextFor(QuoteDestination $destination, string $currency, int $goodsMinor, ?int $weightGrams): ?ShippingContext
    {
        if ($destination->settlement === null || trim($destination->settlement) === '') {
            return null;
        }

        // Cash on delivery and dimensions are left null: the collected amount depends on the shipping price
        // being asked for, and "summed dimensions" has no meaning for several items (§6.6) — both are for the
        // first real carrier to define.
        return new ShippingContext($destination->countryCode, $destination->settlement, $destination->isPickupPoint(), $currency, $goodsMinor, null, $weightGrams);
    }

    private function carrierMethod(ShippingMethod $method, MethodRate $rate, ?ShippingContext $context, bool $isPickupPoint): MethodQuote
    {
        $id = $rate->methodId;
        $serves = $method->servesPickupPoint($isPickupPoint);
        $make = static fn (string $reason): MethodQuote => MethodQuote::unavailable($id, $method->name(), ShippingMethodKind::CARRIER->value, $method->requiresPickupPoint(), $rate->currency, $reason, $method->courier(), $method->deliveryType()?->value, $method->destinationScope()->value, $serves);

        // The merchant restricted this method to the other kind of destination: the carrier is NOT asked (no wasted call, no cost).
        if (! $serves) {
            return $make(MethodQuote::DESTINATION_NOT_SERVED);
        }

        if ($context === null) {
            return $make(MethodQuote::NO_SETTLEMENT);
        }

        $result = $this->carrierQuotes->quotes((string) $rate->carrierCode, $context, CallBudget::milliseconds(QuoteCachePolicy::CARRIER_BUDGET_MS));

        if (! $result->isAvailable()) {
            return $make($result->reason()->value);
        }

        $cheapest = null;

        foreach ($result->items() as $quote) {
            /** @var ShippingQuote $quote */
            if ($cheapest === null || [$quote->amountMinor, $quote->serviceCode] < [$cheapest->amountMinor, $cheapest->serviceCode]) {
                $cheapest = $quote;
            }
        }

        if ($cheapest === null) {
            return $make(MethodQuote::NO_QUOTE);
        }

        return MethodQuote::priced($id, $method->name(), ShippingMethodKind::CARRIER->value, $method->requiresPickupPoint(), $rate->currency, $cheapest->amountMinor, $cheapest->serviceCode, null, null, $method->courier(), $method->deliveryType()?->value, $method->destinationScope()->value, true);
    }
}
