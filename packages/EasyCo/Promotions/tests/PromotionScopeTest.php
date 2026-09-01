<?php

namespace EasyCo\Promotions\Tests;

use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\PromotionScope;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromotionScopeTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $scope = new PromotionScope(
            id: null,
            promotionId: '',
            scopeType: PromotionScopeType::BRAND,
            scopeReferenceId: 'brand-guess',
            mode: PromotionScopeMode::INCLUDE,
        );

        $this->assertNull($scope->id());
        $this->assertSame('', $scope->promotionId());
        $this->assertSame(PromotionScopeType::BRAND, $scope->scopeType());
        $this->assertSame('brand-guess', $scope->scopeReferenceId());
        $this->assertSame(PromotionScopeMode::INCLUDE, $scope->mode());
    }

    public function test_empty_scope_reference_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PromotionScope(
            id: null,
            promotionId: '',
            scopeType: PromotionScopeType::BRAND,
            scopeReferenceId: '',
            mode: PromotionScopeMode::INCLUDE,
        );
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $scope = new PromotionScope(
            id: null,
            promotionId: '',
            scopeType: PromotionScopeType::BRAND,
            scopeReferenceId: 'brand-guess',
            mode: PromotionScopeMode::INCLUDE,
        );

        $scope->assignId('1');
        $this->assertSame('1', $scope->id());

        $this->expectException(LogicException::class);
        $scope->assignId('2');
    }

    public function test_promotion_id_can_only_be_assigned_once_starting_from_the_placeholder(): void
    {
        $scope = new PromotionScope(
            id: null,
            promotionId: '',
            scopeType: PromotionScopeType::BRAND,
            scopeReferenceId: 'brand-guess',
            mode: PromotionScopeMode::INCLUDE,
        );

        $this->assertSame('', $scope->promotionId());

        $scope->assignPromotionId('7');
        $this->assertSame('7', $scope->promotionId());

        $this->expectException(LogicException::class);
        $scope->assignPromotionId('8');
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $scope = PromotionScope::reconstituteFromStorage(
            id: '3',
            promotionId: '7',
            scopeType: PromotionScopeType::ATTRIBUTE_VALUE,
            scopeReferenceId: 'attr-value-summer-2026',
            mode: PromotionScopeMode::EXCLUDE,
        );

        $this->assertSame('3', $scope->id());
        $this->assertSame('7', $scope->promotionId());
        $this->assertSame(PromotionScopeType::ATTRIBUTE_VALUE, $scope->scopeType());
        $this->assertSame('attr-value-summer-2026', $scope->scopeReferenceId());
        $this->assertSame(PromotionScopeMode::EXCLUDE, $scope->mode());
    }

    #[DataProvider('scopeTypes')]
    public function test_every_scope_type_is_constructible(PromotionScopeType $scopeType): void
    {
        $scope = new PromotionScope(
            id: null,
            promotionId: '',
            scopeType: $scopeType,
            scopeReferenceId: 'some-reference-id',
            mode: PromotionScopeMode::INCLUDE,
        );

        $this->assertSame($scopeType, $scope->scopeType());
    }

    public static function scopeTypes(): array
    {
        return [
            'BRAND' => [PromotionScopeType::BRAND],
            'CATEGORY' => [PromotionScopeType::CATEGORY],
            'TAG' => [PromotionScopeType::TAG],
            'ATTRIBUTE_VALUE' => [PromotionScopeType::ATTRIBUTE_VALUE],
            'PRODUCT' => [PromotionScopeType::PRODUCT],
            'ACCOUNT' => [PromotionScopeType::ACCOUNT],
        ];
    }

    #[DataProvider('scopeModes')]
    public function test_every_scope_mode_is_constructible(PromotionScopeMode $mode): void
    {
        $scope = new PromotionScope(
            id: null,
            promotionId: '',
            scopeType: PromotionScopeType::BRAND,
            scopeReferenceId: 'brand-guess',
            mode: $mode,
        );

        $this->assertSame($mode, $scope->mode());
    }

    public static function scopeModes(): array
    {
        return [
            'INCLUDE' => [PromotionScopeMode::INCLUDE],
            'EXCLUDE' => [PromotionScopeMode::EXCLUDE],
        ];
    }
}
