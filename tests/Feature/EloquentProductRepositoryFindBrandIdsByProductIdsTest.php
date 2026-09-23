<?php

namespace Tests\Feature;

use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ProductRepository::findBrandIdsByProductIds() — narrow, column-only batched read. */
class EloquentProductRepositoryFindBrandIdsByProductIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_real_brand_id_for_a_product_that_has_one_and_null_for_one_that_does_not(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($brand);

        $withBrand = Product::createSimple('With Brand', 'SKU-1', 'with-brand');
        $withBrand->assignBrand($brand->id());
        app(ProductRepository::class)->save($withBrand);

        $withoutBrand = Product::createSimple('Without Brand', 'SKU-2', 'without-brand');
        app(ProductRepository::class)->save($withoutBrand);

        $result = app(ProductRepository::class)->findBrandIdsByProductIds([$withBrand->id(), $withoutBrand->id()]);

        $this->assertCount(2, $result);
        $this->assertSame($brand->id(), $result[$withBrand->id()]);
        $this->assertNull($result[$withoutBrand->id()]);
    }

    public function test_returns_empty_array_for_an_empty_id_list(): void
    {
        $this->assertSame([], app(ProductRepository::class)->findBrandIdsByProductIds([]));
    }
}
