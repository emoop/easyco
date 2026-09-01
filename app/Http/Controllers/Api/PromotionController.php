<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Exceptions\PromotionCodeAlreadyExistsException;
use EasyCo\Promotions\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for the global, reusable Promotion set. Deliberately
 * minimal, mirroring BrandController's style: no auth, no form request
 * class, no resource transformer — store()/index() only, no
 * update/delete for now.
 *
 * NO PromotionScope attach/list/detach here, and NO Cart integration —
 * both are separate, later prompts. This controller only lets a
 * merchant create and list promo codes.
 */
class PromotionController extends Controller
{
    public function __construct(
        private readonly PromotionRepository $promotions,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255',
            'discount_type' => 'required|in:percentage,fixed_amount',
            'percentage_basis_points' => 'required_if:discount_type,percentage|prohibited_if:discount_type,fixed_amount|integer|min:0|max:10000',
            'discount_amount' => 'required_if:discount_type,fixed_amount|prohibited_if:discount_type,percentage|numeric|min:0',
            'individual_use_only' => 'boolean',
            'exclude_sale_items' => 'boolean',
            'new_customers_only' => 'boolean',
            'minimum_spend' => 'nullable|numeric|min:0',
            'maximum_spend' => 'nullable|numeric|min:0',
            'usage_limit_total' => 'nullable|integer|min:1',
            'usage_limit_per_customer' => 'nullable|integer|min:1',
            'usage_limit_items' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date',
        ]);

        $currency = DefaultCurrency::get();
        $discountType = PromotionDiscountType::from($validated['discount_type']);

        $promotion = Promotion::create(
            code: $validated['code'],
            discountType: $discountType,
            percentageBasisPoints: $validated['percentage_basis_points'] ?? null,
            discountAmount: $discountType === PromotionDiscountType::FIXED_AMOUNT
                ? Money::fromDecimal((string) $validated['discount_amount'], $currency)
                : null,
            individualUseOnly: $validated['individual_use_only'] ?? false,
            excludeSaleItems: $validated['exclude_sale_items'] ?? false,
            minimumSpend: isset($validated['minimum_spend'])
                ? Money::fromDecimal((string) $validated['minimum_spend'], $currency)
                : null,
            maximumSpend: isset($validated['maximum_spend'])
                ? Money::fromDecimal((string) $validated['maximum_spend'], $currency)
                : null,
            newCustomersOnly: $validated['new_customers_only'] ?? false,
            usageLimitTotal: $validated['usage_limit_total'] ?? null,
            usageLimitPerCustomer: $validated['usage_limit_per_customer'] ?? null,
            usageLimitItems: $validated['usage_limit_items'] ?? null,
            validFrom: isset($validated['valid_from']) ? new DateTimeImmutable($validated['valid_from']) : null,
            validUntil: isset($validated['valid_until']) ? new DateTimeImmutable($validated['valid_until']) : null,
        );

        try {
            $this->promotions->save($promotion);
        } catch (PromotionCodeAlreadyExistsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->toListItem($promotion), 201);
    }

    public function index(): JsonResponse
    {
        $promotions = array_map(
            fn (Promotion $promotion) => $this->toListItem($promotion),
            $this->promotions->all()
        );

        return response()->json($promotions);
    }

    private function toListItem(Promotion $promotion): array
    {
        return [
            'id' => $promotion->id(),
            'code' => $promotion->code(),
            'discount_type' => $promotion->discountType()->value,
            'percentage_basis_points' => $promotion->percentageBasisPoints(),
            'discount_amount' => $this->moneyToArray($promotion->discountAmount()),
            'individual_use_only' => $promotion->individualUseOnly(),
            'exclude_sale_items' => $promotion->excludeSaleItems(),
            'minimum_spend' => $this->moneyToArray($promotion->minimumSpend()),
            'maximum_spend' => $this->moneyToArray($promotion->maximumSpend()),
            'new_customers_only' => $promotion->newCustomersOnly(),
            'usage_limit_total' => $promotion->usageLimitTotal(),
            'usage_limit_per_customer' => $promotion->usageLimitPerCustomer(),
            'usage_limit_items' => $promotion->usageLimitItems(),
            'valid_from' => $promotion->validFrom()?->format(DateTimeImmutable::ATOM),
            'valid_until' => $promotion->validUntil()?->format(DateTimeImmutable::ATOM),
            'status' => $promotion->status()->value,
        ];
    }

    private function moneyToArray(?Money $money): ?array
    {
        if ($money === null) {
            return null;
        }

        return ['amount' => $money->decimalValue(), 'currency' => $money->currency()->code()];
    }
}
