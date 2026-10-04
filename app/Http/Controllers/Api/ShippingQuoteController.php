<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\KnownCountryCode;
use App\Services\Exceptions\ShippingQuoteFilterException;
use App\Services\Exceptions\ShippingQuoteRefusedException;
use App\Services\MethodQuote;
use App\Services\QuoteDestination;
use App\Services\ShippingQuoteResult;
use App\Services\ShippingQuoteService;
use App\Settings\StoreLocale;
use EasyCo\Address\Enums\AddressDeliveryType;
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
 * `postal_code` (optional, ignored for a pickup point) — OR `address_id` alone, one
 * of the customer's saved addresses (customer session only; scoped to the account).
 *
 * A PICKUP-POINT DELIVERY IS QUOTED FROM THE SETTLEMENT THE CLIENT SENDS. The client
 * MUST QUOTE AGAIN AFTER THE CUSTOMER CHOOSES A POINT: the chosen point's settlement
 * may fall in another shipping zone than the one the customer first typed, and then
 * both the methods and the prices differ. A handle belongs to the quote it came with.
 *
 * RESPONSE 200: cart_id, currency, goods_after_discount, zone {id, name}, and
 * `methods` — every offered method with id, name, kind, requires_pickup_point,
 * `available`, `price` {minor, currency} and `handle` (priced methods) or
 * `unavailable_reason` (carrier problems: provider_error, timed_out,
 * invalid_response, not_configured, no_quote, no_settlement); `service_code` for a
 * carrier method.
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
            'address_id' => 'nullable|string|prohibits:delivery_type,country,settlement,postal_code',
            'delivery_type' => 'required_without:address_id|in:street_address,pickup_point',
            'country' => ['required_without:address_id', 'string', new KnownCountryCode('delivery.country.invalid')],
            'settlement' => 'required_without:address_id|string|min:1|max:255',
            'postal_code' => 'nullable|string|max:20',
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

            $result = $addressId !== null
                ? $this->quotes->quoteForSavedAddress($cart, (string) $accountId, (string) $addressId)
                : $this->quotes->quote($cart, $accountId, new QuoteDestination(
                    AddressDeliveryType::from($validated['delivery_type']),
                    $validated['country'],
                    $validated['settlement'],
                    $validated['postal_code'] ?? null,
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
            'methods' => array_map(static fn (MethodQuote $m): array => [
                'id' => $m->methodId,
                'name' => $m->name,
                'kind' => $m->kind,
                'requires_pickup_point' => $m->requiresPickupPoint,
                'available' => $m->isAvailable(),
                'price' => $m->isAvailable() ? ['minor' => $m->amountMinor, 'currency' => $m->currency] : null,
                'service_code' => $m->serviceCode,
                'handle' => $m->handle,
                'unavailable_reason' => $m->unavailableReason,
            ], $result->methods),
        ];
    }
}
