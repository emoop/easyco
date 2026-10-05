<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\PlainText;
use DateTimeImmutable;
use EasyCo\Pricing\Currency;
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

    /**
     * The three amounts (discount_amount, minimum_spend, maximum_spend) are
     * plain decimal STRINGS, checked against exactly the shape and the
     * precision Money::fromDecimal() parses them with further down:
     *
     *  - at most 9 integer digits, so the biginteger of minor units cannot
     *    overflow (999999999.99 EUR is 99999999999 minor units);
     *  - no more fractional digits than the currency has places;
     *  - no sign (a negative discount is not something this endpoint accepts),
     *    no exponent, no thousands separator, no spaces, no comma.
     *
     * `numeric|min:0` alone is NOT enough, and its failure mode is a 500 rather
     * than a 422: `1e5` passes `numeric`, and then Money::fromDecimal() throws
     * an uncaught InvalidArgumentException; `1,5` does the same. A value with
     * more decimals than the currency uses is silently ROUNDED half-up by Money
     * — Money is deliberately left exactly as it is (see its own docblock), so
     * the scale is refused here instead: a merchant who typed 10.999 must be
     * told, not quietly charged 11.00.
     */
    public function store(Request $request): JsonResponse
    {
        $currency = DefaultCurrency::get();
        $amount = self::plainAmountPattern($currency);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', new PlainText()],
            'discount_type' => 'required|in:percentage,fixed_amount',
            'percentage_basis_points' => 'required_if:discount_type,percentage|prohibited_if:discount_type,fixed_amount|integer|min:0|max:10000',
            'discount_amount' => ['required_if:discount_type,fixed_amount', 'prohibited_if:discount_type,percentage', 'numeric', 'min:0', 'regex:'.$amount],
            'individual_use_only' => 'boolean',
            'exclude_sale_items' => 'boolean',
            'new_customers_only' => 'boolean',
            'minimum_spend' => ['nullable', 'numeric', 'min:0', 'regex:'.$amount],
            'maximum_spend' => ['nullable', 'numeric', 'min:0', 'regex:'.$amount],
            'usage_limit_total' => 'nullable|integer|min:1',
            'usage_limit_per_customer' => 'nullable|integer|min:1',
            'usage_limit_items' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date',
        ], self::amountFormatMessages());

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

    /**
     * The one pattern all three amounts share: digits only, an optional dot
     * with at most as many decimals as the currency has minor-unit places, at
     * most nine integer digits, anchored at both ends so a leading/trailing
     * space is a refusal rather than a silent trim.
     */
    private static function plainAmountPattern(Currency $currency): string
    {
        return '/^\d{1,9}(\.\d{1,'.$currency->decimalPlaces().'})?$/';
    }

    /**
     * `regex` is the only rule here whose framework message would be the bare
     * "format is invalid" sentence, and this project's own lang key says what a
     * merchant actually has to type.
     *
     * @return array<string, string>
     */
    private static function amountFormatMessages(): array
    {
        return [
            'discount_amount.regex' => __('validation.money_format'),
            'minimum_spend.regex' => __('validation.money_format'),
            'maximum_spend.regex' => __('validation.money_format'),
        ];
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
