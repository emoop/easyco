<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceListItem;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceListItemTest extends TestCase
{
    private function price(string $amount = '19.99'): Price
    {
        return Price::exclusiveOfTax(Money::fromDecimal($amount, 'EUR'), 2000);
    }

    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $price = $this->price();

        $item = new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $price,
            minQuantity: 10,
        );

        $this->assertNull($item->id());
        $this->assertSame('', $item->priceListId());
        $this->assertSame(PriceListItemTargetType::PRODUCT, $item->targetType());
        $this->assertSame('product-154215', $item->targetId());
        $this->assertSame($price, $item->price());
        $this->assertSame(10, $item->minQuantity());
    }

    public function test_min_quantity_defaults_to_one_when_not_passed(): void
    {
        $item = new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $this->price(),
        );

        $this->assertSame(1, $item->minQuantity());
    }

    public function test_empty_target_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: '',
            price: $this->price(),
        );
    }

    public function test_min_quantity_zero_throws_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $this->price(),
            minQuantity: 0,
        );
    }

    public function test_min_quantity_negative_throws_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $this->price(),
            minQuantity: -1,
        );
    }

    public function test_min_quantity_zero_throws_on_update(): void
    {
        $item = new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $this->price(),
        );

        $this->expectException(InvalidArgumentException::class);
        $item->updateMinQuantity(0);
    }

    public function test_min_quantity_negative_throws_on_update(): void
    {
        $item = new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $this->price(),
        );

        $this->expectException(InvalidArgumentException::class);
        $item->updateMinQuantity(-5);
    }

    public function test_update_min_quantity_to_a_valid_value_succeeds(): void
    {
        $item = new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $this->price(),
        );

        $item->updateMinQuantity(10);

        $this->assertSame(10, $item->minQuantity());
    }

    public function test_update_price_succeeds_and_getter_reflects_the_new_price(): void
    {
        $firstPrice = $this->price('19.99');
        $secondPrice = $this->price('17.99');

        $item = new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $firstPrice,
        );

        $item->updatePrice($secondPrice);

        $this->assertSame($secondPrice, $item->price());
        $this->assertNotSame($firstPrice, $item->price());
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $item = new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $this->price(),
        );

        $item->assignId('1');
        $this->assertSame('1', $item->id());

        $this->expectException(LogicException::class);
        $item->assignId('2');
    }

    public function test_price_list_id_can_only_be_assigned_once_starting_from_the_placeholder(): void
    {
        $item = new PriceListItem(
            id: null,
            priceListId: '',
            targetType: PriceListItemTargetType::PRODUCT,
            targetId: 'product-154215',
            price: $this->price(),
        );

        $this->assertSame('', $item->priceListId());

        $item->assignPriceListId('7');
        $this->assertSame('7', $item->priceListId());

        $this->expectException(LogicException::class);
        $item->assignPriceListId('8');
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $price = $this->price();

        $item = PriceListItem::reconstituteFromStorage(
            id: '3',
            priceListId: '7',
            targetType: PriceListItemTargetType::VARIATION,
            targetId: 'variation-154215-1',
            price: $price,
            minQuantity: 5,
        );

        $this->assertSame('3', $item->id());
        $this->assertSame('7', $item->priceListId());
        $this->assertSame(PriceListItemTargetType::VARIATION, $item->targetType());
        $this->assertSame('variation-154215-1', $item->targetId());
        $this->assertSame($price, $item->price());
        $this->assertSame(5, $item->minQuantity());
    }

    #[DataProvider('targetTypes')]
    public function test_every_target_type_is_constructible(PriceListItemTargetType $targetType): void
    {
        $item = new PriceListItem(
            id: null,
            priceListId: '',
            targetType: $targetType,
            targetId: 'some-target-id',
            price: $this->price(),
        );

        $this->assertSame($targetType, $item->targetType());
    }

    public static function targetTypes(): array
    {
        return [
            'PRODUCT' => [PriceListItemTargetType::PRODUCT],
            'VARIATION' => [PriceListItemTargetType::VARIATION],
        ];
    }
}
