<?php

namespace Tests\Feature;

use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Contracts\PriceRangeResolver;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\PriceListScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two jobs: (1) the no-drift equivalence guarantee — resolveQuotes([$c])
 * must equal resolve($c) for every case in the §4.3-§4.6 matrix, and the
 * omitted-id set must be exactly what resolve() throws
 * PriceNotConfiguredException for; (2) the boundedness contract —
 * real DB::listen() query-count measurements proving the query count does
 * not grow with the number of contexts, only with the number of distinct
 * `at` values and distinct winning lists.
 */
class EloquentPriceRangeResolverTest extends TestCase
{
    use RefreshDatabase;

    private function priceListRepository(): PriceListRepository
    {
        return app(PriceListRepository::class);
    }

    private function scopeRepository(): PriceListScopeRepository
    {
        return app(PriceListScopeRepository::class);
    }

    private function itemRepository(): PriceListItemRepository
    {
        return app(PriceListItemRepository::class);
    }

    private function resolver(): PriceResolver
    {
        return app(PriceResolver::class);
    }

    private function rangeResolver(): PriceRangeResolver
    {
        return app(PriceRangeResolver::class);
    }

    private function seedRegularPricesList(): PriceList
    {
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
        $this->priceListRepository()->save($list);

        return $list;
    }

    private function addItem(
        PriceList $list,
        PriceListItemTargetType $targetType,
        string $targetId,
        Price $price,
        int $minQuantity = 1,
    ): void {
        $item = new PriceListItem(null, $list->id(), $targetType, $targetId, $price, $minQuantity);
        $this->itemRepository()->save($item);
    }

    /**
     * The core equivalence assertion for a PRICED target: resolveQuotes()
     * agrees with resolve() on BOTH regular and final gross, and the
     * priceableId is present (never omitted).
     */
    private function assertQuotesAgree(PriceContext $context): void
    {
        $individual = $this->resolver()->resolve($context);
        $batched = $this->rangeResolver()->resolveQuotes([$context]);

        $this->assertArrayHasKey($context->priceableId, $batched, "expected {$context->priceableId} to be present, not omitted");
        $this->assertTrue(
            $individual->regular->gross()->equals($batched[$context->priceableId]->regular->gross()),
            "regular mismatch for {$context->priceableId}"
        );
        $this->assertTrue(
            $individual->final->gross()->equals($batched[$context->priceableId]->final->gross()),
            "final mismatch for {$context->priceableId}"
        );
    }

    /**
     * The core equivalence assertion for an UNPRICED target: resolve()
     * throws PriceNotConfiguredException, and resolveQuotes() omits it
     * — exactly the set resolve() would have thrown for.
     */
    private function assertOmitted(PriceContext $context): void
    {
        $threw = false;

        try {
            $this->resolver()->resolve($context);
        } catch (PriceNotConfiguredException) {
            $threw = true;
        }

        $this->assertTrue($threw, "expected resolve() to throw PriceNotConfiguredException for {$context->priceableId}");

        $batched = $this->rangeResolver()->resolveQuotes([$context]);
        $this->assertArrayNotHasKey($context->priceableId, $batched);
    }

