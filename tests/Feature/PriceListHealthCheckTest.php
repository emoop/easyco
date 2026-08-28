<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListHealthCheck;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceListHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function healthCheck(): PriceListHealthCheck
    {
        return app(PriceListHealthCheck::class);
    }

    private function priceListRepository(): PriceListRepository
    {
        return app(PriceListRepository::class);
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
    ): PriceListItem {
        $item = new PriceListItem(null, $list->id(), $targetType, $targetId, $price, $minQuantity);
        $this->itemRepository()->save($item);

        return $item;
    }

    private function price(string $amount): Price
    {
        return Price::exclusiveOfTax(Money::fromDecimal($amount, 'EUR'), 2000);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function test_all_restricted_prices_at_or_below_regular_produces_no_issues(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem($wholesaleList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));
        // Exactly equal is allowed too.
        $this->addItem($wholesaleList, PriceListItemTargetType::VARIATION, 'variation-2', $this->price('29.99'));
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-2', $this->price('29.99'));

        $issues = $this->healthCheck()->run($this->now());

        $this->assertSame([], $issues);
    }

    public function test_one_wholesale_item_above_regular_produces_exactly_one_issue_with_correct_fields(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem($wholesaleList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $issues = $this->healthCheck()->run($this->now());

        $this->assertCount(1, $issues);
        $issue = $issues[0];
        $this->assertSame($wholesaleList->id(), $issue->priceListId);
        $this->assertSame('Wholesale', $issue->priceListName);
        $this->assertSame(PriceListItemTargetType::VARIATION, $issue->targetType);
        $this->assertSame('variation-1', $issue->targetId);
        $this->assertTrue($issue->itemPrice->net()->equals(Money::fromDecimal('29.99', 'EUR')));
        $this->assertTrue($issue->regularPrice->net()->equals(Money::fromDecimal('19.99', 'EUR')));
    }

    public function test_multiple_lists_and_items_with_issues_all_appear_in_the_result(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-2', $this->price('9.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem($wholesaleList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $staffDiscountList = PriceList::create('Staff Discount', PriceListMode::FIXED_ITEMS, priority: 20);
        $this->priceListRepository()->save($staffDiscountList);
        $this->addItem($staffDiscountList, PriceListItemTargetType::VARIATION, 'variation-2', $this->price('14.99'));

        $issues = $this->healthCheck()->run($this->now());

        $this->assertCount(2, $issues);
        $targetIdsWithLists = array_map(
            fn ($issue) => $issue->priceListName.':'.$issue->targetId,
            $issues
        );
        $this->assertContains('Wholesale:variation-1', $targetIdsWithLists);
        $this->assertContains('Staff Discount:variation-2', $targetIdsWithLists);
    }

    public function test_manual_sale_with_a_bad_price_appears_in_the_result(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $manualSaleList = PriceList::createSystemList('Manual Sale', PriceListMode::FIXED_ITEMS, priority: 1000);
        $this->priceListRepository()->save($manualSaleList);
        $this->addItem($manualSaleList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('24.99'));

        $issues = $this->healthCheck()->run($this->now());

        $this->assertCount(1, $issues);
        $this->assertSame('Manual Sale', $issues[0]->priceListName);
    }

    public function test_percentage_off_regular_list_is_never_checked(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $discountList = PriceList::create('Guess -20%', PriceListMode::PERCENTAGE_OFF_REGULAR, priority: 10, percentageBasisPoints: 2000);
        $this->priceListRepository()->save($discountList);
        // A PriceListItem attached to a PERCENTAGE_OFF_REGULAR list is not
        // meaningful in practice, but the mode filter must exclude it
        // regardless — an absurdly high price proves it really is
        // filtered out, not just happening not to trip.
        $this->addItem($discountList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('999999.99'));

        $issues = $this->healthCheck()->run($this->now());

        $this->assertSame([], $issues);
    }

    public function test_inactive_restricted_list_does_not_participate(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem($wholesaleList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $wholesaleList->deactivate();
        $this->priceListRepository()->save($wholesaleList);

        $issues = $this->healthCheck()->run($this->now());

        $this->assertSame([], $issues);
    }

    public function test_out_of_time_window_restricted_list_does_not_participate(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $expiredList = PriceList::create(
            'Expired Sale',
            PriceListMode::FIXED_ITEMS,
            priority: 10,
            validFrom: new DateTimeImmutable('2020-01-01'),
            validUntil: new DateTimeImmutable('2020-02-01'),
        );
        $this->priceListRepository()->save($expiredList);
        $this->addItem($expiredList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $issues = $this->healthCheck()->run($this->now());

        $this->assertSame([], $issues);
    }

    public function test_variation_item_missing_from_map_with_no_variation_level_regular_is_skipped_not_guessed(): void
    {
        $regularList = $this->seedRegularPricesList();
        // Only a PRODUCT-level regular price exists, but the item's
        // variation id is absent from the map, so the PRODUCT fallback
        // must not be attempted — the item must simply be skipped.
        $this->addItem($regularList, PriceListItemTargetType::PRODUCT, 'product-1', $this->price('19.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem($wholesaleList, PriceListItemTargetType::VARIATION, 'variation-unmapped', $this->price('29.99'));

        $issues = $this->healthCheck()->run($this->now(), variationToProductMap: []);

        $this->assertSame([], $issues);
    }

    public function test_product_targeted_restricted_item_is_checked_without_needing_a_map(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::PRODUCT, 'product-1', $this->price('19.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem($wholesaleList, PriceListItemTargetType::PRODUCT, 'product-1', $this->price('29.99'));

        $issues = $this->healthCheck()->run($this->now(), variationToProductMap: []);

        $this->assertCount(1, $issues);
        $this->assertSame(PriceListItemTargetType::PRODUCT, $issues[0]->targetType);
        $this->assertSame('product-1', $issues[0]->targetId);
    }

    public function test_missing_regular_prices_list_entirely_returns_empty_array_not_throws(): void
    {
        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem($wholesaleList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $issues = $this->healthCheck()->run($this->now());

        $this->assertSame([], $issues);
    }

    public function test_variation_item_in_map_falls_back_to_product_level_regular_price(): void
    {
        $regularList = $this->seedRegularPricesList();
        $this->addItem($regularList, PriceListItemTargetType::PRODUCT, 'product-1', $this->price('19.99'));

        $wholesaleList = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $this->priceListRepository()->save($wholesaleList);
        $this->addItem($wholesaleList, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('29.99'));

        $issues = $this->healthCheck()->run($this->now(), variationToProductMap: ['variation-1' => 'product-1']);

        $this->assertCount(1, $issues);
        $this->assertSame(PriceListItemTargetType::VARIATION, $issues[0]->targetType);
        $this->assertSame('variation-1', $issues[0]->targetId);
        $this->assertTrue($issues[0]->regularPrice->net()->equals(Money::fromDecimal('19.99', 'EUR')));
    }
}
