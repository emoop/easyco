<?php

namespace Tests\Feature;

use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests Product::brandId()/assignBrand() round-tripping through
 * EloquentProductRepository against real MySQL — catalog_products.brand_id
 * is a real FK to catalog_brands (nullOnDelete), so a saved brand_id must
 * reference a real, persisted Brand row.
 */
class CatalogProductBrandRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_product_with_a_brand_round_trips_the_brand_id(): void
    {
        $brandRepository = app(BrandRepository::class);
        $productRepository = app(ProductRepository::class);

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        $brandRepository->save($brand);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->assignBrand($brand->id());
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame($brand->id(), $found->brandId());
    }

    public function test_saving_a_product_with_no_brand_round_trips_a_null_brand_id(): void
    {
        $productRepository = app(ProductRepository::class);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertNull($found->brandId());
    }

    public function test_changing_a_products_brand_and_saving_again_persists_the_new_value(): void
    {
        $brandRepository = app(BrandRepository::class);
        $productRepository = app(ProductRepository::class);

        $nike = new Brand(id: null, name: 'Nike', slug: 'nike');
        $brandRepository->save($nike);

        $adidas = new Brand(id: null, name: 'Adidas', slug: 'adidas');
        $brandRepository->save($adidas);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->assignBrand($nike->id());
        $productRepository->save($product);

        $product->assignBrand($adidas->id());
        $productRepository->save($product);

        $found = $productRepository->findById($product->id());

        $this->assertSame($adidas->id(), $found->brandId());
    }
}
