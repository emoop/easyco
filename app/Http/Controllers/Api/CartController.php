<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Cart\Exceptions\InsufficientStockForCartException;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The Cart HTTP surface — see cart-domain-design.md §4/§10 for the
 * identification rules (account vs. session-token) and §4 for why
 * PriceContext assembly (Catalog-reading) lives here rather than
 * inside the Cart package itself.
 *
 * PRICE-NOT-CONFIGURED HANDLING: store() catches
 * EasyCo\Pricing\Exceptions\PriceNotConfiguredException specifically
 * (never a broad RuntimeException — that would also swallow
 * EloquentPriceResolver's OTHER, genuinely system-misconfiguration
 * throw site, which must keep failing loudly) and returns a clean 422
 * — see cart-domain-design.md §12. NARROWER RESIDUAL GAP, still real:
 * this is only wired into the add-line flow. serializeCart() (used by
 * both index() and the response of every write) does not catch it —
 * a GET /api/cart for a cart that already holds a line whose price
 * was since unset would still surface an uncaught 500. Not fixed here
 * because it wasn't this task's scope; flagged, not silently left
 * undocumented.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartLineAdder $cartLineAdder,
        private readonly VariationRepository $variations,
        private readonly StockLevelRepository $stockLevels,
        private readonly PriceResolver $priceResolver,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->serializeCart($this->findCurrentCart($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'variation_id' => 'required|exists:catalog_variations,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $variationId = (string) $validated['variation_id'];
        $variation = $this->variations->findById($variationId);

        if ($variation === null || ! $variation->isEffectivelyPurchasable()) {
            return response()->json([
                'message' => "Variation \"{$variationId}\" is not currently purchasable.",
            ], 422);
        }

        $cart = $this->currentOrNewCart($request);
        $cart->refreshExpiry($this->expiryFor($cart));

        $currency = DefaultCurrency::get();

        try {
            $quote = $this->priceResolver->resolve(new PriceContext(
                priceableId: $variation->priceableId(),
                quantity: $validated['quantity'],
                currency: $currency->code(),
            ));
        } catch (PriceNotConfiguredException) {
            return response()->json([
                'message' => "Variation \"{$variationId}\" has no price configured yet and cannot be added to a cart.",
            ], 422);
        }

        $unitPrice = $quote->final->gross();

        try {
            $this->cartLineAdder->addLine(
                $cart,
                $variationId,
                $validated['quantity'],
                $unitPrice->minorValue(),
                $unitPrice->currency()->code(),
            );
        } catch (InsufficientStockForCartException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'variation_id' => $e->variationId,
                'requested' => $e->requested,
                'available' => $e->available,
            ], 422);
        }

        return response()->json($this->serializeCart($cart), 201);
    }

    public function update(Request $request, string $variationId): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = $this->findCurrentCart($request);

        if ($cart === null || ! $this->cartHasLine($cart, $variationId)) {
            return response()->json([
                'message' => "Variation \"{$variationId}\" is not in this cart.",
            ], 404);
        }

        $available = $this->stockLevels->findByVariationId($variationId)->quantity();

        if ($validated['quantity'] > $available) {
            return response()->json([
                'message' => "Cannot set quantity to {$validated['quantity']}: only {$available} available.",
                'variation_id' => $variationId,
                'requested' => $validated['quantity'],
                'available' => $available,
            ], 422);
        }

        $cart->updateLineQuantity($variationId, $validated['quantity']);
        $cart->refreshExpiry($this->expiryFor($cart));
        $this->carts->save($cart);

        return response()->json($this->serializeCart($cart));
    }

    public function destroy(Request $request, string $variationId): JsonResponse
    {
        $cart = $this->findCurrentCart($request);

        if ($cart === null || ! $this->cartHasLine($cart, $variationId)) {
            return response()->json([
                'message' => "Variation \"{$variationId}\" is not in this cart.",
            ], 404);
        }

        $cart->removeLine($variationId);
        $cart->refreshExpiry($this->expiryFor($cart));
        $this->carts->save($cart);

        return response()->json(null, 204);
    }

    private function cartHasLine(Cart $cart, string $variationId): bool
    {
        foreach ($cart->lines() as $line) {
            if ($line->variationId() === $variationId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read-only cart resolution — never creates a cart or a session
     * token. See cart-domain-design.md §10: a guest's cart token is
     * only ever generated on a WRITE, never a read.
     */
    private function findCurrentCart(Request $request): ?Cart
    {
        if (Auth::guard('customer')->check()) {
            return $this->carts->findByAccountId((string) Auth::guard('customer')->id());
        }

        $token = $request->session()->get('cart_token');

        return $token !== null ? $this->carts->findBySessionToken($token) : null;
    }

    /**
     * Write-path cart resolution — creates an (unsaved) Cart and, for
     * a guest with no session token yet, a fresh server-generated
     * token stored in the session, if neither already exists.
     * Deliberately never accepts a client-supplied token from the
     * request itself (cart-domain-design.md §10) — that would let
     * anyone read anyone else's cart by guessing one.
     */
    private function currentOrNewCart(Request $request): Cart
    {
        if (Auth::guard('customer')->check()) {
            $accountId = (string) Auth::guard('customer')->id();

            return $this->carts->findByAccountId($accountId) ?? Cart::forAccount($accountId, $this->expiry(true));
        }

        $token = $request->session()->get('cart_token');

        if ($token === null) {
            $token = (string) Str::uuid();
            $request->session()->put('cart_token', $token);
        }

        return $this->carts->findBySessionToken($token) ?? Cart::forGuest($token, $this->expiry(false));
    }

    private function expiryFor(Cart $cart): DateTimeImmutable
    {
        return $this->expiry($cart->accountId() !== null);
    }

    /** 30 days for account carts, 10 for guest carts — cart-domain-design.md §9. */
    private function expiry(bool $isAccountCart): DateTimeImmutable
    {
        return new DateTimeImmutable($isAccountCart ? '+30 days' : '+10 days');
    }

    private function serializeCart(?Cart $cart): array
    {
        $currency = DefaultCurrency::get();
        $total = Money::fromMinorUnits(0, $currency);

        if ($cart === null || $cart->isEmpty()) {
            return ['lines' => [], 'total' => $this->moneyToArray($total)];
        }

        $lines = [];

        foreach ($cart->lines() as $line) {
            $quote = $this->priceResolver->resolve(new PriceContext(
                priceableId: $line->variationId(),
                quantity: $line->quantity(),
                currency: $currency->code(),
            ));

            $unitPrice = $quote->final->gross();
            $lineTotal = $unitPrice->multiply($line->quantity());
            $total = $total->add($lineTotal);

            $priceChanged = $line->priceAtAddMinor() !== null && (
                $line->priceAtAddMinor() !== $unitPrice->minorValue()
                || $line->priceAtAddCurrency() !== $unitPrice->currency()->code()
            );

            $lines[] = [
                'variation_id' => $line->variationId(),
                'quantity' => $line->quantity(),
                'price_at_add' => $line->priceAtAddMinor() !== null
                    ? $this->moneyToArray(Money::fromMinorUnits($line->priceAtAddMinor(), $line->priceAtAddCurrency()))
                    : null,
                'unit_price' => $this->moneyToArray($unitPrice),
                'line_total' => $this->moneyToArray($lineTotal),
                'price_changed_since_add' => $priceChanged,
            ];
        }

        return ['lines' => $lines, 'total' => $this->moneyToArray($total)];
    }

    private function moneyToArray(Money $money): array
    {
        return ['minor' => $money->minorValue(), 'currency' => $money->currency()->code()];
    }
}
