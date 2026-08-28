<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\FixedItemsPriceLookup;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use PHPUnit\Framework\TestCase;

final class PriceListItemLookupTest extends TestCase
{
    private FakePriceListItemRepository $itemRepository;

    private FixedItemsPriceLookup $lookup;

    protected function setUp(): void
    {
        $this->itemRepository = new FakePriceListItemRepository();
        $this->lookup = new FixedItemsPriceLookup($this->itemRepository);
    }

    private function list(): PriceList
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        $list->assignId('list-1');

        return $list;
    }

    private function price(string $amount): Price
    {
        return Price::exclusiveOfTax(Money::fromDecimal($amount, 'EUR'), 2000);
    }

    private function addItem(
        PriceList $list,
        PriceListItemTargetType $targetType,
        string $targetId,
        Price $price,
        int $minQuantity = 1,
    ): void {
        $item = new PriceListItem(null, $list->id(), $targetType, $targetId, $price, $minQuantity);
        $this->itemRepository->save($item);
    }

    public function test_variation_level_item_found_directly(): void
    {
        $list = $this->list();
        $this->addItem($list, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.99'));

        $price = $this->lookup->forTarget($list, priceableId: 'variation-1', productId: null, quantity: 1);

        $this->assertNotNull($price);
        $this->assertTrue($price->net()->equals(Money::fromDecimal('19.99', 'EUR')));
    }

    public function test_no_variation_item_falls_back_to_product_level_item_when_product_id_given(): void
    {
        $list = $this->list();
        $this->addItem($list, PriceListItemTargetType::PRODUCT, 'product-1', $this->price('24.99'));

        $price = $this->lookup->forTarget($list, priceableId: 'variation-1', productId: 'product-1', quantity: 1);

        $this->assertNotNull($price);
        $this->assertTrue($price->net()->equals(Money::fromDecimal('24.99', 'EUR')));
    }

    public function test_no_variation_or_product_item_returns_null(): void
    {
        $list = $this->list();

        $price = $this->lookup->forTarget($list, priceableId: 'variation-1', productId: 'product-1', quantity: 1);

        $this->assertNull($price);
    }

    /** Mirrors the §4.4 example already used in EloquentPriceResolverTest: 22.00/19.00 at quantity 7/12. */
    public function test_quantity_tiers_pick_the_correct_threshold(): void
    {
        $list = $this->list();
        $this->addItem($list, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('22.00'), minQuantity: 1);
        $this->addItem($list, PriceListItemTargetType::VARIATION, 'variation-1', $this->price('19.00'), minQuantity: 10);

        $priceAtSeven = $this->lookup->forTarget($list, priceableId: 'variation-1', productId: null, quantity: 7);
        $priceAtTwelve = $this->lookup->forTarget($list, priceableId: 'variation-1', productId: null, quantity: 12);

        $this->assertTrue($priceAtSeven->net()->equals(Money::fromDecimal('22.00', 'EUR')));
        $this->assertTrue($priceAtTwelve->net()->equals(Money::fromDecimal('19.00', 'EUR')));
    }

    public function test_null_product_id_does_not_attempt_product_fallback_without_a_variation_item(): void
    {
        $list = $this->list();
        $this->addItem($list, PriceListItemTargetType::PRODUCT, 'product-1', $this->price('24.99'));

        $price = $this->lookup->forTarget($list, priceableId: 'variation-1', productId: null, quantity: 1);

        $this->assertNull($price);
    }
}
