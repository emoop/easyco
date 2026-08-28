<?php

namespace Tests\Feature;

use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Persistence\Eloquent\PriceListItemModel;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentPriceListItemRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): PriceListItemRepository
    {
        return app(PriceListItemRepository::class);
    }

    private function persistedPriceListId(): string
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        app(PriceListRepository::class)->save($list);

        return $list->id();
    }

    public function test_save_then_find_by_price_list_id_round_trips_an_exclusive_of_tax_price_exactly(): void
    {
        $priceListId = $this->persistedPriceListId();
        $price = Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000);

        $item = new PriceListItem(
            id: null,
            priceListId: $priceListId,
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $price,
            minQuantity: 10,
        );

        $this->repository()->save($item);

        $this->assertNotNull($item->id());

        $reloaded = $this->repository()->findByPriceListId($priceListId);
        $this->assertCount(1, $reloaded);

        $reloadedItem = $reloaded[0];
        $this->assertSame($item->id(), $reloadedItem->id());
        $this->assertSame(PriceListItemTargetType::PRODUCT, $reloadedItem->targetType());
        $this->assertSame('product-154215', $reloadedItem->targetId());
        $this->assertSame(10, $reloadedItem->minQuantity());
        $this->assertFalse($reloadedItem->price()->isTaxInclusive());
        $this->assertTrue($reloadedItem->price()->net()->equals($price->net()));
        $this->assertSame(2000, $reloadedItem->price()->taxRateBasisPoints());
    }

    public function test_save_then_find_by_price_list_id_round_trips_an_inclusive_of_tax_price_exactly(): void
    {
        $priceListId = $this->persistedPriceListId();
        $price = Price::inclusiveOfTax(Money::fromDecimal('23.99', 'EUR'), 2000);

        $item = new PriceListItem(
            id: null,
            priceListId: $priceListId,
            targetType: PriceListItemTargetType::VARIATION,
            targetId: 'variation-154215-1',
            price: $price,
        );

        $this->repository()->save($item);

        $reloaded = $this->repository()->findByPriceListId($priceListId);
        $this->assertCount(1, $reloaded);

        $reloadedItem = $reloaded[0];
        $this->assertTrue($reloadedItem->price()->isTaxInclusive());
        $this->assertTrue($reloadedItem->price()->gross()->equals($price->gross()));
        $this->assertSame(2000, $reloadedItem->price()->taxRateBasisPoints());
    }

    public function test_save_as_update_modifies_the_same_row_instead_of_creating_a_new_one(): void
    {
        $priceListId = $this->persistedPriceListId();
        $item = new PriceListItem(
            id: null,
            priceListId: $priceListId,
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000),
        );
        $this->repository()->save($item);
        $originalId = $item->id();

        $item->updatePrice(Price::exclusiveOfTax(Money::fromDecimal('17.99', 'EUR'), 2000));
        $item->updateMinQuantity(5);
        $this->repository()->save($item);

        $this->assertSame($originalId, $item->id());
        $this->assertSame(1, PriceListItemModel::count());

        $reloaded = $this->repository()->findByPriceListId($priceListId);
        $this->assertCount(1, $reloaded);
        $this->assertSame(5, $reloaded[0]->minQuantity());
        $this->assertTrue($reloaded[0]->price()->net()->equals(Money::fromDecimal('17.99', 'EUR')));
    }

    public function test_remove_deletes_the_row(): void
    {
        $priceListId = $this->persistedPriceListId();
        $item = new PriceListItem(
            id: null,
            priceListId: $priceListId,
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000),
        );
        $this->repository()->save($item);

        $this->repository()->remove($item->id());

        $this->assertSame([], $this->repository()->findByPriceListId($priceListId));
    }
}
