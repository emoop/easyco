<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests ProductGroup round-tripping through EloquentProductGroupRepository
 * against real MySQL — mirrors CatalogSeasonRepositoryTest's established
 * shape.
 */
class CatalogProductGroupRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_then_find_by_id_round_trips_a_real_product_group(): void
    {
        $repository = app(ProductGroupRepository::class);

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        $repository->save($group);

        $found = $repository->findById($group->id());

        $this->assertSame('shoes', $found->code());
        $this->assertSame('Обувки', $found->name());
    }

    public function test_rename_then_save_persists_the_new_name(): void
    {
        $repository = app(ProductGroupRepository::class);

        $group = new ProductGroup(id: null, code: 'sets', name: 'Комплекти');
        $repository->save($group);

        $group->rename('Комплекти дрехи');
        $repository->save($group);

        $found = $repository->findById($group->id());

        $this->assertSame('Комплекти дрехи', $found->name());
        $this->assertSame('sets', $found->code());
    }
}
