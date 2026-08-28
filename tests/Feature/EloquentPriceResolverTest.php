<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\FixedItemsPriceLookup;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Persistence\Eloquent\EloquentPriceResolver;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\PriceListScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EloquentPriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): EloquentPriceResolver
    {
        $itemRepository = app(PriceListItemRepository::class);

        return new EloquentPriceResolver(
            app(PriceListRepository::class),
            app(PriceListScopeRepository::class),
            new FixedItemsPriceLookup($itemRepository),
        );
    }

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

    public function test_only_regular_prices_exists_resolves_regular_equals_final(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $quote = $this->resolver()->resolve(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR'));

        $this->assertTrue($quote->regular->net()->equals(Money::fromDecimal('29.99', 'EUR')));
        $this->assertTrue($quote->final->net()->equals($quote->regular->net()));
        $this->assertFalse($quote->isDiscounted());
    }

    public function test_percentage_off_regular_winning_list_applies_the_discount(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $discountList = PriceList::create('Guess -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 2000);
        $this->priceListRepository()->save($discountList);

        $quote = $this->resolver()->resolve(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR'));

        // 29.99 * 0.8 = 23.992 -> half-up rounds to 23.99 (2399 minor units).
        $this->assertTrue($quote->final->net()->equals(Money::fromMinorUnits(2399, 'EUR')));
        $this->assertTrue($quote->isDiscounted());
    }

    public function test_fixed_items_winning_list_variation_level_item_found_directly(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem(
            $wholesaleList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000)
        );

        $quote = $this->resolver()->resolve(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR'));

        $this->assertTrue($quote->final->net()->equals(Money::fromDecimal('19.99', 'EUR')));
    }

    public function test_fixed_items_winning_list_falls_back_to_product_level_item(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $fixedList = PriceList::create('Fixed Price', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($fixedList);
        $this->addItem(
            $fixedList,
            PriceListItemTargetType::PRODUCT,
            'product-1',
            Price::exclusiveOfTax(Money::fromDecimal('24.99', 'EUR'), 2000)
        );

        $quote = $this->resolver()->resolve(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            productId: 'product-1',
        ));

        $this->assertTrue($quote->final->net()->equals(Money::fromDecimal('24.99', 'EUR')));
    }

    public function test_fixed_items_winning_list_with_no_matching_item_falls_back_to_regular(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $fixedList = PriceList::create('Fixed Price', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($fixedList);
        $this->addItem(
            $fixedList,
            PriceListItemTargetType::VARIATION,
            'variation-999',
            Price::exclusiveOfTax(Money::fromDecimal('1.00', 'EUR'), 2000)
        );

        $quote = $this->resolver()->resolve(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR'));

        $this->assertTrue($quote->final->net()->equals($quote->regular->net()));
        $this->assertFalse($quote->isDiscounted());
    }

    public function test_quantity_tiers_resolve_the_correct_threshold(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem(
            $wholesaleList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('22.00', 'EUR'), 2000),
            minQuantity: 1,
        );
        $this->addItem(
            $wholesaleList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('19.00', 'EUR'), 2000),
            minQuantity: 10,
        );

        $quoteSeven = $this->resolver()->resolve(new PriceContext(priceableId: 'variation-1', quantity: 7, currency: 'EUR'));
        $quoteTwelve = $this->resolver()->resolve(new PriceContext(priceableId: 'variation-1', quantity: 12, currency: 'EUR'));

        $this->assertTrue($quoteSeven->final->net()->equals(Money::fromDecimal('22.00', 'EUR')));
        $this->assertTrue($quoteTwelve->final->net()->equals(Money::fromDecimal('19.00', 'EUR')));
    }

    public function test_brand_scope_matches_only_when_context_includes_the_brand_id(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $brandList = PriceList::create('Guess -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 2000);
        $this->priceListRepository()->save($brandList);
        $this->scopeRepository()->attach(new PriceListScope(null, $brandList->id(), PriceListScopeType::BRAND, 'brand-guess'));

        $matchingQuote = $this->resolver()->resolve(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['brand' => ['brand-guess']],
        ));
        $nonMatchingQuote = $this->resolver()->resolve(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['brand' => ['brand-other']],
        ));

        $this->assertTrue($matchingQuote->isDiscounted());
        $this->assertFalse($nonMatchingQuote->isDiscounted());
    }

    public function test_and_logic_requires_all_scope_conditions_to_match(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $list = PriceList::create('Guess Summer -30%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 3000);
        $this->priceListRepository()->save($list);
        $this->scopeRepository()->attach(new PriceListScope(null, $list->id(), PriceListScopeType::BRAND, 'brand-guess'));
        $this->scopeRepository()->attach(new PriceListScope(null, $list->id(), PriceListScopeType::ATTRIBUTE_VALUE, 'summer-2026'));

        $bothMatch = new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['brand' => ['brand-guess'], 'attribute_value' => ['summer-2026']],
        );
        $onlyBrandMatches = new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['brand' => ['brand-guess'], 'attribute_value' => ['winter-2026']],
        );

        $this->assertTrue($this->resolver()->resolve($bothMatch)->isDiscounted());
        $this->assertFalse($this->resolver()->resolve($onlyBrandMatches)->isDiscounted());
    }

    public function test_higher_priority_wins_over_lower(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $lowPriority = PriceList::create('Low -10%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 5, percentageBasisPoints: 1000);
        $this->priceListRepository()->save($lowPriority);

        $highPriority = PriceList::create('High -50%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 20, percentageBasisPoints: 5000);
        $this->priceListRepository()->save($highPriority);

        $quote = $this->resolver()->resolve(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR'));

        // High priority wins: 29.99 * 0.5 = 14.995 -> half-up rounds to 15.00.
        $this->assertTrue($quote->final->net()->equals(Money::fromMinorUnits(1500, 'EUR')));
    }

    public function test_priority_tie_is_broken_by_the_more_recently_created_list(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        // Different (non-identical) scope sets on purpose — two universal
        // (zero-scope) lists at the same priority would collide on
        // UNIQUE(priority, scope_signature), §4.7. This test is about the
        // priority-tie/id-DESC tie-break rule, not §4.7's exact-duplicate
        // guard, so each list gets its own distinct single-brand scope and
        // the context is made to match both.
        $older = PriceList::create('Older -10%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 1000);
        $this->priceListRepository()->save($older);
        $this->scopeRepository()->attach(new PriceListScope(null, $older->id(), PriceListScopeType::BRAND, 'brand-a'));

        $newer = PriceList::create('Newer -25%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 2500);
        $this->priceListRepository()->save($newer);
        $this->scopeRepository()->attach(new PriceListScope(null, $newer->id(), PriceListScopeType::BRAND, 'brand-b'));

        $quote = $this->resolver()->resolve(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            matchingScopeReferenceIds: ['brand' => ['brand-a', 'brand-b']],
        ));

        // Newer (higher id) wins: 29.99 * 0.75 = 22.4925 -> half-up rounds to 22.49.
        $this->assertTrue($quote->final->net()->equals(Money::fromMinorUnits(2249, 'EUR')));
    }

    public function test_a_list_outside_its_time_window_does_not_win_even_if_scope_and_priority_match(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem(
            $regularList,
            PriceListItemTargetType::VARIATION,
            'variation-1',
            Price::exclusiveOfTax(Money::fromDecimal('29.99', 'EUR'), 2000)
        );

        $expiredList = PriceList::create(
            'Expired -50%',
            PriceListMode::PERCENTAGE_OFF_REGULAR,
            priority: 100,
            validFrom: new DateTimeImmutable('2020-01-01'),
            validUntil: new DateTimeImmutable('2020-02-01'),
            percentageBasisPoints: 5000,
        );
        $this->priceListRepository()->save($expiredList);

        $quote = $this->resolver()->resolve(new PriceContext(
            priceableId: 'variation-1',
            quantity: 1,
            currency: 'EUR',
            at: new DateTimeImmutable('2026-01-01'),
        ));

        $this->assertFalse($quote->isDiscounted());
    }

    public function test_missing_regular_prices_list_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->resolver()->resolve(new PriceContext(priceableId: 'variation-1', quantity: 1, currency: 'EUR'));
    }

    public function test_regular_prices_list_with_no_item_for_this_priceable_id_throws(): void
    {
        $this->seedRegularPricesList();

        $this->expectException(RuntimeException::class);

        $this->resolver()->resolve(new PriceContext(priceableId: 'variation-unknown', quantity: 1, currency: 'EUR'));
    }
}
