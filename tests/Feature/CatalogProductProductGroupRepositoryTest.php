<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests Product::productGroupId()/assignProductGroup() round-tripping
 * through EloquentProductRepository against real MySQL — mirrors
 * CatalogProductSeasonRepositoryTest exactly. catalog_products.product_group_id
 * is a real FK to catalog_product_groups (nullOnDelete), so a saved
 * product_group_id must reference a real, persisted ProductGroup row.
 */
class CatalogProductProductGroupRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_product_with_a_group_round_trips_the_group_id(): void
    {
        $groupRepository = app(ProductGroupRepository::class);
        $productRepository = app(ProductRepository::class);

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        $groupRepository->save($group);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->assignProductGroup($group->id());
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame($group->id(), $found->productGroupId());
    }

    public function test_saving_a_product_with_no_group_round_trips_a_null_group_id(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertNull($found->productGroupId());
    }
}
