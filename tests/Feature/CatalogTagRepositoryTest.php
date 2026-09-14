<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductTag;
use EasyCo\Catalog\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests EloquentTagRepository against real MySQL — save/findById/all
 * round-trips, and that a duplicate slug propagates as a raw
 * QueryException (no dedicated exception wrapping), same precedent as
 * AttributeDefinition's own repository.
 */
class CatalogTagRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_assigns_an_id_to_a_new_tag(): void
    {
        $repository = app(TagRepository::class);

        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        $repository->save($tag);

        $this->assertNotNull($tag->id());
        $this->assertDatabaseHas('catalog_tags', ['id' => $tag->id(), 'name' => 'Summer', 'slug' => 'summer']);
    }

    public function test_find_by_id_round_trips_a_saved_tag(): void
    {
        $repository = app(TagRepository::class);

        $tag = new Tag(id: null, name: 'Winter', slug: 'winter');
        $repository->save($tag);

        $found = $repository->findById($tag->id());

        $this->assertNotNull($found);
        $this->assertSame($tag->id(), $found->id());
        $this->assertSame('Winter', $found->name());
        $this->assertSame('winter', $found->slug());
    }

    public function test_find_by_id_returns_null_for_an_unknown_id(): void
    {
        $repository = app(TagRepository::class);

        $this->assertNull($repository->findById('999999'));
    }

    public function test_all_returns_every_saved_tag(): void
    {
        $repository = app(TagRepository::class);

        $repository->save(new Tag(id: null, name: 'Summer', slug: 'summer'));
        $repository->save(new Tag(id: null, name: 'Winter', slug: 'winter'));

        $all = $repository->all();
        $names = array_map(fn (Tag $tag) => $tag->name(), $all);
        sort($names);

        $this->assertCount(2, $all);
        $this->assertSame(['Summer', 'Winter'], $names);
    }

    public function test_saving_a_second_tag_with_a_colliding_slug_throws_a_raw_query_exception(): void
    {
        $repository = app(TagRepository::class);

        $repository->save(new Tag(id: null, name: 'Summer', slug: 'colliding-slug'));

        $this->expectException(QueryException::class);

        $repository->save(new Tag(id: null, name: 'Not Summer', slug: 'colliding-slug'));
    }

    public function test_rename_then_save_persists_the_new_name(): void
    {
        $repository = app(TagRepository::class);

        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        $repository->save($tag);

        $tag->rename('Summer Sale');
        $repository->save($tag);

        $reloaded = $repository->findById($tag->id());

        $this->assertSame('Summer Sale', $reloaded->name());
    }

    public function test_change_slug_then_save_persists_the_new_slug(): void
    {
        $repository = app(TagRepository::class);

        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        $repository->save($tag);

        $tag->changeSlug('summer-sale');
        $repository->save($tag);

        $reloaded = $repository->findById($tag->id());

        $this->assertSame('summer-sale', $reloaded->slug());
    }

    public function test_count_products_using_is_zero_when_unused(): void
    {
        $repository = app(TagRepository::class);

        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        $repository->save($tag);

        $this->assertSame(0, $repository->countProductsUsing($tag->id()));
    }

    public function test_count_products_using_counts_every_product_attached_to_this_tag(): void
    {
        $tagRepository = app(TagRepository::class);
        $productRepository = app(ProductRepository::class);
        $productTagRepository = app(ProductTagRepository::class);

        $summer = new Tag(id: null, name: 'Summer', slug: 'summer');
        $tagRepository->save($summer);

        $winter = new Tag(id: null, name: 'Winter', slug: 'winter');
        $tagRepository->save($winter);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            $productRepository->save($product);
            $productTagRepository->save(new ProductTag(id: null, productId: $product->id(), tagId: $summer->id()));
        }

        $unrelated = Product::createSimple('Unrelated', 'SKU-unrelated', 'unrelated');
        $productRepository->save($unrelated);
        $productTagRepository->save(new ProductTag(id: null, productId: $unrelated->id(), tagId: $winter->id()));

        $this->assertSame(3, $tagRepository->countProductsUsing($summer->id()));
    }
}
