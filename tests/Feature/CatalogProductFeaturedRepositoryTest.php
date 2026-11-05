<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P1: Product::isFeatured() / setFeatured() round-trip through EloquentProductRepository into catalog_products.is_featured. */
class CatalogProductFeaturedRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_product_is_not_featured(): void
    {
        $repo = app(ProductRepository::class);
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $this->assertFalse($product->isFeatured());
        $repo->save($product);

        $this->assertFalse($repo->findById($product->id())->isFeatured());
        $this->assertSame(0, (int) DB::table('catalog_products')->where('id', $product->id())->value('is_featured'));
    }

    public function test_the_flag_saves_loads_and_can_be_switched_off_again(): void
    {
        $repo = app(ProductRepository::class);
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->setFeatured(true);
        $repo->save($product);

        $this->assertSame(1, (int) DB::table('catalog_products')->where('id', $product->id())->value('is_featured'));
        $found = $repo->findById($product->id());
        $this->assertTrue($found->isFeatured());

        $found->setFeatured(false);
        $repo->save($found);

        $this->assertFalse($repo->findById($product->id())->isFeatured());
    }

    public function test_saving_something_else_keeps_the_flag(): void
    {
        $repo = app(ProductRepository::class);
        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->setFeatured(true);
        $repo->save($product);

        $found = $repo->findById($product->id());
        $found->rename('Air Max 2');
        $repo->save($found);

        $this->assertTrue($repo->findById($product->id())->isFeatured());
    }
}
