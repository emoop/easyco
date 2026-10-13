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
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
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
        $final = $this->filteredMethods($offers, $destination);

        // The hint was computed before the filter: recompute it from what is finally offered (stage 4b, §9.1.7),
        // so it never names a method the customer cannot choose. No query: the facts are on the quotes. The facts
        // are read from the method's own PRE-filter quote, in its original order: a filter that rebuilds a quote
        // (to change its amount) carries no threshold facts, and the hint is goods against the threshold, not price.
        // The scope is the kind of this destination (stage 5g-2b): it never names a method that is not free for it.
        return $offers->withMethods($final)->withFreeShippingHint($this->freeShippingHint->readFromQuotes($this->survivors($offers, $final), $offers->currency, $destination->scopeKinds()));
    }

    /**
     * Runs the `shipping.quotes` filter over ONE destination's list and validates what comes back. The context carries
     * `destination_kind` (stage 5g-2b, Q12): 'address' | 'office' | 'locker' | 'pickup' (a pickup point of unstated kind), so EVERY
     * call states which kind it prices. An `all` quote calls it once per kind; the contract is the same each time.
     *
     * @return list<MethodQuote> the accepted methods, in the original order
     *
     * @throws ShippingQuoteFilterException
     */
    private function filteredMethods(QuoteOffers $offers, QuoteDestination $destination): array
    {
        $filtered = Hook::apply('shipping.quotes', $offers->methods, [
            'cart_id' => $offers->cartId,
            'currency' => $offers->currency,
            'goods_after_discount_minor' => $offers->goodsAfterDiscountMinor,
            'zone_id' => $offers->zoneId,
            'country' => $destination->countryCode,
            'settlement' => $destination->settlement,
            'is_pickup_point' => $destination->isPickupPoint(),
            'destination_kind' => $destination->hashKind(),
        ]);

        return $this->validatedFilterOutput($offers->methods, $filtered);
    }

    /**
     * The PRE-filter quotes that survived the filter, in their original order (the facts the hint reads are theirs).
     *
     * @param  list<MethodQuote>  $final
     * @return list<MethodQuote>
     */
    private function survivors(QuoteOffers $offers, array $final): array
    {
        $surviving = array_flip(array_map(static fn (MethodQuote $quote): string => $quote->methodId, $final));

        return array_values(array_filter($offers->methods, static fn (MethodQuote $quote): bool => isset($surviving[$quote->methodId])));
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
                || ($item->servedKinds !== null && $item->servedKinds !== $before->servedKinds)
                || ($item->freeKinds !== null && $item->freeKinds !== $before->freeKinds)
                || $item->currency !== $before->currency || $item->serviceCode !== $before->serviceCode
                || $item->unavailableReason !== $before->unavailableReason || $item->handle !== null) {
                throw ShippingQuoteFilterException::because(ShippingQuoteFilterException::CHANGED_FIELD, $item->methodId);
            }

            // The courier group facts and the served / free kinds (5g-2b) are the method row's, whatever a filter rebuilt (stage 5f).
            $accepted[$item->methodId] = $item->withGrouping($before->courier, $before->deliveryType)->withDestination($before->destinationScope, $before->servesDestination)->withKinds($before->servedKinds, $before->freeKinds);
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
        return $this->offersFor($this->context($cart, $accountId), $destination);
    }

    /**
     * The part of a quote that does not depend on where the parcel goes (stage 5g-2b, design 9.3.4): the empty-cart and
     * no-priced-lines refusals, the ONE cart pricing (goods after discount, unpriced lines skipped), the lines' classes and weights
     * (one query), the store's normalizer and the ordered zone list. Built once per request and shared by every bucket and kind.
     *
     * @throws ShippingQuoteRefusedException
     */
    public function context(Cart $cart, ?string $accountId): QuoteContext
    {
        if ($cart->isEmpty()) {
            throw new ShippingQuoteRefusedException(ShippingQuoteRefusedException::EMPTY_CART);
        }

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

        return new QuoteContext(
            $cart,
            (string) $cart->id(),
            $currency,
            $pricing->goodsAfterDiscount()->minorValue(),
            $rateLines,
            $weightKnown ? $weight : null,
            $this->normalizers->forCurrentLocale(),
            $this->zones->allOrdered(),
        );
    }

    /**
     * Every active method of the zone the destination matched, priced, for ONE destination (the single-kind quote, and what
     * offers() has always returned).
     *
     * @throws ShippingQuoteRefusedException no_zone_for_destination
     */
    public function offersFor(QuoteContext $context, QuoteDestination $destination): QuoteOffers
    {
        $bucket = $this->bucketFor($context, $destination)
            ?? throw new ShippingQuoteRefusedException(ShippingQuoteRefusedException::NO_ZONE_FOR_DESTINATION);

        return $this->offersForKind($context, $bucket, $destination);
    }

    /**
     * Step 3-4a: the zone of this destination and that zone's ACTIVE methods, or null when no zone covers it. $zoneMethods is a
     * per-request memo (zone id => methods) so a zone two buckets share is read ONCE.
     *
     * @param array<string, list<ShippingMethod>> $zoneMethods
     */
    public function bucketFor(QuoteContext $context, QuoteDestination $destination, array &$zoneMethods = []): ?QuoteBucket
    {
        $match = $this->zoneMatcher->match(
            $context->zones,
            new ZoneDestination($destination->countryCode, $destination->settlement, $destination->postcode, $destination->isPickupPoint()),
            $context->normalizer,
        );

        if (! $match->isMatched()) {
            return null;
        }

        $zone = $match->zone();
        $zoneMethods[(string) $zone->id()] ??= $this->methods->forZone((string) $zone->id(), activeOnly: true);

        return new QuoteBucket($zone, $zoneMethods[(string) $zone->id()]);
    }

    /**
     * Step 4b: rate the bucket's methods for this destination KIND (the calculator is pure: three kinds are three cheap passes) and
     * ask the CARRIER methods (once per bucket, answers remembered). Prices, the pricing hash (this kind's own) and the hint of the
     * request's kind.
     */
    public function offersForKind(QuoteContext $context, QuoteBucket $bucket, QuoteDestination $destination): QuoteOffers
    {
        // The destination goes into the rating (stage 5g-2a): the free-shipping set is per kind, never cheaper than what is stated.
        $rates = $this->calculator->ratesFor($bucket->methods, new RateRequest($context->currency, $context->goodsMinor, $context->rateLines, $destination->rateDestination()));
        $shipping = $this->contextFor($destination, $context->currency, $context->goodsMinor, $context->weightGrams);
        $offered = [];

        foreach ($rates as $rate) {
            $method = $bucket->byId[$rate->methodId];
            $offered[] = $rate->needsQuote() ? $this->carrierMethod($method, $rate, $shipping, $destination, $bucket) : MethodQuote::priced(
                $rate->methodId, $method->name(), $method->kind()->value, $method->requiresPickupPoint(), $context->currency, $rate->amountMinor(), null,
                $rate->freeAboveMinor, $rate->remainingToFreeMinor, $method->courier(), $method->deliveryType()?->value,
                $method->destinationScope()->value, $method->servesKind($destination->kind(), $destination->isPickupPoint()),
                self::servedKindsOf($method), self::freeKindsOf($method),
            );
        }

        return new QuoteOffers(
            $context->cartId,
            $context->currency,
            $context->goodsMinor,
            (string) $bucket->zone->id(),
            $bucket->zone->name(),
            self::pricingHashFor($destination, $context->normalizer->normalize((string) $destination->settlement), (string) $bucket->zone->id(), $context->goodsMinor, $context->currency, $context->cart),
            $offered,
            $this->freeShippingHint->read($bucket->methods, $rates, $context->currency, $destination->scopeKinds()),
            $destination->isPickupPoint() ? 'pickup' : 'address',
        );
    }

    /**
     * The `all` quote (stage 5g-2b, design 9.3.4): every method with its price for EACH kind of destination — address, office,
     * locker — from ONE request: one context (one cart pricing), at most two buckets (the address and the pickup point are matched
     * separately: a postcode-narrowed zone may match the one and not the other), each zone's methods read once, three pure rating
     * passes. The merchant filter runs once per kind. With a settlement the answer is FINAL: a handle per (method, kind), each from
     * THAT kind's own pricing hash, so it verifies only for the kind it was quoted for. Without one it is a PREVIEW (both buckets
     * matched as the cart hint does, no postcode): prices are indicative and NO handle is issued.
     *
     * @throws ShippingQuoteRefusedException no_zone_for_destination when neither bucket matched; the empty-cart / no-priced-lines refusals
     * @throws ShippingQuoteFilterException
     */
    public function quoteAllKinds(Cart $cart, ?string $accountId, string $countryCode, ?string $settlement, ?string $postalCode): AllKindsQuoteResult
    {
        $context = $this->context($cart, $accountId);
        $settlement = $settlement === null || trim($settlement) === '' ? null : trim($settlement);
        $preview = $settlement === null;

        $address = QuoteDestination::forAddress(AddressDeliveryType::STREET_ADDRESS, $countryCode, $settlement, $preview ? null : $postalCode);
        $pickup = static fn (?ShippingDeliveryType $kind): QuoteDestination => QuoteDestination::forAddress(AddressDeliveryType::PICKUP_POINT, $countryCode, $settlement, null, $kind);

        $zoneMethods = [];
        $buckets = ['address' => $this->bucketFor($context, $address, $zoneMethods), 'pickup' => $this->bucketFor($context, $pickup(null), $zoneMethods)];

        if ($buckets['address'] === null && $buckets['pickup'] === null) {
            throw new ShippingQuoteRefusedException(ShippingQuoteRefusedException::NO_ZONE_FOR_DESTINATION);
        }

        $kinds = [
            'address' => [$buckets['address'], $address],
            'office' => [$buckets['pickup'], $pickup(ShippingDeliveryType::OFFICE)],
            'locker' => [$buckets['pickup'], $pickup(ShippingDeliveryType::LOCKER)],
        ];

        $results = [];
        $survivingByKind = [];

        foreach ($kinds as $kind => [$bucket, $destination]) {
            if ($bucket === null) {
                continue;
            }

            $offers = $this->offersForKind($context, $bucket, $destination);
            $final = $this->filteredMethods($offers, $destination);
            $survivingByKind[$kind] = $this->survivors($offers, $final);
            $offers = $offers->withMethods($final);

            $results[$kind] = $preview ? $this->withoutHandles($offers) : $this->issueHandles($offers);
        }

        return new AllKindsQuoteResult(
            $context->cartId,
            $context->currency,
            $context->goodsMinor,
            $preview,
            [
                'address' => $buckets['address'] === null ? null : ['id' => (string) $buckets['address']->zone->id(), 'name' => $buckets['address']->zone->name()],
                'pickup' => $buckets['pickup'] === null ? null : ['id' => (string) $buckets['pickup']->zone->id(), 'name' => $buckets['pickup']->zone->name()],
            ],
            $results,
            // Read from what each kind's filter left (its surviving pre-filter quotes carry the facts), so a kind the filter removed for a method is not in scope for it.
            $this->freeShippingHint->readFromKindLists($survivingByKind, $context->currency),
        );
    }

    /** The offers as a result with NO handle (a preview: nothing is issued, nothing is cached). */
    private function withoutHandles(QuoteOffers $offers): ShippingQuoteResult
    {
        return new ShippingQuoteResult($offers->cartId, $offers->currency, $offers->goodsAfterDiscountMinor, $offers->zoneId, $offers->zoneName, $offers->pricingHash, $offers->methods, $offers->freeShippingHint, $offers->bucket);
    }

    /** @return list<string> the kinds the method serves, as values (address | office | locker) */
    private static function servedKindsOf(ShippingMethod $method): array
    {
        return array_map(static fn (ShippingDeliveryType $k): string => $k->value, $method->servedKinds());
    }

    /** @return list<string> the kinds its free-shipping set really covers (stored set within the served kinds) */
    private static function freeKindsOf(ShippingMethod $method): array
    {
        return array_map(static fn (ShippingDeliveryType $k): string => $k->value, $method->effectiveFreeDestinations());
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

        return new ShippingQuoteResult($offers->cartId, $offers->currency, $offers->goodsAfterDiscountMinor, $offers->zoneId, $offers->zoneName, $offers->pricingHash, $methods, $offers->freeShippingHint, $offers->bucket);
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
            // v2 (stage 5g-2a, design 9.3.6): the pickup KIND joined the hash, so every handle issued before expires once.
            'v' => 2,
            'country' => $destination->countryCode,
            'settlement' => $normalizedSettlement,
            'postcode' => $destination->isPickupPoint() || $destination->postcode === null ? '' : PostcodeNormalizer::normalize($destination->postcode),
            'pickup' => $destination->isPickupPoint(),
            // address | office | locker | pickup (unspecified): a handle quoted for one kind never verifies for another.
            'kind' => $destination->hashKind(),
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

    private function carrierMethod(ShippingMethod $method, MethodRate $rate, ?ShippingContext $context, QuoteDestination $destination, QuoteBucket $bucket): MethodQuote
    {
        $id = $rate->methodId;
        $serves = $method->servesKind($destination->kind(), $destination->isPickupPoint());
        $servedKinds = self::servedKindsOf($method);
        $freeKinds = self::freeKindsOf($method);
        $make = static fn (string $reason): MethodQuote => MethodQuote::unavailable($id, $method->name(), ShippingMethodKind::CARRIER->value, $method->requiresPickupPoint(), $rate->currency, $reason, $method->courier(), $method->deliveryType()?->value, $method->destinationScope()->value, $serves, $servedKinds, $freeKinds);

        // The merchant restricted this method to the other kind of destination: the carrier is NOT asked (no wasted call, no cost).
        if (! $serves) {
            return $make(MethodQuote::DESTINATION_NOT_SERVED);
        }

        if ($context === null) {
            return $make(MethodQuote::NO_SETTLEMENT);
        }

        // Asked ONCE per bucket: the office and the locker of an `all` quote read the same answer (the carrier is not told the kind).
        $answer = $bucket->carrierAnswers[$id] ??= $this->askCarrier((string) $rate->carrierCode, $context);

        if ($answer['reason'] !== null) {
            return $make($answer['reason']);
        }

        $cheapest = $answer['quote'];

        return MethodQuote::priced($id, $method->name(), ShippingMethodKind::CARRIER->value, $method->requiresPickupPoint(), $rate->currency, $cheapest->amountMinor, $cheapest->serviceCode, null, null, $method->courier(), $method->deliveryType()?->value, $method->destinationScope()->value, true, $servedKinds, $freeKinds);
    }

    /** @return array{quote: ?ShippingQuote, reason: ?string} the carrier's cheapest quote, or why there is none */
    private function askCarrier(string $carrierCode, ShippingContext $context): array
    {
        $result = $this->carrierQuotes->quotes($carrierCode, $context, CallBudget::milliseconds(QuoteCachePolicy::CARRIER_BUDGET_MS));

        if (! $result->isAvailable()) {
            return ['quote' => null, 'reason' => $result->reason()->value];
        }

        $cheapest = null;

        foreach ($result->items() as $quote) {
            /** @var ShippingQuote $quote */
            if ($cheapest === null || [$quote->amountMinor, $quote->serviceCode] < [$cheapest->amountMinor, $cheapest->serviceCode]) {
                $cheapest = $quote;
            }
        }

        return $cheapest === null ? ['quote' => null, 'reason' => MethodQuote::NO_QUOTE] : ['quote' => $cheapest, 'reason' => null];
    }
}
