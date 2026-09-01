<?php

namespace Tests\Feature;

use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductCategoryModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private function productId(): string
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->id();
    }

    private function categoryId(): string
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $category = new Category(id: null, parentId: null, name: "Category {$suffix}", slug: "category-{$suffix}");
        app(CategoryRepository::class)->save($category);

        return $category->id();
    }

    private function assignCategory(string $productId, ?string $categoryId = null): string
    {
        $productCategory = new ProductCategory(null, $productId, $categoryId ?? $this->categoryId());
        app(ProductCategoryRepository::class)->save($productCategory);

        return $productCategory->id();
    }

    public function test_happy_path_attach_creates_a_row_and_returns_201(): void
    {
        $productId = $this->productId();
        $categoryId = $this->categoryId();

        $response = $this->postJson("/api/products/{$productId}/categories", [
            'category_id' => $categoryId,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('product_id', $productId);
        $response->assertJsonPath('category_id', $categoryId);

        $this->assertSame(1, ProductCategoryModel::count());
        $row = ProductCategoryModel::first();
        $this->assertSame($productId, (string) $row->product_id);
        $this->assertSame($categoryId, (string) $row->category_id);
    }

    public function test_attaching_the_same_category_twice_returns_a_clean_422_and_creates_no_duplicate_row(): void
    {
        $productId = $this->productId();
        $categoryId = $this->categoryId();

        $first = $this->postJson("/api/products/{$productId}/categories", ['category_id' => $categoryId]);
        $first->assertStatus(201);

        $second = $this->postJson("/api/products/{$productId}/categories", ['category_id' => $categoryId]);
        $second->assertStatus(422);

        $this->assertSame(1, ProductCategoryModel::count());
    }

    public function test_attaching_to_a_nonexistent_product_id_returns_422(): void
    {
        $categoryId = $this->categoryId();

        $response = $this->postJson('/api/products/999999/categories', ['category_id' => $categoryId]);

        $response->assertStatus(422);
        $this->assertSame(0, ProductCategoryModel::count());
    }

    public function test_attaching_a_nonexistent_category_id_returns_422(): void
    {
        $productId = $this->productId();

        $response = $this->postJson("/api/products/{$productId}/categories", ['category_id' => 999999]);

        $response->assertStatus(422);
        $this->assertSame(0, ProductCategoryModel::count());
    }

    public function test_index_on_a_product_with_no_categories_returns_an_empty_data_array(): void
    {
        $productId = $this->productId();

        $response = $this->getJson("/api/products/{$productId}/categories");

        $response->assertStatus(200);
        $response->assertExactJson(['data' => []]);
    }

    public function test_index_returns_the_hydrated_shape(): void
    {
        $productId = $this->productId();
        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);
        $this->assignCategory($productId, $category->id());

        $response = $this->getJson("/api/products/{$productId}/categories");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($category->id(), $data[0]['category_id']);
        $this->assertSame('Shoes', $data[0]['name']);
        $this->assertSame('shoes', $data[0]['slug']);
        $this->assertArrayHasKey('id', $data[0]);
    }

    public function test_index_for_a_nonexistent_product_id_returns_422(): void
    {
        $response = $this->getJson('/api/products/999999/categories');

        $response->assertStatus(422);
    }

    public function test_destroy_happy_path_returns_204_and_removes_the_row(): void
    {
        $productId = $this->productId();
        $categoryId = $this->categoryId();
        $this->assignCategory($productId, $categoryId);

        $response = $this->deleteJson("/api/products/{$productId}/categories/{$categoryId}");

        $response->assertStatus(204);
        $this->assertSame(0, ProductCategoryModel::count());
    }

    public function test_destroy_an_assignment_belonging_to_a_different_product_returns_404_and_does_not_delete(): void
    {
        $productId = $this->productId();
        $otherProductId = $this->productId();
        $categoryId = $this->categoryId();
        $this->assignCategory($productId, $categoryId);

        $response = $this->deleteJson("/api/products/{$otherProductId}/categories/{$categoryId}");

        $response->assertStatus(404);
        $this->assertSame(1, ProductCategoryModel::count());
    }

    public function test_destroy_a_nonexistent_category_id_on_a_real_product_returns_404(): void
    {
        $productId = $this->productId();

        $response = $this->deleteJson("/api/products/{$productId}/categories/999999");

        $response->assertStatus(404);
    }
}
