<?php

namespace Tests\Feature;

use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListScope;
use EasyCo\Pricing\PriceListSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EloquentPriceListScopeRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function scopeRepository(): PriceListScopeRepository
    {
        return app(PriceListScopeRepository::class);
    }

    private function persistedPriceListId(): string
    {
        $list = PriceList::create('Wholesale', PriceListMode::FIXED_ITEMS, priority: 10);
        app(PriceListRepository::class)->save($list);

        return $list->id();
    }

    public function test_attach_assigns_id_and_the_row_appears_in_find_by_price_list_id(): void
    {
        $priceListId = $this->persistedPriceListId();
        $scope = new PriceListScope(null, $priceListId, PriceListScopeType::BRAND, 'guess');

        $this->scopeRepository()->attach($scope);

        $this->assertNotNull($scope->id());

        $found = $this->scopeRepository()->findByPriceListId($priceListId);
        $this->assertCount(1, $found);
        $this->assertSame($scope->id(), $found[0]->id());
        $this->assertSame(PriceListScopeType::BRAND, $found[0]->scopeType());
        $this->assertSame('guess', $found[0]->scopeReferenceId());
    }

    public function test_attach_updates_the_parent_lists_scope_signature(): void
    {
        $priceListId = $this->persistedPriceListId();
        $scope = new PriceListScope(null, $priceListId, PriceListScopeType::BRAND, 'guess');

        $this->scopeRepository()->attach($scope);

        $expected = PriceListSignature::forScopes([$scope])->value();
        $actual = PriceListModel::find($priceListId)->scope_signature;

        $this->assertSame($expected, $actual);
    }

    public function test_attaching_a_second_scope_updates_the_signature_again(): void
    {
        $priceListId = $this->persistedPriceListId();
        $first = new PriceListScope(null, $priceListId, PriceListScopeType::BRAND, 'guess');
        $this->scopeRepository()->attach($first);

        $signatureAfterFirst = PriceListModel::find($priceListId)->scope_signature;

        $second = new PriceListScope(null, $priceListId, PriceListScopeType::ATTRIBUTE_VALUE, 'summer-2026');
        $this->scopeRepository()->attach($second);

        $signatureAfterSecond = PriceListModel::find($priceListId)->scope_signature;

        $this->assertNotSame($signatureAfterFirst, $signatureAfterSecond);

        $expected = PriceListSignature::forScopes([$first, $second])->value();
        $this->assertSame($expected, $signatureAfterSecond);
    }

    public function test_detach_removes_the_row_and_restores_the_universal_signature_when_it_was_the_last_scope(): void
    {
        $priceListId = $this->persistedPriceListId();
        $scope = new PriceListScope(null, $priceListId, PriceListScopeType::BRAND, 'guess');
        $this->scopeRepository()->attach($scope);

        $this->scopeRepository()->detach($scope->id());

        $this->assertSame([], $this->scopeRepository()->findByPriceListId($priceListId));

        $signature = PriceListModel::find($priceListId)->scope_signature;
        $this->assertSame(PriceListSignature::forUniversalScope()->value(), $signature);
    }

    public function test_attach_throws_when_scope_price_list_id_is_still_the_placeholder(): void
    {
        $scope = new PriceListScope(null, '', PriceListScopeType::BRAND, 'guess');

        $this->expectException(InvalidArgumentException::class);
        $this->scopeRepository()->attach($scope);
    }
}
