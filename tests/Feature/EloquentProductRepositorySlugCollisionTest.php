<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests EloquentProductRepository::save()'s own slug UNIQUE-constraint
 * collision retry directly — the authoritative, DB-constraint-driven
 * safety net (as opposed to CatalogSlugGeneratorTest, which covers the
 * best-effort app-layer dedup in the hook listener). Bypasses the hook
 * entirely: both products here are constructed with the exact same slug
 * up front, so the real MySQL UNIQUE(slug) index is what's actually
 * caught and retried.
 */
class EloquentProductRepositorySlugCollisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_second_product_with_a_colliding_slug_retries_with_a_numeric_suffix(): void
    {
        $repository = app(ProductRepository::class);

        $first = Product::createSimple('First Product', 'SKU-A', 'colliding-slug');
        $repository->save($first);

        $second = Product::createSimple('Second Product', 'SKU-B', 'colliding-slug');
        $repository->save($second);

        $this->assertSame('colliding-slug', $first->slug());
        $this->assertSame(
            'colliding-slug-1',
            $second->slug(),
            'the in-memory Product must reflect exactly what was actually persisted after a retry'
        );

        $this->assertDatabaseHas('catalog_products', ['id' => $first->id(), 'slug' => 'colliding-slug']);
        $this->assertDatabaseHas('catalog_products', ['id' => $second->id(), 'slug' => 'colliding-slug-1']);
    }

    public function test_repeated_collisions_keep_incrementing_the_suffix(): void
    {
        $repository = app(ProductRepository::class);

        $first = Product::createSimple('First', 'SKU-A', 'popular-slug');
        $repository->save($first);

        $second = Product::createSimple('Second', 'SKU-B', 'popular-slug');
        $repository->save($second);

        $third = Product::createSimple('Third', 'SKU-C', 'popular-slug');
        $repository->save($third);

        $this->assertSame('popular-slug', $first->slug());
        $this->assertSame('popular-slug-1', $second->slug());
        $this->assertSame('popular-slug-2', $third->slug());
    }

    public function test_exhausting_all_retries_throws_a_clear_exception(): void
    {
        $repository = app(ProductRepository::class);

        // Occupy the base slug and all 3 suffix variants the retry loop
        // will try, so a 5th product has nowhere left to land.
        foreach (['exhausted-slug', 'exhausted-slug-1', 'exhausted-slug-2', 'exhausted-slug-3'] as $i => $slug) {
            $product = Product::createSimple("Occupant {$i}", "SKU-OCC-{$i}", $slug);
            $repository->save($product);
        }

        $newProduct = Product::createSimple('One Too Many', 'SKU-OVERFLOW', 'exhausted-slug');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exhausted-slug/');

        $repository->save($newProduct);
    }
}
