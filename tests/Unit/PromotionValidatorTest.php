<?php

namespace Tests\Unit;

use App\Services\PromotionUsageContext;
use App\Services\PromotionValidator;
use DateTimeImmutable;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionScope;
use PHPUnit\Framework\TestCase;

final class PromotionValidatorTest extends TestCase
{
    private function validator(): PromotionValidator
    {
        return new PromotionValidator();
    }

    /** Neutral: no previous orders, zero redemptions anywhere — existing cases keep testing what they tested. */
    private function usage(
        bool $customerHasPreviousOrders = false,
        int $redemptionsTotal = 0,
        int $redemptionsForAccount = 0,
    ): PromotionUsageContext {
        return new PromotionUsageContext($customerHasPreviousOrders, $redemptionsTotal, $redemptionsForAccount);
    }

    private function promotion(
        bool $active = true,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
        ?Money $minimumSpend = null,
        ?Money $maximumSpend = null,
        bool $newCustomersOnly = false,
        bool $excludeSaleItems = false,
        ?int $usageLimitTotal = null,
        ?int $usageLimitPerCustomer = null,
    ): Promotion {
        $promotion = Promotion::create(
            code: 'test-'.uniqid(),
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 1000,
            excludeSaleItems: $excludeSaleItems,
            minimumSpend: $minimumSpend,
            maximumSpend: $maximumSpend,
            newCustomersOnly: $newCustomersOnly,
            usageLimitTotal: $usageLimitTotal,
            usageLimitPerCustomer: $usageLimitPerCustomer,
            validFrom: $validFrom,
            validUntil: $validUntil,
        );

        if (! $active) {
            $promotion->deactivate();
        }

        return $promotion;
    }

    private function scope(PromotionScopeType $scopeType, string $referenceId, PromotionScopeMode $mode): PromotionScope
    {
        return new PromotionScope(null, 'promo-1', $scopeType, $referenceId, $mode);
    }

    /** @return array{variationId: string, lineTotal: Money, productId: ?string, matchingScopeReferenceIds: array<string, string[]>, isDiscounted: bool} */
    private function line(
        string $variationId,
        ?string $productId = null,
        array $matchingScopeReferenceIds = [],
        bool $isDiscounted = false,
        string $amount = '10.00',
    ): array {
        return [
            'variationId' => $variationId,
            'lineTotal' => Money::fromDecimal($amount, 'EUR'),
            'productId' => $productId,
            'matchingScopeReferenceIds' => $matchingScopeReferenceIds,
            'isDiscounted' => $isDiscounted,
        ];
    }

    private function subtotal(string $amount = '10.00'): Money
    {
        return Money::fromDecimal($amount, 'EUR');
    }

    // --- lifecycle -----------------------------------------------------

