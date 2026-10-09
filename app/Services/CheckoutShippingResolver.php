<?php

namespace App\Services;

use App\Services\Exceptions\ShippingQuoteRefusedException;
use EasyCo\Cart\Cart;
use EasyCo\Pricing\Money;

/**
 * Turns (cart, destination, method id, quote handle, expected amount) into a verified ShippingSelection, or a named
 * ShippingRefusal (shipping stage 4d, shipping-domain-design.md §9.1.3). Called ONCE per checkout, BEFORE the placement
 * transaction (stage 4e). It writes nothing, opens no transaction and issues no handle.
 *
 * THE CHECKS, in this order (each refusal happens before the next step runs):
 *  a. shape — the id and the handle must both be present (else `required`) and well-formed: the id 1-20 digits, the
 *     handle `qh_` + exactly 40 alphanumerics (else `invalid`). A malformed value is refused BEFORE any database or cache
 *     read, and is never repeated in the exception.
 *  b. rebuild the offers through the SAME pipeline the quote endpoint uses — ShippingQuoteService::offers() then
 *     applyQuotesFilter(), so the merchant `shipping.quotes` filter applies and the amount is the FILTERED one. No
 *     pricing logic is duplicated, and issueHandles() is deliberately NOT called (nothing is written to the cache).
 *     No zone for the destination means no method can be offered: `method_unavailable`.
 *  c. the method must be among the offered, AVAILABLE methods (offers() lists only the matched zone's active methods;
 *     a method the filter removed was never offered to the customer) — else `method_unavailable`.
 *  d. pickup agreement (§9.1.6) — the method's requires_pickup_point must equal whether the destination is a pickup point,
 *     else `pickup_mismatch`.
 *  e. the PRICE decision table, on the recomputed amount R (and its pricing hash and service code):
 *       1. the handle verifies against (cart, method, R, currency, hash, service)            -> ACCEPT
 *       2. it does not, expected_shipping_minor is given and differs from R                  -> `price_changed` (carries R)
 *       3. it does not, the method is LOCAL and expected_shipping_minor equals R             -> ACCEPT (the O1 compromise:
 *          a lost or expired handle is tolerated only when the customer provably saw this exact figure)
 *       4. anything else                                                                    -> `quote_expired`
 *     QuoteHandleStore::verify() answers a plain false for an unknown, expired, tampered or foreign handle, so those are
 *     deliberately NOT told apart. Goods or destination changed since the quote change the pricing hash, so they fall
 *     out of row 1 the same way.
 *  f. CARRIER methods: no carrier integration exists today (the quote lists such a method as unavailable), so an
 *     unavailable carrier method is `method_unavailable`. The designed rule is "a carrier's amount comes from the verified
 *     handle only, never a live re-quote". The handle store can only VERIFY a presented amount (it has no read accessor),
 *     so the structure here is: the carrier amount is whatever the (cached) offers say now, and it is accepted only if the
 *     handle verifies for exactly that amount and service (row 1); row 3 never applies to a carrier. Reading the amount
 *     out of the handle would need a new QuoteHandleStore accessor — reported, not invented.
 *  g. the handle is NEVER consumed (O3: the cart claim is the single-use guard) and expected_shipping_minor is only
 *     compared, never charged.
 *
 * Not caught here, by design: ShippingQuoteFilterException (a broken merchant filter is a server fault, as in the quote)
 * and the quote's empty-cart / no-priced-lines refusals (checkout refuses those itself before shipping is asked).
 */
final class CheckoutShippingResolver
{
    public const METHOD_ID_PATTERN = '/\A[0-9]{1,20}\z/';

    public const HANDLE_PATTERN = '/\Aqh_[A-Za-z0-9]{40}\z/';

    public function __construct(
        private readonly ShippingQuoteService $quotes,
        private readonly QuoteHandleStore $handles,
    ) {
    }

    /**
     * $destination carries whether the address is a pickup point (QuoteDestination::isPickupPoint()), so no separate flag is taken.
     *
     * @throws ShippingRefusal
     */
    public function resolve(Cart $cart, ?string $accountId, QuoteDestination $destination, ?string $shippingMethodId, ?string $quoteHandle, ?int $expectedShippingMinor): ShippingSelection
    {
        // a. shape — before any lookup.
        if ($shippingMethodId === null || $shippingMethodId === '' || $quoteHandle === null || $quoteHandle === '') {
            throw ShippingRefusal::because(ShippingRefusalReason::REQUIRED, 'A shipping method and its quote handle are required.');
        }

        if (strlen($shippingMethodId) > 20 || preg_match(self::METHOD_ID_PATTERN, $shippingMethodId) !== 1) {
            throw ShippingRefusal::because(ShippingRefusalReason::INVALID, 'The shipping method id is malformed.');
        }

        if (strlen($quoteHandle) !== 43 || preg_match(self::HANDLE_PATTERN, $quoteHandle) !== 1) {
            throw ShippingRefusal::because(ShippingRefusalReason::INVALID, 'The quote handle is malformed.');
        }

        // b. the same pipeline as the quote, merchant filter included; no handle is issued.
        try {
            $offers = $this->quotes->applyQuotesFilter($this->quotes->offers($cart, $accountId, $destination), $destination);
        } catch (ShippingQuoteRefusedException $e) {
            if ($e->reason === ShippingQuoteRefusedException::NO_ZONE_FOR_DESTINATION) {
                throw ShippingRefusal::because(ShippingRefusalReason::METHOD_UNAVAILABLE, 'No shipping zone covers the destination.');
            }

            throw $e;
        }

        // c. offered, available, active (offers() holds only the matched zone's active methods).
        $quote = null;

        foreach ($offers->methods as $offered) {
            if ($offered->methodId === $shippingMethodId) {
                $quote = $offered;
                break;
            }
        }

        if ($quote === null || ! $quote->isAvailable()) {
            throw ShippingRefusal::because(ShippingRefusalReason::METHOD_UNAVAILABLE, 'The shipping method is not offered for this cart and destination.');
        }

        // d. pickup agreement.
        if ($quote->requiresPickupPoint !== $destination->isPickupPoint()) {
            throw ShippingRefusal::because(ShippingRefusalReason::PICKUP_MISMATCH, 'The shipping method and the address disagree about a pickup point.');
        }

        // e. the price.
        $amountMinor = (int) $quote->amountMinor;
        $isLocal = $quote->kind !== 'carrier';

        $verified = $this->handles->verify($quoteHandle, $offers->cartId, $shippingMethodId, $amountMinor, $quote->currency, $offers->pricingHash, $quote->serviceCode);

        if (! $verified) {
            if ($expectedShippingMinor !== null && $expectedShippingMinor !== $amountMinor) {
                throw ShippingRefusal::priceChanged(Money::fromMinorUnits($amountMinor, $quote->currency));
            }

            if (! ($isLocal && $expectedShippingMinor === $amountMinor)) {
                throw ShippingRefusal::because(ShippingRefusalReason::QUOTE_EXPIRED, 'The quote handle is unknown, expired or does not match this cart and price.');
            }
        }

        return new ShippingSelection(
            $quote->methodId,
            $quote->name,
            $quote->courier,
            $quote->deliveryType,
            Money::fromMinorUnits($amountMinor, $quote->currency),
            $quote->serviceCode,
            $quote->requiresPickupPoint,
            $quote->kind,
        );
    }
}
