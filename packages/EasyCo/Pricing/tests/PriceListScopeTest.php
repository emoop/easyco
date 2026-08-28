<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\PriceListScope;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceListScopeTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $scope = new PriceListScope(
            id: null,
            priceListId: '',
            scopeType: PriceListScopeType::BRAND,
            scopeReferenceId: 'brand-guess',
        );

        $this->assertNull($scope->id());
        $this->assertSame('', $scope->priceListId());
        $this->assertSame(PriceListScopeType::BRAND, $scope->scopeType());
        $this->assertSame('brand-guess', $scope->scopeReferenceId());
    }

    public function test_empty_scope_reference_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceListScope(
            id: null,
            priceListId: '',
            scopeType: PriceListScopeType::BRAND,
            scopeReferenceId: '',
        );
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $scope = new PriceListScope(
            id: null,
            priceListId: '',
            scopeType: PriceListScopeType::BRAND,
            scopeReferenceId: 'brand-guess',
        );

        $scope->assignId('1');
        $this->assertSame('1', $scope->id());

        $this->expectException(LogicException::class);
        $scope->assignId('2');
    }

    public function test_price_list_id_can_only_be_assigned_once_starting_from_the_placeholder(): void
    {
        $scope = new PriceListScope(
            id: null,
            priceListId: '',
            scopeType: PriceListScopeType::BRAND,
            scopeReferenceId: 'brand-guess',
        );

        $this->assertSame('', $scope->priceListId());

        $scope->assignPriceListId('7');
        $this->assertSame('7', $scope->priceListId());

        $this->expectException(LogicException::class);
        $scope->assignPriceListId('8');
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $scope = PriceListScope::reconstituteFromStorage(
            id: '3',
            priceListId: '7',
            scopeType: PriceListScopeType::ATTRIBUTE_VALUE,
            scopeReferenceId: 'attr-value-summer-2026',
        );

        $this->assertSame('3', $scope->id());
        $this->assertSame('7', $scope->priceListId());
        $this->assertSame(PriceListScopeType::ATTRIBUTE_VALUE, $scope->scopeType());
        $this->assertSame('attr-value-summer-2026', $scope->scopeReferenceId());
    }

    #[DataProvider('scopeTypes')]
    public function test_every_scope_type_is_constructible(PriceListScopeType $scopeType): void
    {
        $scope = new PriceListScope(
            id: null,
            priceListId: '',
            scopeType: $scopeType,
            scopeReferenceId: 'some-reference-id',
        );

        $this->assertSame($scopeType, $scope->scopeType());
    }

    public static function scopeTypes(): array
    {
        return [
            'BRAND' => [PriceListScopeType::BRAND],
            'CATEGORY' => [PriceListScopeType::CATEGORY],
            'TAG' => [PriceListScopeType::TAG],
            'ATTRIBUTE_VALUE' => [PriceListScopeType::ATTRIBUTE_VALUE],
            'CUSTOMER_GROUP' => [PriceListScopeType::CUSTOMER_GROUP],
            'CHANNEL' => [PriceListScopeType::CHANNEL],
            'PRODUCT' => [PriceListScopeType::PRODUCT],
        ];
    }
}