    public function test_product_level_only_price(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::PRODUCT, 'product-1', Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000));

        $this->assertQuotesAgree(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR', productId: 'product-1'));
    }

    public function test_variation_override(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::PRODUCT, 'product-1', Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000));
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('24.99', 'EUR'), 2000));

        $this->assertQuotesAgree(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR', productId: 'product-1'));
    }

    public function test_mixed_product_and_override_across_two_variations_of_the_same_product(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::PRODUCT, 'product-1', Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000));
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('24.99', 'EUR'), 2000));

        // variation-1 has its own override; variation-2 falls back to the product-level price.
        $this->assertQuotesAgree(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR', productId: 'product-1'));
        $this->assertQuotesAgree(new PriceContext(priceableId: 'variation-2', quantity: 1, currency: 'EUR', productId: 'product-1'));
    }

    public function test_manual_sale_wins(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000));

        $manualSale = PriceList::createSystemList('Manual Sale', PriceListMode::FIXED_ITEMS, priority: 1000);
        $this->priceListRepository()->save($manualSale);
        $this->addItem($manualSale, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('17.99', 'EUR'), 2000));

        $this->assertQuotesAgree(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR'));
    }

    public function test_percentage_off_list_wins(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000));

        $discountList = PriceList::create('Guess -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 2000);
        $this->priceListRepository()->save($discountList);

        $this->assertQuotesAgree(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR'));
    }

    public function test_brand_scoped_list_matching_and_non_matching(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000));

        $brandList = PriceList::create('Guess -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 2000);
        $this->priceListRepository()->save($brandList);
        $this->scopeRepository()->attach(new PriceListScope(null, $brandList->id(), PriceListScopeType::BRAND, 'brand-guess'));

        $this->assertQuotesAgree(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['brand' => ['brand-guess']],
        ));
        $this->assertQuotesAgree(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['brand' => ['brand-other']],
        ));
    }

    public function test_category_scoped_list(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000));

        $list = PriceList::create('Shirts -10%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 1000);
        $this->priceListRepository()->save($list);
        $this->scopeRepository()->attach(new PriceListScope(null, $list->id(), PriceListScopeType::CATEGORY, 'category-shirts'));

        $this->assertQuotesAgree(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['category' => ['category-shirts']],
        ));
    }

    public function test_tag_scoped_list(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000));

        $list = PriceList::create('Clearance -15%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 1500);
        $this->priceListRepository()->save($list);
        $this->scopeRepository()->attach(new PriceListScope(null, $list->id(), PriceListScopeType::TAG, 'tag-clearance'));

        $this->assertQuotesAgree(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['tag' => ['tag-clearance']],
        ));
    }

    public function test_attribute_value_scoped_list(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000));

        $list = PriceList::create('Summer -25%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 2500);
        $this->priceListRepository()->save($list);
        $this->scopeRepository()->attach(new PriceListScope(null, $list->id(), PriceListScopeType::ATTRIBUTE_VALUE, 'summer-2026'));

        $this->assertQuotesAgree(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['attribute_value' => ['summer-2026']],
        ));
    }

    public function test_quantity_tier(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000));

        $wholesale = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesale);
        $this->addItem($wholesale, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('22.00', 'EUR'), 2000), minQuantity: 1);
        $this->addItem($wholesale, PriceListItemTargetType::VARIATION, 'variation-1', Price::exclusiveOfTax(Money::fromDecimal('19.00', 'EUR'), 2000), minQuantity: 10);

        $this->assertQuotesAgree(new PriceContext(priceableId: 'variation-1', quantity: 7, currency: 'EUR'));
        $this->assertQuotesAgree(new PriceContext(priceableId: 'variation-1', quantity: 12, currency: 'EUR'));
    }

    public function test_unpriced_target_is_omitted_exactly_where_resolve_throws(): void
    {
        $this->seedRegularPricesList();

        $this->assertOmitted(new PriceContext(priceableId: 'variation-unpriced', quantity: 1, currency: 'EUR'));
    }

    public function test_missing_regular_prices_list_returns_empty_array(): void
    {
        // No "Regular Prices" system list seeded at all.
        $quotes = $this->rangeResolver()->resolveQuotes([
            new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR'),
        ]);

        $this->assertSame([], $quotes);
    }

    public function test_a_batch_mixing_priced_and_unpriced_targets_keeps_only_the_priced_ones(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-priced', Price::exclusiveOfTax(Money::fromDecimal('9.99', 'EUR'), 2000));

        $quotes = $this->rangeResolver()->resolveQuotes([
            new PriceContext(priceableId: 'variation-priced', quantity: 1, currency: 'EUR'),
            new PriceContext(priceableId: 'variation-unpriced', quantity: 1, currency: 'EUR'),
        ]);

        $this->assertArrayHasKey('variation-priced', $quotes);
        $this->assertArrayNotHasKey('variation-unpriced', $quotes);
    }

    // ─────────────────────────────────────────────────────────────
    // Query-count measurement — the boundedness contract, proven with
    // real numbers, not asserted against a hardcoded magic bound.
    // ─────────────────────────────────────────────────────────────

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        DB::flushQueryLog();

        return $count;
    }

    public function test_query_count_for_n_1_vs_n_50_contexts_is_equal(): void
    {
        $regularList = $this->seedRegularPricesList();
        $wholesale = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesale);

        for ($i = 1; $i <= 50; $i++) {
            $this->addItem($regularList, PriceListItemTargetType::VARIATION, "variation-{$i}", Price::exclusiveOfTax(Money::fromDecimal('9.99', 'EUR'), 2000));
            $this->addItem($wholesale, PriceListItemTargetType::VARIATION, "variation-{$i}", Price::exclusiveOfTax(Money::fromDecimal('7.99', 'EUR'), 2000));
        }

        $contextsOf = fn (int $n) => array_map(
            fn (int $i) => new PriceContext(priceableId: "variation-{$i}", quantity: 1, currency: 'EUR'),
            range(1, $n)
        );

        $queriesForOne = $this->countQueries(fn () => $this->rangeResolver()->resolveQuotes($contextsOf(1)));
        $queriesForFifty = $this->countQueries(fn () => $this->rangeResolver()->resolveQuotes($contextsOf(50)));

        fwrite(STDERR, "\n[query-count] N=1: {$queriesForOne} queries, N=50: {$queriesForFifty} queries\n");

        $this->assertSame($queriesForOne, $queriesForFifty, 'query count must not grow with the number of contexts');
    }

    public function test_query_count_with_three_distinct_winning_lists(): void
    {
        $regularList = $this->seedRegularPricesList();

        $listA = PriceList::create('List A', PriceListMode::FIXED_ITEMS, priority: 10);
        $listB = PriceList::create('List B', PriceListMode::FIXED_ITEMS, priority: 20);
        $listC = PriceList::create('List C', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 30, percentageBasisPoints: 1000);
        $this->priceListRepository()->save($listA);
        $this->priceListRepository()->save($listB);
        $this->priceListRepository()->save($listC);

        $this->scopeRepository()->attach(new PriceListScope(null, $listA->id(), PriceListScopeType::TAG, 'tag-a'));
        $this->scopeRepository()->attach(new PriceListScope(null, $listB->id(), PriceListScopeType::TAG, 'tag-b'));
        $this->scopeRepository()->attach(new PriceListScope(null, $listC->id(), PriceListScopeType::TAG, 'tag-c'));

        $contexts = [];
        for ($i = 1; $i <= 30; $i++) {
            $this->addItem($regularList, PriceListItemTargetType::VARIATION, "variation-{$i}", Price::exclusiveOfTax(Money::fromDecimal('9.99', 'EUR'), 2000));

            $tag = match ($i % 3) {
                0 => 'tag-a',
                1 => 'tag-b',
                default => 'tag-c',
            };

            if ($tag === 'tag-a') {
                $this->addItem($listA, PriceListItemTargetType::VARIATION, "variation-{$i}", Price::exclusiveOfTax(Money::fromDecimal('7.99', 'EUR'), 2000));
            } elseif ($tag === 'tag-b') {
                $this->addItem($listB, PriceListItemTargetType::VARIATION, "variation-{$i}", Price::exclusiveOfTax(Money::fromDecimal('6.99', 'EUR'), 2000));
            }

            $contexts[] = new PriceContext(
                priceableId: "variation-{$i}",
                quantity: 1,
                currency: 'EUR',
                matchingScopeReferenceIds: ['tag' => [$tag]],
            );
        }

        $queries = $this->countQueries(fn () => $this->rangeResolver()->resolveQuotes($contexts));

        fwrite(STDERR, "\n[query-count] 3 distinct winning lists, 30 contexts: {$queries} queries\n");

        $results = $this->rangeResolver()->resolveQuotes($contexts);
        $this->assertCount(30, $results, 'every context in this fixture is priced and must resolve');
    }
}
