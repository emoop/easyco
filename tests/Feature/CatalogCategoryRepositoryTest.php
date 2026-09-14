<?php

namespace Tests\Feature;

use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests EloquentCategoryRepository against real MySQL — save/findById/all
 * round-trips (including parentId), and that a duplicate slug propagates
 * as a raw QueryException (no dedicated exception wrapping), same
 * precedent as AttributeDefinition's own repository.
 */
class CatalogCategoryRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_assigns_an_id_to_a_new_category(): void
    {
        $repository = app(CategoryRepository::class);

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($category);

        $this->assertNotNull($category->id());
        $this->assertDatabaseHas('catalog_categories', ['id' => $category->id(), 'name' => 'Shoes', 'slug' => 'shoes']);
    }

    public function test_find_by_id_round_trips_a_saved_category_with_a_parent(): void
    {
        $repository = app(CategoryRepository::class);

        $parent = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($parent);

        $child = new Category(id: null, parentId: $parent->id(), name: 'Running Shoes', slug: 'running-shoes');
        $repository->save($child);

        $found = $repository->findById($child->id());

        $this->assertNotNull($found);
        $this->assertSame($child->id(), $found->id());
        $this->assertSame($parent->id(), $found->parentId());
        $this->assertSame('Running Shoes', $found->name());
        $this->assertSame('running-shoes', $found->slug());
    }

    public function test_find_by_id_round_trips_a_saved_category_with_no_parent(): void
    {
        $repository = app(CategoryRepository::class);

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($category);

        $found = $repository->findById($category->id());

        $this->assertNull($found->parentId());
    }

    public function test_find_by_id_returns_null_for_an_unknown_id(): void
    {
        $repository = app(CategoryRepository::class);

        $this->assertNull($repository->findById('999999'));
    }

    public function test_all_returns_every_saved_category(): void
    {
        $repository = app(CategoryRepository::class);

        $repository->save(new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes'));
        $repository->save(new Category(id: null, parentId: null, name: 'Bags', slug: 'bags'));

        $all = $repository->all();
        $names = array_map(fn (Category $category) => $category->name(), $all);
        sort($names);

        $this->assertCount(2, $all);
        $this->assertSame(['Bags', 'Shoes'], $names);
    }

    public function test_saving_a_second_category_with_a_colliding_slug_throws_a_raw_query_exception(): void
    {
        $repository = app(CategoryRepository::class);

        $repository->save(new Category(id: null, parentId: null, name: 'Shoes', slug: 'colliding-slug'));

        $this->expectException(QueryException::class);

        $repository->save(new Category(id: null, parentId: null, name: 'Not Shoes', slug: 'colliding-slug'));
    }

    public function test_rename_then_save_persists_the_new_name(): void
    {
        $repository = app(CategoryRepository::class);

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($category);

        $category->rename('Footwear');
        $repository->save($category);

        $reloaded = $repository->findById($category->id());

        $this->assertSame('Footwear', $reloaded->name());
    }

    public function test_change_slug_then_save_persists_the_new_slug(): void
    {
        $repository = app(CategoryRepository::class);

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($category);

        $category->changeSlug('footwear');
        $repository->save($category);

        $reloaded = $repository->findById($category->id());

        $this->assertSame('footwear', $reloaded->slug());
    }

    public function test_change_parent_then_save_persists_the_new_parent(): void
    {
        $repository = app(CategoryRepository::class);

        $originalParent = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($originalParent);

        $newParent = new Category(id: null, parentId: null, name: 'Bags', slug: 'bags');
        $repository->save($newParent);

        $child = new Category(id: null, parentId: $originalParent->id(), name: 'Running Shoes', slug: 'running-shoes');
        $repository->save($child);

        $child->changeParent($newParent->id());
        $repository->save($child);

        $reloaded = $repository->findById($child->id());

        $this->assertSame($newParent->id(), $reloaded->parentId());
    }

    public function test_change_parent_to_null_then_save_persists_null(): void
    {
        $repository = app(CategoryRepository::class);

        $parent = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($parent);

        $child = new Category(id: null, parentId: $parent->id(), name: 'Running Shoes', slug: 'running-shoes');
        $repository->save($child);

        $child->changeParent(null);
        $repository->save($child);

        $reloaded = $repository->findById($child->id());

        $this->assertNull($reloaded->parentId());
    }

    public function test_count_products_using_is_zero_when_unused(): void
    {
        $repository = app(CategoryRepository::class);

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($category);

        $this->assertSame(0, $repository->countProductsUsing($category->id()));
    }

    public function test_count_products_using_counts_every_product_attached_to_this_category(): void
    {
        $categoryRepository = app(CategoryRepository::class);
        $productRepository = app(ProductRepository::class);
        $productCategoryRepository = app(ProductCategoryRepository::class);

        $shoes = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $categoryRepository->save($shoes);

        $bags = new Category(id: null, parentId: null, name: 'Bags', slug: 'bags');
        $categoryRepository->save($bags);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            $productRepository->save($product);
            $productCategoryRepository->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $shoes->id()));
        }

        $unrelated = Product::createSimple('Unrelated', 'SKU-unrelated', 'unrelated');
        $productRepository->save($unrelated);
        $productCategoryRepository->save(new ProductCategory(id: null, productId: $unrelated->id(), categoryId: $bags->id()));

        $this->assertSame(3, $categoryRepository->countProductsUsing($shoes->id()));
    }
}
