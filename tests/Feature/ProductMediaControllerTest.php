<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\MediaVariant;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Media\ProductMedia;
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

    /** A processed asset with real variants, for list-shape assertions. */
    private function readyMediaId(): string
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo-'.uniqid().'.jpg');
        app(MediaAssetRepository::class)->save($asset);

        $asset->markProcessing();
        $asset->markReady([
            new MediaVariant('thumbnail', 200, 200, 80, 'uploads/2026/08/photo-thumb.webp'),
        ]);
        app(MediaAssetRepository::class)->save($asset);

        return $asset->id();
    }

    private function attachMedia(string $productId, ?string $mediaId = null, int $sortOrder = 0): string
    {
        $productMedia = new ProductMedia(null, $productId, $mediaId ?? $this->mediaId(), $sortOrder);
        app(ProductMediaRepository::class)->save($productMedia);

        return $productMedia->id();
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

    public function test_index_on_a_product_with_no_media_returns_an_empty_data_array(): void
    {
        $productId = $this->productId();

        $response = $this->getJson("/api/products/{$productId}/media");

        $response->assertStatus(200);
        $response->assertExactJson(['data' => []]);
    }

    public function test_index_returns_attached_media_ordered_by_sort_order_with_expected_shape(): void
    {
        $productId = $this->productId();
        $readyId = $this->readyMediaId();
        $pendingId = $this->mediaId();

        // Inserted out of order to prove the response is sorted, not
        // insertion-ordered.
        $this->attachMedia($productId, $pendingId, sortOrder: 1);
        $this->attachMedia($productId, $readyId, sortOrder: 0);

        $response = $this->getJson("/api/products/{$productId}/media");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);

        $this->assertSame($readyId, $data[0]['media_id']);
        $this->assertSame(0, $data[0]['sort_order']);
        $this->assertSame('ready', $data[0]['processing_status']);
        $this->assertIsString($data[0]['url']);
        $this->assertNotSame('', $data[0]['url']);
        $this->assertArrayHasKey('thumbnail', $data[0]['variants']);

        $this->assertSame($pendingId, $data[1]['media_id']);
        $this->assertSame(1, $data[1]['sort_order']);
        $this->assertSame('pending', $data[1]['processing_status']);
        $this->assertSame([], $data[1]['variants']);
    }

    public function test_index_for_a_nonexistent_product_id_returns_422(): void
    {
        $response = $this->getJson('/api/products/999999/media');

        $response->assertStatus(422);
    }

    public function test_destroy_happy_path_returns_204_and_removes_the_row(): void
    {
        $productId = $this->productId();
        $pivotId = $this->attachMedia($productId);

        $response = $this->deleteJson("/api/products/{$productId}/media/{$pivotId}");

        $response->assertStatus(204);
        $this->assertSame(0, ProductMediaModel::count());
    }

    public function test_destroy_a_pivot_belonging_to_a_different_product_returns_404_and_does_not_delete(): void
    {
        $productId = $this->productId();
        $otherProductId = $this->productId();
        $pivotId = $this->attachMedia($productId);

        $response = $this->deleteJson("/api/products/{$otherProductId}/media/{$pivotId}");

        $response->assertStatus(404);
        $this->assertSame(1, ProductMediaModel::count());
    }

    public function test_destroy_a_nonexistent_pivot_id_returns_404(): void
    {
        $productId = $this->productId();

        $response = $this->deleteJson("/api/products/{$productId}/media/999999");

        $response->assertStatus(404);
    }

    public function test_destroy_does_not_touch_the_underlying_media_asset(): void
    {
        $productId = $this->productId();
        $mediaId = $this->mediaId();
        $pivotId = $this->attachMedia($productId, $mediaId);

        $this->deleteJson("/api/products/{$productId}/media/{$pivotId}")->assertStatus(204);

        $this->assertNotNull(app(MediaAssetRepository::class)->findById($mediaId));
    }

    public function test_reorder_happy_path_reverses_three_items_and_persists_new_order(): void
    {
        $productId = $this->productId();
        $first = $this->attachMedia($productId, sortOrder: 0);
        $second = $this->attachMedia($productId, sortOrder: 1);
        $third = $this->attachMedia($productId, sortOrder: 2);

        $response = $this->putJson("/api/products/{$productId}/media/order", [
            'order' => [$third, $second, $first],
        ]);

        $response->assertStatus(200);
        $this->assertSame(0, ProductMediaModel::find($third)->sort_order);
        $this->assertSame(1, ProductMediaModel::find($second)->sort_order);
        $this->assertSame(2, ProductMediaModel::find($first)->sort_order);
    }

    public function test_reorder_with_a_partial_array_missing_one_id_returns_422_and_changes_nothing(): void
    {
        $productId = $this->productId();
        $first = $this->attachMedia($productId, sortOrder: 0);
        $second = $this->attachMedia($productId, sortOrder: 1);

        $response = $this->putJson("/api/products/{$productId}/media/order", [
            'order' => [$second],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ProductMediaModel::find($first)->sort_order);
        $this->assertSame(1, ProductMediaModel::find($second)->sort_order);
    }

    public function test_reorder_with_a_foreign_pivot_id_returns_422(): void
    {
        $productId = $this->productId();
        $otherProductId = $this->productId();
        $ownPivot = $this->attachMedia($productId, sortOrder: 0);
        $foreignPivot = $this->attachMedia($otherProductId, sortOrder: 0);

        $response = $this->putJson("/api/products/{$productId}/media/order", [
            'order' => [$foreignPivot],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ProductMediaModel::find($ownPivot)->sort_order);
    }

    public function test_reorder_with_a_duplicate_id_returns_422(): void
    {
        $productId = $this->productId();
        $first = $this->attachMedia($productId, sortOrder: 0);
        $second = $this->attachMedia($productId, sortOrder: 1);

        $response = $this->putJson("/api/products/{$productId}/media/order", [
            'order' => [$first, $first],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ProductMediaModel::find($first)->sort_order);
        $this->assertSame(1, ProductMediaModel::find($second)->sort_order);
    }

    public function test_reorder_on_an_empty_product_with_an_empty_array_is_a_noop(): void
    {
        $productId = $this->productId();

        $response = $this->putJson("/api/products/{$productId}/media/order", [
            'order' => [],
        ]);

        $response->assertStatus(200);
        $this->assertSame(0, ProductMediaModel::where('product_id', $productId)->count());
    }
}
