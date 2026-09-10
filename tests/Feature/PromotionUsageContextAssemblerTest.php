<?php

namespace Tests\Feature;

use App\Services\PromotionUsageContextAssembler;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The guard's own regression test, living next to the guard — see
 * PromotionUsageContextAssembler's own class docblock. Previously this
 * coverage only existed at the CartController HTTP layer
 * (CartControllerTest's own query-count tests, still passing unedited
 * after this extraction — the primary proof this refactor changed no
 * behavior).
 */
class PromotionUsageContextAssemblerTest extends TestCase
{
    use RefreshDatabase;

    private function createPromotion(string $code, ?int $usageLimitTotal = null): Promotion
    {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 1000,
            usageLimitTotal: $usageLimitTotal,
        );
        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    public function test_a_plain_promotion_produces_a_false_zero_zero_context_and_queries_neither_orders_nor_promotion_redemptions(): void
    {
        $promotion = $this->createPromotion('PLAIN20');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $usage = app(PromotionUsageContextAssembler::class)->assemble($promotion, accountId: 'account-1');

        $this->assertFalse($usage->customerHasPreviousOrders());
        $this->assertSame(0, $usage->redemptionsTotal());
        $this->assertSame(0, $usage->redemptionsForAccount());

        $touchedOrders = array_filter($queries, fn (string $sql) => str_contains($sql, '`orders`'));
        $touchedRedemptions = array_filter($queries, fn (string $sql) => str_contains($sql, '`promotion_redemptions`'));

        $this->assertSame([], array_values($touchedOrders), 'Expected no query against `orders`.');
        $this->assertSame([], array_values($touchedRedemptions), 'Expected no query against `promotion_redemptions`.');
    }

    /** Proves the guard above doesn't over-suppress: a real usage-limited Promotion still triggers the count query it needs. */
    public function test_a_promotion_with_usage_limit_total_set_queries_promotion_redemptions(): void
    {
        $promotion = $this->createPromotion('LIMITED20', usageLimitTotal: 100);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(PromotionUsageContextAssembler::class)->assemble($promotion, accountId: 'account-1');

        $touchedRedemptions = array_filter($queries, fn (string $sql) => str_contains($sql, '`promotion_redemptions`'));

        $this->assertNotSame([], array_values($touchedRedemptions), 'Expected a query against `promotion_redemptions`.');
    }
}
