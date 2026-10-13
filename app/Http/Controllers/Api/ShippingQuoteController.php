<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\KnownCountryCode;
use App\Services\Exceptions\ShippingQuoteFilterException;
use App\Services\Exceptions\ShippingQuoteRefusedException;
use App\Services\AllKindsQuoteResult;
use App\Services\MethodQuote;
use App\Services\QuoteDestination;
use App\Services\QuoteGroups;
use App\Services\ShippingQuoteResult;
use App\Services\ShippingQuoteService;
use App\Settings\StoreLocale;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\Contracts\CartRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/shipping/quote — the shipping price of the CURRENT cart to a
 * destination (shipping-domain-design.md §6.8). Public, like /cart and /checkout:
 * guests work, so it sits outside the staff group; throttled by the named limiter
 * `shipping-quote` (App\Http\ApiRateLimits).
 *
 * THE CART IS RESOLVED SERVER-SIDE, exactly as GET /cart does: a signed-in
 * customer's own cart, else the guest session's cart. A client-supplied cart token
 * or cart id is never read. No cart is an empty cart (422 `empty_cart`).
 *
 * REQUEST: `delivery_type` (street_address|pickup_point), `country` (the two-letter
 * code, trimmed and uppercased, checked against the country list), `settlement`
 * (required, trimmed: a street address's city, or the pickup point's settlement),
 * `postal_code` (optional, ignored for a pickup point), `pickup_kind` (optional, office|locker, pickup
 * point only — shipping stage 5g-2a) — OR `address_id` alone, one
 * of the customer's saved addresses (customer session only; scoped to the account; it uses its own
 * stored kind, so `pickup_kind` is refused with it).
 *
 * THE PICKUP KIND (stage 5g-2a, design 9.3.6): the free-shipping set of a method is per kind (address, office, locker), so a
 * pickup quote with `pickup_kind` is priced for THAT kind; without it the pickup point is "kind unspecified" and is free only
 * when BOTH office and locker are free (never cheaper than a stated kind). The kind is part of the pricing hash, so a handle
 * quoted for one kind never verifies for another at checkout. The response shape is unchanged.
 *
 * THE `all` QUOTE (stage 5g-2b, design 9.3.4): `delivery_type=all` prices EVERY kind of destination from one request. `country` is
 * required, `settlement` is OPTIONAL (trimmed, max 255), `postal_code` optional (the address kind only), `pickup_kind` and
 * `address_id` are refused with it (a saved address is always a single-kind quote). WITH a settlement the answer is FINAL: a
 * handle per (method, kind), each from that kind's own pricing hash, so it verifies at checkout for that kind and no other.
 * WITHOUT one it is a PREVIEW (`preview: true`): both buckets are matched as the cart hint matches them (no settlement, no
 * postcode), the prices are indicative and NO handle is issued; a carrier method is unavailable `no_settlement`.
 * Response: the single-kind shape plus `mode` ('single'|'all'), `preview` and `zones` {address, pickup} (each {id, name} or null;
 * `zone` stays the first matched bucket's: the address one, else the pickup one; neither matching is the usual 422
 * `no_zone_for_destination`). In `all` mode each method has NO method-level price, handle, threshold facts or
 * `serves_destination` (null); `available` is "available for at least one kind"; and `destinations` is an object keyed
 * address | office | locker holding only the kinds the method serves in a bucket whose zone offers it: {available, price, handle,
 * free_applies, free_above_minor, remaining_to_free_minor, unavailable_reason}. Methods are in order of first appearance (the
 * address bucket first). In a single-kind quote `destinations` is absent. Each `groups[]` entry has `destinations` (address,
 * office, locker: the union over its methods) and, in an `all` quote, a `from_minor` over its methods' kinds. The merchant filter
 * runs once per kind (its context carries `destination_kind`). The limiter counts an `all` request as ONE hit.
 *
 * THE HINT is kind-aware: `free_shipping_hint.destinations` lists the kinds of the chosen method's free shipping IN SCOPE of the
 * request (the asked kind; the matched buckets' kinds for `all`), and the sentence names them ("to an office or a locker") unless
 * they are every kind the method serves in that scope, when it is the sentence it always was.
 *
 * A PICKUP-POINT DELIVERY IS QUOTED FROM THE SETTLEMENT THE CLIENT SENDS. The client
 * MUST QUOTE AGAIN AFTER THE CUSTOMER CHOOSES A POINT: the chosen point's settlement
 * may fall in another shipping zone than the one the customer first typed, and then
 * both the methods and the prices differ. A handle belongs to the quote it came with.
 *
 * RESPONSE 200: cart_id, currency, goods_after_discount, zone {id, name}, and
 * `methods` — every offered method with id, name, kind, requires_pickup_point,
 * destination_scope, serves_destination, `available`, `price` {minor, currency} and `handle` (priced methods) or
 * `unavailable_reason` (carrier problems: provider_error, timed_out,
 * invalid_response, not_configured, no_quote, no_settlement, destination_not_served); `service_code` for a
 * carrier method.
 *
 * DESTINATION SCOPE (shipping stage 6b, design 9.2.4): `destination_scope` is the merchant's rule —
 * `address` (a street address only), `pickup` (a pickup point only) or `any` (both) — and
 * `serves_destination` says whether the method serves THIS request's own kind of destination (the
 * scope's one rule, ShippingDestinationScope::serves()). `requires_pickup_point` stays, now DERIVED:
 * TRUE ONLY FOR A PICKUP-ONLY METHOD; false means "not pickup-only" (address-only OR any), so a client
 * that wants to know where a method may go reads `destination_scope`. The quote still lists EVERY
 * active method of the zone, whatever its scope; the client shows the ones that serve the destination.
 * A CARRIER method whose scope does not serve the destination is not asked: it is unavailable with
 * `unavailable_reason: destination_not_served`.
 *
 * For a FLAT or PER_CLASS method that has a free-shipping
 * threshold, each method also carries `free_above_minor` and
 * `remaining_to_free_minor` (the threshold and how much MORE the goods must reach
 * it, 0 once met); both are null for a method without a threshold, for FREE and
 * for CARRIER. They are INFORMATION — the handle binds the amount, never these.
 *
 * The response also carries a top-level `free_shipping_hint` (stage 3e, §5.1):
 * null, or {state: remaining|unlocked, method_id, method_name, free_above_minor,
 * remaining_minor, currency, text} — the ONE sentence the storefront shows
 * ("Add 23.50 EUR more for free shipping with “Econt office”"), computed on the
 * same goods-after-discount basis and through the same matcher/calculator as the
 * methods. Its `text` embeds the MERCHANT's own method name (never any customer
 * input); it is plain text and the client renders the field escaped, like every
 * other merchant string.
 *
 * COURIER GROUPING (stage 5f): each method also carries `courier` (string|null) and `delivery_type`
 * (address|office|locker|other|null) — the method row's own display facts. The response carries a top-level `groups`:
 * the same methods grouped by courier (App\Services\QuoteGroups), built from the final list after the
 * `shipping.quotes` filter — one entry per courier in order of its first method, ungrouped methods in ONE trailing entry
 * with `courier: null`; each entry is {courier, methods: [method ids in order], from_minor (the lowest price among
 * its AVAILABLE methods, null if none), currency}. `methods` stays the flat list; nothing was removed or renamed.
 *
 * ERRORS, all `{message, reason}`: 422 `empty_cart`, `no_priced_lines`,
 * `no_zone_for_destination`, `address_incomplete`; 404 `address_not_found` (an
 * unknown address and another account's are the same answer); 422 validation
 * (Laravel's own `{message, errors}` shape); 429 `too_many_requests`; 500
 * `quote_filter_invalid` (a merchant's `shipping.quotes` filter returned something
 * refused; logged). EVERY MESSAGE IS TRANSLATED IN THE STORE'S LOCALE, set
 * explicitly from StoreLocale::current() for this request, because the `api`
 * middleware group never applies it (and restored afterwards).
 */
class ShippingQuoteController extends Controller
{
    public function __construct(
        private readonly ShippingQuoteService $quotes,
        private readonly CartRepository $carts,
        private readonly StoreLocale $storeLocale,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $previous = App::getLocale();
        App::setLocale($this->storeLocale->current());

        try {
            return $this->respond($request);
        } finally {
            App::setLocale($previous);
        }
    }

    private function respond(Request $request): JsonResponse
    {
        if ($request->has('country')) {
            $request->merge(['country' => KnownCountryCode::normalize($request->input('country'))]);
        }

        if (is_string($request->input('settlement'))) {
            $request->merge(['settlement' => trim($request->input('settlement'))]);
        }

        $validated = $request->validate([
            'address_id' => 'nullable|string|prohibits:delivery_type,country,settlement,postal_code,pickup_kind',
            'delivery_type' => 'required_without:address_id|in:street_address,pickup_point,all',
            'country' => ['required_without:address_id', 'string', new KnownCountryCode('delivery.country.invalid')],
            // Required for a street address and a pickup point; OPTIONAL for `all` (none = a PREVIEW, stage 5g-2b).
            'settlement' => ['required_if:delivery_type,street_address,pickup_point', 'nullable', 'string', 'min:1', 'max:255'],
            'postal_code' => 'nullable|string|max:20',
            // The pickup point's KIND (stage 5g-2a): the customer's dropdown choice, so the free-shipping set of each kind prices it. Optional:
            // none = a pickup point of unspecified kind, priced as cautiously as the dearest kind. A street address has none.
            'pickup_kind' => ['nullable', 'prohibited_if:delivery_type,street_address,all', 'string', 'in:office,locker'],
        ], [
            'country.required_without' => __('delivery.country.required'),
        ]);

        $accountId = Auth::guard('customer')->check() ? (string) Auth::guard('customer')->id() : null;
        $addressId = $validated['address_id'] ?? null;

        if ($addressId !== null && $accountId === null) {
            throw ValidationException::withMessages(['address_id' => __('shipping_quote.address_requires_login')]);
        }

        try {
            $cart = $this->currentCart($request);

            if ($cart === null) {
                throw new ShippingQuoteRefusedException(ShippingQuoteRefusedException::EMPTY_CART);
            }

            if (($validated['delivery_type'] ?? null) === 'all') {
                return response()->json($this->toArrayAll($this->quotes->quoteAllKinds($cart, $accountId, $validated['country'], $validated['settlement'] ?? null, $validated['postal_code'] ?? null)));
            }

            $result = $addressId !== null
                ? $this->quotes->quoteForSavedAddress($cart, (string) $accountId, (string) $addressId)
                : $this->quotes->quote($cart, $accountId, QuoteDestination::forAddress(
                    AddressDeliveryType::from($validated['delivery_type']),
                    $validated['country'],
                    $validated['settlement'],
                    $validated['postal_code'] ?? null,
                    isset($validated['pickup_kind']) ? ShippingDeliveryType::from($validated['pickup_kind']) : null,
                ));
        } catch (ShippingQuoteRefusedException $e) {
            return response()->json([
                'message' => __('shipping_quote.refusals.'.$e->reason),
                'reason' => $e->reason,
            ], $e->reason === ShippingQuoteRefusedException::ADDRESS_NOT_FOUND ? 404 : 422);
        } catch (ShippingQuoteFilterException $e) {
            // A merchant's own filter broke the contract (Hook policy: never papered over).
            Log::error('shipping.quotes filter refused.', ['reason' => $e->reason]);

            return response()->json([
                'message' => __('shipping_quote.quote_filter_invalid'),
                'reason' => 'quote_filter_invalid',
            ], 500);
        }

        return response()->json($this->toArray($result));
    }

    /** Read-only, as GET /cart: never creates a cart or a session token. */
    private function currentCart(Request $request): ?Cart
    {
        if (Auth::guard('customer')->check()) {
            return $this->carts->findByAccountId((string) Auth::guard('customer')->id());
        }

        $token = $request->session()->get('cart_token');

        return $token !== null ? $this->carts->findBySessionToken($token) : null;
    }

    /** @return array<string, mixed> */
    private function toArray(ShippingQuoteResult $result): array
    {
        return [
            'cart_id' => $result->cartId,
            'currency' => $result->currency,
            'goods_after_discount' => ['minor' => $result->goodsAfterDiscountMinor, 'currency' => $result->currency],
            'zone' => ['id' => $result->zoneId, 'name' => $result->zoneName],
            'mode' => 'single',
            'preview' => false,
            // Which bucket this single-kind quote matched; the other one was not asked.
            'zones' => [
                'address' => $result->bucket === 'address' ? ['id' => $result->zoneId, 'name' => $result->zoneName] : null,
                'pickup' => $result->bucket === 'pickup' ? ['id' => $result->zoneId, 'name' => $result->zoneName] : null,
            ],
            'methods' => array_map(static fn (MethodQuote $m): array => [
                'id' => $m->methodId,
                'name' => $m->name,
                'kind' => $m->kind,
                'requires_pickup_point' => $m->requiresPickupPoint,
                'destination_scope' => $m->scope(),
                'serves_destination' => $m->servesDestination,
                'available' => $m->isAvailable(),
                'price' => $m->isAvailable() ? ['minor' => $m->amountMinor, 'currency' => $m->currency] : null,
                'free_above_minor' => $m->freeAboveMinor,
                'remaining_to_free_minor' => $m->remainingToFreeMinor,
                'service_code' => $m->serviceCode,
                'handle' => $m->handle,
                'unavailable_reason' => $m->unavailableReason,
                'courier' => $m->courier,
                'delivery_type' => $m->deliveryType,
            ], $result->methods),
            'groups' => QuoteGroups::build($result->methods, $result->currency),
            'free_shipping_hint' => $result->freeShippingHint?->toArray(),
        ];
    }

    /**
     * The `all` quote as JSON (stage 5g-2b, design 9.3.4). Same top-level shape as a single-kind quote, plus `mode`, `preview` and
     * `zones`; each method carries `destinations` (keyed address | office | locker, ONLY the kinds the method serves in a bucket whose
     * zone offers it) and has no method-level price, handle, threshold facts or `serves_destination` (they belong to a kind).
     *
     * @return array<string, mixed>
     */
    private function toArrayAll(AllKindsQuoteResult $result): array
    {
        $methods = [];
        $representatives = [];
        $kindQuotes = [];

        foreach ($result->methods() as $method) {
            $first = $method['first'];
            $destinations = [];
            $availableAny = false;
            $reason = null;

            foreach (AllKindsQuoteResult::KINDS as $kind) {
                $quote = $method['kinds'][$kind] ?? null;

                if ($quote === null) {
                    continue;
                }

                $availableAny = $availableAny || $quote->isAvailable();
                $reason ??= $quote->unavailableReason;
                $destinations[$kind] = [
                    'available' => $quote->isAvailable(),
                    'price' => $quote->isAvailable() ? ['minor' => $quote->amountMinor, 'currency' => $quote->currency] : null,
                    'handle' => $quote->handle,
                    // The threshold applies to this kind (it serves the kind and the free set covers it): the facts below are then set.
                    'free_applies' => $quote->freeAboveMinor !== null,
                    'free_above_minor' => $quote->freeAboveMinor,
                    'remaining_to_free_minor' => $quote->remainingToFreeMinor,
                    'unavailable_reason' => $quote->unavailableReason,
                ];
            }

            $representatives[] = $first;
            $kindQuotes[$first->methodId] = $method['kinds'];
            $methods[] = [
                'id' => $first->methodId,
                'name' => $first->name,
                'kind' => $first->kind,
                'requires_pickup_point' => $first->requiresPickupPoint,
                'destination_scope' => $first->scope(),
                'serves_destination' => null,
                'available' => $availableAny,
                'price' => null,
                'free_above_minor' => null,
                'remaining_to_free_minor' => null,
                'service_code' => null,
                'handle' => null,
                'unavailable_reason' => $availableAny ? null : $reason,
                'courier' => $first->courier,
                'delivery_type' => $first->deliveryType,
                'destinations' => $destinations,
            ];
        }

        $zone = $result->firstZone();

        return [
            'cart_id' => $result->cartId,
            'currency' => $result->currency,
            'goods_after_discount' => ['minor' => $result->goodsAfterDiscountMinor, 'currency' => $result->currency],
            'zone' => $zone,
            'mode' => 'all',
            'preview' => $result->preview,
            'zones' => $result->zones,
            'methods' => $methods,
            'groups' => QuoteGroups::build($representatives, $result->currency, $kindQuotes),
            'free_shipping_hint' => $result->freeShippingHint?->toArray(),
        ];
    }
}
