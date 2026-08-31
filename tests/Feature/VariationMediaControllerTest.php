<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\Persistence\Eloquent\VariationMediaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariationMediaControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function mediaId(): string
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo-'.uniqid().'.jpg');
        app(MediaAssetRepository::class)->save($asset);

        return $asset->id();
    }

    public function test_happy_path_attach_creates_a_row_and_returns_201(): void
    {
        $variationId = $this->variationId();
        $mediaId = $this->mediaId();

        $response = $this->postJson("/api/variations/{$variationId}/media", [
            'media_id' => $mediaId,
            'sort_order' => 2,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('variation_id', $variationId);
        $response->assertJsonPath('media_id', $mediaId);
        $response->assertJsonPath('sort_order', 2);

        $this->assertSame(1, VariationMediaModel::count());
        $row = VariationMediaModel::first();
        $this->assertSame($variationId, (string) $row->variation_id);
        $this->assertSame($mediaId, (string) $row->media_id);
        $this->assertSame(2, $row->sort_order);
    }

    public function test_sort_order_omitted_defaults_to_zero(): void
    {
        $variationId = $this->variationId();
        $mediaId = $this->mediaId();

        $response = $this->postJson("/api/variations/{$variationId}/media", [
            'media_id' => $mediaId,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('sort_order', 0);
        $this->assertSame(0, VariationMediaModel::first()->sort_order);
    }

    public function test_attaching_the_same_media_twice_returns_a_clean_422_and_creates_no_duplicate_row(): void
    {
        $variationId = $this->variationId();
        $mediaId = $this->mediaId();

        $first = $this->postJson("/api/variations/{$variationId}/media", ['media_id' => $mediaId]);
        $first->assertStatus(201);

        $second = $this->postJson("/api/variations/{$variationId}/media", ['media_id' => $mediaId]);
        $second->assertStatus(422);

        $this->assertSame(1, VariationMediaModel::count());
    }

    public function test_attaching_to_a_nonexistent_variation_id_returns_422(): void
    {
        $mediaId = $this->mediaId();

        $response = $this->postJson('/api/variations/999999/media', ['media_id' => $mediaId]);

        $response->assertStatus(422);
        $this->assertSame(0, VariationMediaModel::count());
    }

    public function test_attaching_a_nonexistent_media_id_returns_422(): void
    {
        $variationId = $this->variationId();

        $response = $this->postJson("/api/variations/{$variationId}/media", ['media_id' => 999999]);

        $response->assertStatus(422);
        $this->assertSame(0, VariationMediaModel::count());
    }

    public function test_exceeding_max_photos_per_variation_on_the_fourth_attach_returns_422_with_exactly_three_rows(): void
    {
        $variationId = $this->variationId();

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson("/api/variations/{$variationId}/media", ['media_id' => $this->mediaId()]);
            $response->assertStatus(201);
        }

        $this->assertSame(3, VariationMediaModel::where('variation_id', $variationId)->count());

        $fourth = $this->postJson("/api/variations/{$variationId}/media", ['media_id' => $this->mediaId()]);
        $fourth->assertStatus(422);

        $this->assertSame(3, VariationMediaModel::where('variation_id', $variationId)->count());
    }
}
