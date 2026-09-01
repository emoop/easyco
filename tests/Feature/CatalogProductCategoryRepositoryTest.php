<?php

namespace Tests\Feature;

use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Exceptions\CategoryAlreadyAssignedException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests EloquentProductCategoryRepository against real MySQL —
 * save/findByProductId/remove round-trips, and that assigning the same
 * Category to the same Product twice throws CategoryAlreadyAssignedException
 * via the real UNIQUE(product_id, category_id) constraint
 * (catalog_product_categories_product_id_category_id_unique).
 */
class CatalogProductCategoryRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(string $slug): Product
    {
        $productRepository = app(ProductRepository::class);
        $product = Product::createSimple('Air Max', "SKU-{$slug}", $slug);
        $productRepository->save($product);

        return $product;
    }

    private function createCategory(string $slug): Category
    {
        $categoryRepository = app(CategoryRepository::class);
        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: $slug);
        $categoryRepository->save($category);

        return $category;
    }

    public function test_save_assigns_an_id_and_persists_the_assignment(): void
    {
        $repository = app(ProductCategoryRepository::class);
        $product = $this->createProduct('air-max-1');
        $category = $this->createCategory('shoes-1');

        $productCategory = new ProductCategory(id: null, productId: $product->id(), categoryId: $category->id());
        $repository->save($productCategory);

        $this->assertNotNull($productCategory->id());
        $this->assertDatabaseHas('catalog_product_categories', [
            'id' => $productCategory->id(),
            'product_id' => $product->id(),
            'category_id' => $category->id(),
        ]);
    }

    public function test_find_by_product_id_returns_every_assignment_for_that_product(): void
    {
        $repository = app(ProductCategoryRepository::class);
        $product = $this->createProduct('air-max-2');
        $shoes = $this->createCategory('shoes-2');
        $running = $this->createCategory('running-2');

        $repository->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $shoes->id()));
        $repository->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $running->id()));

        $found = $repository->findByProductId($product->id());

        $this->assertCount(2, $found);
        $categoryIds = array_map(fn (ProductCategory $pc) => $pc->categoryId(), $found);
        sort($categoryIds);
        $expected = [$shoes->id(), $running->id()];
        sort($expected);
        $this->assertSame($expected, $categoryIds);
    }

    public function test_find_by_product_id_returns_empty_array_for_a_product_with_no_categories(): void
    {
        $repository = app(ProductCategoryRepository::class);
        $product = $this->createProduct('air-max-3');

        $this->assertSame([], $repository->findByProductId($product->id()));
    }

    public function test_remove_deletes_the_assignment(): void
    {
        $repository = app(ProductCategoryRepository::class);
        $product = $this->createProduct('air-max-4');
        $category = $this->createCategory('shoes-4');

        $productCategory = new ProductCategory(id: null, productId: $product->id(), categoryId: $category->id());
        $repository->save($productCategory);

        $repository->remove($productCategory->id());

        $this->assertDatabaseMissing('catalog_product_categories', ['id' => $productCategory->id()]);
        $this->assertSame([], $repository->findByProductId($product->id()));
    }

    public function test_assigning_the_same_category_twice_throws_category_already_assigned_exception(): void
    {
        $repository = app(ProductCategoryRepository::class);
        $product = $this->createProduct('air-max-5');
        $category = $this->createCategory('shoes-5');

        $repository->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $category->id()));

        $this->expectException(CategoryAlreadyAssignedException::class);

        $repository->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $category->id()));
    }
}
