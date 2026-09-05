<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CatalogScopeResolver;
use App\Services\PromotionDiscountCalculator;
use App\Services\PromotionUsageContext;
use App\Services\PromotionValidator;
use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Cart\Exceptions\InsufficientStockForCartException;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LogicException;

/**
 * The Cart HTTP surface — see cart-domain-design.md §4/§10 for the
 * identification rules (account vs. session-token) and §4 for why
 * PriceContext assembly (Catalog-reading) lives here rather than
 * inside the Cart package itself.
 *
 * PRICE-NOT-CONFIGURED HANDLING, in two different places, on purpose
 * (never a broad RuntimeException catch in either — that would also
 * swallow EloquentPriceResolver's OTHER, genuinely
 * system-misconfiguration throw site, which must keep failing loudly)
 * — see cart-domain-design.md §12:
 * - store() catches EasyCo\Pricing\Exceptions\PriceNotConfiguredException
 *   around the line being actively added and returns a clean 422 —
 *   you cannot add an unpriced variation to a cart.
 * - serializeCart() catches it per line, individually, for lines
 *   ALREADY in the cart whose price has since been fully removed — a
 *   single unpriced line degrades gracefully (unit_price/line_total
 *   null, price_available: false, excluded from the cart total)
 *   rather than taking down the entire GET /api/cart response, or the
 *   response body of any write.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartLineAdder $cartLineAdder,
        private readonly VariationRepository $variations,
        private readonly StockLevelRepository $stockLevels,
        private readonly PriceResolver $priceResolver,
        private readonly CatalogScopeResolver $catalogScopeResolver,
        private readonly PromotionRepository $promotions,
        private readonly PromotionScopeRepository $promotionScopes,
        private readonly PromotionValidator $promotionValidator,
        private readonly PromotionDiscountCalculator $promotionDiscountCalculator,
        private readonly OrderRepository $orders,
        private readonly PromotionRedemptionRepository $promotionRedemptions,
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
        $scope = $this->catalogScopeResolver->forVariation($variationId);

        try {
            $quote = $this->priceResolver->resolve(new PriceContext(
                priceableId: $variation->priceableId(),
                quantity: $validated['quantity'],
                currency: $currency->code(),
                productId: $scope['productId'],
                matchingScopeReferenceIds: $scope['matchingScopeReferenceIds'],
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

    /**
     * Applies a promo code to the current cart — does NOT compute or
     * reflect any discount amount; that's a separate, later prompt.
     * This only confirms the code corresponds to a real Promotion and
     * stores it on the Cart. Validity (is it still active, does the
     * scope match, etc.) is always recomputed live by serializeCart(),
     * never cached here.
     */
    public function applyPromotion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $cart = $this->findCurrentCart($request);

        if ($cart === null) {
            return response()->json(['message' => 'No cart to apply a promotion to.'], 404);
        }

        if ($this->promotions->findByCode($validated['code']) === null) {
            return response()->json([
                'message' => "No promotion with code \"{$validated['code']}\" exists.",
            ], 422);
        }

        $cart->applyPromotionCode($validated['code']);
        $cart->refreshExpiry($this->expiryFor($cart));
        $this->carts->save($cart);

        return response()->json($this->serializeCart($cart));
    }

    /**
     * Clears whatever promo code is currently applied — always safe,
     * even if nothing was applied, same no-op-safe posture
     * Cart::clearPromotionCode() itself already has.
     */
    public function removePromotion(Request $request): JsonResponse
    {
        $cart = $this->findCurrentCart($request);

        if ($cart === null) {
            return response()->json(['message' => 'No cart to remove a promotion from.'], 404);
        }

        $cart->clearPromotionCode();
        $cart->refreshExpiry($this->expiryFor($cart));
        $this->carts->save($cart);

        return response()->json($this->serializeCart($cart));
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
        $subtotal = Money::fromMinorUnits(0, $currency);

        if ($cart === null) {
            return [
                'lines' => [],
                'subtotal' => $this->moneyToArray($subtotal),
                'total' => $this->moneyToArray($subtotal),
                'promotion' => null,
            ];
        }

        $lines = [];
        $validatorLines = [];

        foreach ($cart->lines() as $line) {
            $priceAtAdd = $line->priceAtAddMinor() !== null
                ? $this->moneyToArray(Money::fromMinorUnits($line->priceAtAddMinor(), $line->priceAtAddCurrency()))
                : null;

            $scope = $this->catalogScopeResolver->forVariation($line->variationId());

            try {
                $quote = $this->priceResolver->resolve(new PriceContext(
                    priceableId: $line->variationId(),
                    quantity: $line->quantity(),
                    currency: $currency->code(),
                    productId: $scope['productId'],
                    matchingScopeReferenceIds: $scope['matchingScopeReferenceIds'],
                ));
            } catch (PriceNotConfiguredException) {
                // A line already sitting in the cart whose price has
                // since been fully removed — must not take down the
                // whole cart response (cart-domain-design.md §12).
                // Excluded from $subtotal entirely: treating it as 0
                // would silently understate what the customer owes.
                $lines[] = [
                    'variation_id' => $line->variationId(),
                    'quantity' => $line->quantity(),
                    'price_at_add' => $priceAtAdd,
                    'unit_price' => null,
                    'line_total' => null,
                    'price_changed_since_add' => false,
                    'price_available' => false,
                ];

                continue;
            }

            $unitPrice = $quote->final->gross();
            $lineTotal = $unitPrice->multiply($line->quantity());
            $subtotal = $subtotal->add($lineTotal);

            $priceChanged = $line->priceAtAddMinor() !== null && (
                $line->priceAtAddMinor() !== $unitPrice->minorValue()
                || $line->priceAtAddCurrency() !== $unitPrice->currency()->code()
            );

            $lines[] = [
                'variation_id' => $line->variationId(),
                'quantity' => $line->quantity(),
                'price_at_add' => $priceAtAdd,
                'unit_price' => $this->moneyToArray($unitPrice),
                'line_total' => $this->moneyToArray($lineTotal),
                'price_changed_since_add' => $priceChanged,
                'price_available' => true,
            ];

            // Carries both what PromotionValidator needs (productId/
            // matchingScopeReferenceIds/isDiscounted) and what
            // PromotionDiscountCalculator needs (quantity/unitPrice/
            // lineTotal) — one array, extended rather than recomputed
            // twice; each consumer reads only the keys it needs.
            $validatorLines[] = [
                'variationId' => $line->variationId(),
                'quantity' => $line->quantity(),
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineTotal,
                'productId' => $scope['productId'],
                'matchingScopeReferenceIds' => $scope['matchingScopeReferenceIds'],
                'isDiscounted' => $quote->isDiscounted(),
            ];
        }

        ['promotion' => $promotion, 'discountAmount' => $discountAmount] = $this->resolvePromotion($cart, $subtotal, $validatorLines);

        $total = $subtotal;

        if ($discountAmount !== null) {
            $total = $subtotal->subtract($discountAmount);

            if ($total->isNegative()) {
                // Structurally impossible given FIXED_AMOUNT capping at
                // the eligible base (PromotionDiscountCalculator) — a
                // real bug to surface loudly, not silently clamp away.
                throw new LogicException(
                    'Cart total went negative after applying a Promotion discount — this should never happen.'
                );
            }
        }

        return [
            'lines' => $lines,
            'subtotal' => $this->moneyToArray($subtotal),
            'total' => $this->moneyToArray($total),
            'promotion' => $promotion,
        ];
    }

    /**
     * Live-revalidates whatever promo code is currently stored on the
     * Cart, freshly, every call — NEVER persists anything as a side
     * effect (this is called from index()/serializeCart() on every
     * GET, not just writes). An invalid applied code stays stored on
     * the Cart; only an explicit DELETE /api/cart/promotion removes it
     * — same graceful-degradation posture already established for a
     * line whose price was fully removed (cart-domain-design.md §12).
     *
     * Discount computation only ever runs once the code is confirmed
     * valid — PromotionDiscountCalculator has no opinion on validity,
     * same separation PromotionValidator/PromotionDiscountCalculator
     * keep from each other everywhere else.
     *
     * @param array<int, array{variationId: string, quantity: int, unitPrice: Money, lineTotal: Money, productId: ?string, matchingScopeReferenceIds: array<string, string[]>, isDiscounted: bool}> $validatorLines
     * @return array{promotion: ?array, discountAmount: ?Money}
     */
    private function resolvePromotion(Cart $cart, Money $subtotal, array $validatorLines): array
    {
        $code = $cart->appliedPromotionCode();

        if ($code === null) {
            return ['promotion' => null, 'discountAmount' => null];
        }

        $promotion = $this->promotions->findByCode($code);

        if ($promotion === null) {
            // The Promotion was deleted after being applied — a real,
            // if rare, edge case. Reported, not thrown.
            return [
                'promotion' => $this->invalidPromotionResponse($code, 'not_found'),
                'discountAmount' => null,
            ];
        }

        $scopes = $this->promotionScopes->findByPromotionId($promotion->id());
        $accountId = Auth::guard('customer')->check() ? (string) Auth::guard('customer')->id() : null;

        // Each fact is only queried when the Promotion actually has the
        // setting that consumes it — PromotionValidator reads each
        // getter exclusively inside a branch already gated by that same
        // setting (see its own class docblock/checks), so a Promotion
        // with none of these flags costs zero extra queries here. The
        // false/0 values below in the unqueried case mean "not queried
        // because no setting consumes it", NOT "genuinely zero" — see
        // PromotionUsageContext's own docblock.
        $usage = new PromotionUsageContext(
            customerHasPreviousOrders: $promotion->newCustomersOnly()
                && $accountId !== null
                && $this->orders->hasAnyForAccount($accountId),
            redemptionsTotal: $promotion->usageLimitTotal() !== null
                ? $this->promotionRedemptions->countForPromotion($promotion->id())
                : 0,
            redemptionsForAccount: $promotion->usageLimitPerCustomer() !== null && $accountId !== null
                ? $this->promotionRedemptions->countForPromotionAndAccount($promotion->id(), $accountId)
                : 0,
        );

        $result = $this->promotionValidator->validate($promotion, $scopes, $subtotal, $accountId, $validatorLines, $usage);

        if (! $result->isValid()) {
            return [
                'promotion' => $this->invalidPromotionResponse($code, $result->reason()),
                'discountAmount' => null,
            ];
        }

        $applicableIds = array_flip($result->applicableVariationIds());
        $applicableLines = array_values(array_filter(
            $validatorLines,
            static fn (array $line) => isset($applicableIds[$line['variationId']])
        ));

        $discountResult = $this->promotionDiscountCalculator->calculate($promotion, $applicableLines);

        return [
            'promotion' => [
                'code' => $code,
                'valid' => true,
                'reason' => null,
                'applicable_variation_ids' => $result->applicableVariationIds(),
                'discount_amount' => $this->moneyToArray($discountResult->amount()),
                'discount_capped' => $discountResult->discountCapped(),
                'nominal_discount_amount' => $discountResult->nominalAmount() !== null
                    ? $this->moneyToArray($discountResult->nominalAmount())
                    : null,
            ],
            'discountAmount' => $discountResult->amount(),
        ];
    }

    private function invalidPromotionResponse(string $code, ?string $reason): array
    {
        return [
            'code' => $code,
            'valid' => false,
            'reason' => $reason,
            'applicable_variation_ids' => [],
            'discount_amount' => null,
            'discount_capped' => false,
            'nominal_discount_amount' => null,
        ];
    }

    private function moneyToArray(Money $money): array
    {
        return ['minor' => $money->minorValue(), 'currency' => $money->currency()->code()];
    }
}
