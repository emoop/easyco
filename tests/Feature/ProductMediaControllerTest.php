<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMediaControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function productId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->id();
    }

    private function mediaId(): string
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo-'.uniqid().'.jpg');
        app(MediaAssetRepository::class)->save($asset);

        return $asset->id();
    }

    public function test_happy_path_attach_creates_a_row_and_returns_201(): void
    {
        $productId = $this->productId();
        $mediaId = $this->mediaId();

        $response = $this->postJson("/api/products/{$productId}/media", [
            'media_id' => $mediaId,
            'sort_order' => 2,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('product_id', $productId);
        $response->assertJsonPath('media_id', $mediaId);
        $response->assertJsonPath('sort_order', 2);

        $this->assertSame(1, ProductMediaModel::count());
        $row = ProductMediaModel::first();
        $this->assertSame($productId, (string) $row->product_id);
        $this->assertSame($mediaId, (string) $row->media_id);
        $this->assertSame(2, $row->sort_order);
    }

    public function test_sort_order_omitted_defaults_to_zero(): void
    {
        $productId = $this->productId();
        $mediaId = $this->mediaId();

        $response = $this->postJson("/api/products/{$productId}/media", [
            'media_id' => $mediaId,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('sort_order', 0);
        $this->assertSame(0, ProductMediaModel::first()->sort_order);
    }

    public function test_attaching_the_same_media_twice_returns_a_clean_422_and_creates_no_duplicate_row(): void
    {
        $productId = $this->productId();
        $mediaId = $this->mediaId();

        $first = $this->postJson("/api/products/{$productId}/media", ['media_id' => $mediaId]);
        $first->assertStatus(201);

        $second = $this->postJson("/api/products/{$productId}/media", ['media_id' => $mediaId]);
        $second->assertStatus(422);

        $this->assertSame(1, ProductMediaModel::count());
    }

    public function test_attaching_to_a_nonexistent_product_id_returns_422(): void
    {
        $mediaId = $this->mediaId();

        $response = $this->postJson('/api/products/999999/media', ['media_id' => $mediaId]);

        $response->assertStatus(422);
        $this->assertSame(0, ProductMediaModel::count());
    }

    public function test_attaching_a_nonexistent_media_id_returns_422(): void
    {
        $productId = $this->productId();

        $response = $this->postJson("/api/products/{$productId}/media", ['media_id' => 999999]);

        $response->assertStatus(422);
        $this->assertSame(0, ProductMediaModel::count());
    }

    public function test_exceeding_max_photos_per_product_on_the_eleventh_attach_returns_422_with_exactly_ten_rows(): void
    {
        $productId = $this->productId();

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson("/api/products/{$productId}/media", ['media_id' => $this->mediaId()]);
            $response->assertStatus(201);
        }

        $this->assertSame(10, ProductMediaModel::where('product_id', $productId)->count());

        $eleventh = $this->postJson("/api/products/{$productId}/media", ['media_id' => $this->mediaId()]);
        $eleventh->assertStatus(422);

        $this->assertSame(10, ProductMediaModel::where('product_id', $productId)->count());
    }
}
