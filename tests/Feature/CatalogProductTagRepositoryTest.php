<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Exceptions\TagAlreadyAssignedException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductTag;
use EasyCo\Catalog\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests EloquentProductTagRepository against real MySQL —
 * save/findByProductId/remove round-trips, and that assigning the same
 * Tag to the same Product twice throws TagAlreadyAssignedException via
 * the real UNIQUE(product_id, tag_id) constraint
 * (catalog_product_tags_product_id_tag_id_unique).
 */
class CatalogProductTagRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(string $slug): Product
    {
        $productRepository = app(ProductRepository::class);
        $product = Product::createSimple('Air Max', "SKU-{$slug}", $slug);
        $productRepository->save($product);

        return $product;
    }

    private function createTag(string $slug): Tag
    {
        $tagRepository = app(TagRepository::class);
        $tag = new Tag(id: null, name: 'Summer', slug: $slug);
        $tagRepository->save($tag);

        return $tag;
    }

    public function test_save_assigns_an_id_and_persists_the_assignment(): void
    {
        $repository = app(ProductTagRepository::class);
        $product = $this->createProduct('air-max-1');
        $tag = $this->createTag('summer-1');

        $productTag = new ProductTag(id: null, productId: $product->id(), tagId: $tag->id());
        $repository->save($productTag);

        $this->assertNotNull($productTag->id());
        $this->assertDatabaseHas('catalog_product_tags', [
            'id' => $productTag->id(),
            'product_id' => $product->id(),
            'tag_id' => $tag->id(),
        ]);
    }

    public function test_find_by_product_id_returns_every_assignment_for_that_product(): void
    {
        $repository = app(ProductTagRepository::class);
        $product = $this->createProduct('air-max-2');
        $summer = $this->createTag('summer-2');
        $sale = $this->createTag('sale-2');

        $repository->save(new ProductTag(id: null, productId: $product->id(), tagId: $summer->id()));
        $repository->save(new ProductTag(id: null, productId: $product->id(), tagId: $sale->id()));

        $found = $repository->findByProductId($product->id());

        $this->assertCount(2, $found);
        $tagIds = array_map(fn (ProductTag $pt) => $pt->tagId(), $found);
        sort($tagIds);
        $expected = [$summer->id(), $sale->id()];
        sort($expected);
        $this->assertSame($expected, $tagIds);
    }

    public function test_find_by_product_id_returns_empty_array_for_a_product_with_no_tags(): void
    {
        $repository = app(ProductTagRepository::class);
        $product = $this->createProduct('air-max-3');

        $this->assertSame([], $repository->findByProductId($product->id()));
    }

    public function test_remove_deletes_the_assignment(): void
    {
        $repository = app(ProductTagRepository::class);
        $product = $this->createProduct('air-max-4');
        $tag = $this->createTag('summer-4');

        $productTag = new ProductTag(id: null, productId: $product->id(), tagId: $tag->id());
        $repository->save($productTag);

        $repository->remove($productTag->id());

        $this->assertDatabaseMissing('catalog_product_tags', ['id' => $productTag->id()]);
        $this->assertSame([], $repository->findByProductId($product->id()));
    }

    public function test_assigning_the_same_tag_twice_throws_tag_already_assigned_exception(): void
    {
        $repository = app(ProductTagRepository::class);
        $product = $this->createProduct('air-max-5');
        $tag = $this->createTag('summer-5');

        $repository->save(new ProductTag(id: null, productId: $product->id(), tagId: $tag->id()));

        $this->expectException(TagAlreadyAssignedException::class);

        $repository->save(new ProductTag(id: null, productId: $product->id(), tagId: $tag->id()));
    }
}
