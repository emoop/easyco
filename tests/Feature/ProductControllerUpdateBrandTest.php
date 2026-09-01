<?php

namespace Tests\Feature;

use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUT /products/{productId}/brand — App\Http\Controllers\Api\ProductController::updateBrand().
 * The one Product field made HTTP-editable in this prompt.
 */
class ProductControllerUpdateBrandTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private function createProduct(): Product
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product;
    }

    private function createBrand(): Brand
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $brand = new Brand(id: null, name: "Brand {$suffix}", slug: "brand-{$suffix}");
        app(BrandRepository::class)->save($brand);

        return $brand;
    }

    public function test_setting_a_brand_on_a_product_with_no_brand_returns_200_and_persists(): void
    {
        $product = $this->createProduct();
        $brand = $this->createBrand();

        $response = $this->putJson("/api/products/{$product->id()}/brand", ['brand_id' => $brand->id()]);

        $response->assertStatus(200);
        $response->assertJsonPath('product_id', $product->id());
        $response->assertJsonPath('brand_id', $brand->id());

        $reloaded = app(ProductRepository::class)->findById($product->id());
        $this->assertSame($brand->id(), $reloaded->brandId());
    }

    public function test_setting_brand_to_null_clears_an_existing_brand(): void
    {
        $product = $this->createProduct();
        $brand = $this->createBrand();

        $this->putJson("/api/products/{$product->id()}/brand", ['brand_id' => $brand->id()])
            ->assertStatus(200);

        $response = $this->putJson("/api/products/{$product->id()}/brand", ['brand_id' => null]);

        $response->assertStatus(200);
        $response->assertJsonPath('product_id', $product->id());
        $response->assertJsonPath('brand_id', null);

        $reloaded = app(ProductRepository::class)->findById($product->id());
        $this->assertNull($reloaded->brandId());
    }

    public function test_a_nonexistent_brand_id_returns_422(): void
    {
        $product = $this->createProduct();

        $response = $this->putJson("/api/products/{$product->id()}/brand", ['brand_id' => '999999']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['brand_id']);
    }

    public function test_a_nonexistent_product_id_returns_422(): void
    {
        $brand = $this->createBrand();

        $response = $this->putJson('/api/products/999999/brand', ['brand_id' => $brand->id()]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['product_id']);
    }

    public function test_omitting_brand_id_entirely_returns_422(): void
    {
        $product = $this->createProduct();

        $response = $this->putJson("/api/products/{$product->id()}/brand", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['brand_id']);
    }
}
