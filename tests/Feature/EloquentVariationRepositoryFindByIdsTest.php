<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** VariationRepository::findByIds() — the set-based sibling of findById(). */
class EloquentVariationRepositoryFindByIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_every_matching_variation_keyed_by_id(): void
    {
        $productA = Product::createSimple('Product A', 'SKU-A', 'product-a');
        app(\EasyCo\Catalog\Contracts\ProductRepository::class)->save($productA);
        $variationA = $productA->variations()[0]->id();

        $productB = Product::createSimple('Product B', 'SKU-B', 'product-b');
        app(\EasyCo\Catalog\Contracts\ProductRepository::class)->save($productB);
        $variationB = $productB->variations()[0]->id();

        $found = app(VariationRepository::class)->findByIds([$variationA, $variationB]);

        $this->assertCount(2, $found);
        $this->assertSame($variationA, $found[$variationA]->id());
        $this->assertSame($variationB, $found[$variationB]->id());
        $this->assertSame($productA->id(), $found[$variationA]->productId());
        $this->assertSame($productB->id(), $found[$variationB]->productId());
    }

    public function test_silently_omits_a_nonexistent_id(): void
    {
        $product = Product::createSimple('Product A', 'SKU-A', 'product-a');
        app(\EasyCo\Catalog\Contracts\ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $found = app(VariationRepository::class)->findByIds([$variationId, '999999']);

        $this->assertCount(1, $found);
        $this->assertArrayHasKey($variationId, $found);
    }

    public function test_returns_empty_array_for_an_empty_id_list(): void
    {
        $this->assertSame([], app(VariationRepository::class)->findByIds([]));
    }
}
