<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMediaCountGuardTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function guard(int $maxMediaCount): ProductMediaCountGuard
    {
        return new ProductMediaCountGuard(app(ProductMediaRepository::class), $maxMediaCount);
    }

    private function productId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->id();
    }

    private function attachMedia(string $productId): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo-'.uniqid().'.jpg');
        app(MediaAssetRepository::class)->save($asset);

        app(ProductMediaRepository::class)->save(new ProductMedia(null, $productId, $asset->id()));
    }

    public function test_zero_existing_photos_max_three_does_not_throw(): void
    {
        $productId = $this->productId();

        $this->expectNotToPerformAssertions();
        $this->guard(3)->assertCanAttach($productId);
    }

    public function test_two_existing_photos_max_three_does_not_throw(): void
    {
        $productId = $this->productId();
        $this->attachMedia($productId);
        $this->attachMedia($productId);

        $this->expectNotToPerformAssertions();
        $this->guard(3)->assertCanAttach($productId);
    }

    public function test_three_existing_photos_max_three_throws(): void
    {
        $productId = $this->productId();
        $this->attachMedia($productId);
        $this->attachMedia($productId);
        $this->attachMedia($productId);

        $this->expectException(MediaLimitExceededException::class);
        $this->guard(3)->assertCanAttach($productId);
    }

    public function test_exception_message_contains_product_id_current_count_and_max(): void
    {
        $productId = $this->productId();
        $this->attachMedia($productId);
        $this->attachMedia($productId);
        $this->attachMedia($productId);

        try {
            $this->guard(3)->assertCanAttach($productId);
            $this->fail('Expected MediaLimitExceededException was not thrown.');
        } catch (MediaLimitExceededException $e) {
            $this->assertStringContainsString($productId, $e->getMessage());
            $this->assertStringContainsString('3', $e->getMessage());
        }
    }
}