    public function test_an_inactive_promotion_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(active: false),
            [],
            $this->subtotal(),
            null,
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('inactive', $result->reason());
    }

    public function test_a_promotion_not_yet_valid_from_the_future_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(validFrom: new DateTimeImmutable('+1 day')),
            [],
            $this->subtotal(),
            null,
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('not_yet_active', $result->reason());
    }

    public function test_an_expired_promotion_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(validUntil: new DateTimeImmutable('-1 day')),
            [],
            $this->subtotal(),
            null,
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('expired', $result->reason());
    }

    // --- spend thresholds ------------------------------------------------

    public function test_a_cart_subtotal_below_minimum_spend_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(minimumSpend: Money::fromDecimal('50.00', 'EUR')),
            [],
            $this->subtotal('49.99'),
            null,
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('minimum_spend_not_met', $result->reason());
    }

    public function test_a_cart_subtotal_exactly_at_minimum_spend_passes(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(minimumSpend: Money::fromDecimal('50.00', 'EUR')),
            [],
            $this->subtotal('50.00'),
            null,
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
    }

    public function test_a_cart_subtotal_above_maximum_spend_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(maximumSpend: Money::fromDecimal('100.00', 'EUR')),
            [],
            $this->subtotal('100.01'),
            null,
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('maximum_spend_exceeded', $result->reason());
    }

    public function test_a_cart_subtotal_exactly_at_maximum_spend_passes(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(maximumSpend: Money::fromDecimal('100.00', 'EUR')),
            [],
            $this->subtotal('100.00'),
            null,
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
    }

    // --- new customers only -----------------------------------------------

    public function test_new_customers_only_with_a_guest_cart_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(newCustomersOnly: true),
            [],
            $this->subtotal(),
            null,
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('new_customers_only', $result->reason());
    }

    public function test_new_customers_only_with_a_real_account_id_and_no_previous_orders_passes_this_check(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(newCustomersOnly: true),
            [],
            $this->subtotal(),
            'account-1',
            [$this->line('v1')],
            $this->usage(customerHasPreviousOrders: false),
        );

        $this->assertTrue($result->isValid());
    }

    /** The real gap this task closes: a logged-in account with fifty previous orders is not "new." */
    public function test_new_customers_only_with_an_account_that_has_previous_orders_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(newCustomersOnly: true),
            [],
            $this->subtotal(),
            'account-1',
            [$this->line('v1')],
            $this->usage(customerHasPreviousOrders: true),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('new_customers_only', $result->reason());
    }

    // --- ACCOUNT scope: cart-level gate -----------------------------------

    public function test_account_include_scope_matching_the_cart_passes(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::ACCOUNT, 'account-1', PromotionScopeMode::INCLUDE)],
            $this->subtotal(),
            'account-1',
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
    }

    public function test_account_include_scope_not_matching_the_cart_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::ACCOUNT, 'account-1', PromotionScopeMode::INCLUDE)],
            $this->subtotal(),
            'account-2',
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('account_scope_mismatch', $result->reason());
    }

    public function test_account_include_scope_with_a_guest_cart_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::ACCOUNT, 'account-1', PromotionScopeMode::INCLUDE)],
            $this->subtotal(),
            null,
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('account_scope_mismatch', $result->reason());
    }

    public function test_account_exclude_scope_matching_the_cart_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::ACCOUNT, 'account-1', PromotionScopeMode::EXCLUDE)],
            $this->subtotal(),
            'account-1',
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('account_scope_mismatch', $result->reason());
    }

    public function test_account_exclude_scope_not_matching_the_cart_passes(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::ACCOUNT, 'account-1', PromotionScopeMode::EXCLUDE)],
            $this->subtotal(),
            'account-2',
            [$this->line('v1')],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
    }

    // --- usage limits (soft, non-locking) -----------------------------------

    public function test_usage_limit_total_reached_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(usageLimitTotal: 5),
            [],
            $this->subtotal(),
            null,
            [$this->line('v1')],
            $this->usage(redemptionsTotal: 5),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('usage_limit_reached', $result->reason());
    }

    public function test_usage_limit_total_not_yet_reached_passes(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(usageLimitTotal: 5),
            [],
            $this->subtotal(),
            null,
            [$this->line('v1')],
            $this->usage(redemptionsTotal: 4),
        );

        $this->assertTrue($result->isValid());
    }

    public function test_usage_limit_per_customer_reached_for_that_account_is_invalid(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(usageLimitPerCustomer: 1),
            [],
            $this->subtotal(),
            'account-1',
            [$this->line('v1')],
            $this->usage(redemptionsForAccount: 1),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('usage_limit_per_customer_reached', $result->reason());
    }

    public function test_usage_limit_per_customer_reached_for_a_different_account_still_passes(): void
    {
        // account-1 has already redeemed once (would be rejected), but
        // this validate() call is for account-2 with zero redemptions.
        $result = $this->validator()->validate(
            $this->promotion(usageLimitPerCustomer: 1),
            [],
            $this->subtotal(),
            'account-2',
            [$this->line('v1')],
            $this->usage(redemptionsForAccount: 0),
        );

        $this->assertTrue($result->isValid());
    }

    /** No reliable per-guest identity to count against — same reasoning new_customers_only already uses for guests. */
    public function test_a_guest_is_never_rejected_by_the_per_customer_limit_even_with_a_nonzero_count(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(usageLimitPerCustomer: 1),
            [],
            $this->subtotal(),
            null,
            [$this->line('v1')],
            $this->usage(redemptionsForAccount: 5),
        );

        $this->assertTrue($result->isValid());
    }

    // --- per-line scope resolution -----------------------------------------

    public function test_zero_scopes_is_a_universal_match_and_all_lines_are_applicable(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [],
            $this->subtotal(),
            null,
            [$this->line('v1'), $this->line('v2')],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(['v1', 'v2'], $result->applicableVariationIds());
    }

    public function test_a_brand_include_scope_makes_only_the_matching_line_applicable(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::BRAND, 'brand-nike', PromotionScopeMode::INCLUDE)],
            $this->subtotal(),
            null,
            [
                $this->line('v1', matchingScopeReferenceIds: ['brand' => ['brand-nike']]),
                $this->line('v2', matchingScopeReferenceIds: ['brand' => ['brand-adidas']]),
            ],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(['v1'], $result->applicableVariationIds());
    }

    public function test_a_brand_include_scope_with_only_a_non_matching_line_is_no_matching_lines(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::BRAND, 'brand-nike', PromotionScopeMode::INCLUDE)],
            $this->subtotal(),
            null,
            [$this->line('v1', matchingScopeReferenceIds: ['brand' => ['brand-adidas']])],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('no_matching_lines', $result->reason());
    }

    public function test_a_category_exclude_scope_removes_an_otherwise_matching_line(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::CATEGORY, 'cat-shoes', PromotionScopeMode::EXCLUDE)],
            $this->subtotal(),
            null,
            [
                $this->line('v1', matchingScopeReferenceIds: ['category' => ['cat-shoes']]),
                $this->line('v2', matchingScopeReferenceIds: ['category' => ['cat-bags']]),
            ],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(['v2'], $result->applicableVariationIds());
    }

    public function test_a_product_scope_matches_against_the_lines_product_id_directly(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::PRODUCT, 'product-1', PromotionScopeMode::INCLUDE)],
            $this->subtotal(),
            null,
            [
                $this->line('v1', productId: 'product-1'),
                $this->line('v2', productId: 'product-2'),
            ],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(['v1'], $result->applicableVariationIds());
    }

    public function test_exclude_sale_items_removes_a_discounted_line_even_when_its_scope_matches(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(excludeSaleItems: true),
            [$this->scope(PromotionScopeType::BRAND, 'brand-nike', PromotionScopeMode::INCLUDE)],
            $this->subtotal(),
            null,
            [$this->line('v1', matchingScopeReferenceIds: ['brand' => ['brand-nike']], isDiscounted: true)],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('no_matching_lines', $result->reason());
    }

    public function test_exclude_sale_items_leaves_a_non_discounted_matching_line_applicable(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(excludeSaleItems: true),
            [$this->scope(PromotionScopeType::BRAND, 'brand-nike', PromotionScopeMode::INCLUDE)],
            $this->subtotal(),
            null,
            [
                $this->line('v1', matchingScopeReferenceIds: ['brand' => ['brand-nike']], isDiscounted: true),
                $this->line('v2', matchingScopeReferenceIds: ['brand' => ['brand-nike']], isDiscounted: false),
            ],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(['v2'], $result->applicableVariationIds());
    }

    public function test_two_lines_where_only_one_matches_is_valid_with_only_that_one_applicable(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::TAG, 'tag-summer', PromotionScopeMode::INCLUDE)],
            $this->subtotal(),
            null,
            [
                $this->line('v1', matchingScopeReferenceIds: ['tag' => ['tag-summer']]),
                $this->line('v2', matchingScopeReferenceIds: ['tag' => ['tag-winter']]),
            ],
            $this->usage(),
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(['v1'], $result->applicableVariationIds());
    }

    public function test_all_lines_excluded_by_scope_is_no_matching_lines(): void
    {
        $result = $this->validator()->validate(
            $this->promotion(),
            [$this->scope(PromotionScopeType::CATEGORY, 'cat-shoes', PromotionScopeMode::EXCLUDE)],
            $this->subtotal(),
            null,
            [
                $this->line('v1', matchingScopeReferenceIds: ['category' => ['cat-shoes']]),
                $this->line('v2', matchingScopeReferenceIds: ['category' => ['cat-shoes']]),
            ],
            $this->usage(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('no_matching_lines', $result->reason());
    }
}
