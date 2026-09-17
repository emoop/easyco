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
use EasyCo\Media\VideoCountGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Mirrors ProductMediaCountGuardTest's own structure exactly. */
class VideoCountGuardTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function guard(): VideoCountGuard
    {
        return new VideoCountGuard(app(ProductMediaRepository::class));
    }

    private function productId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Video Product {$suffix}", "SKU-VID-{$suffix}", "video-product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->id();
    }

    private function attachMedia(string $productId, MediaType $type): void
    {
        $extension = $type === MediaType::VIDEO ? 'mp4' : 'jpg';
        $asset = MediaAsset::create($type, 'public', "uploads/2026/09/media-{$type->value}-".uniqid().".{$extension}");
        app(MediaAssetRepository::class)->save($asset);

        app(ProductMediaRepository::class)->save(new ProductMedia(null, $productId, $asset->id()));
    }

    public function test_zero_existing_videos_does_not_throw(): void
    {
        $productId = $this->productId();

        $this->expectNotToPerformAssertions();
        $this->guard()->assertCanAttach($productId);
    }

    public function test_one_existing_video_throws(): void
    {
        $productId = $this->productId();
        $this->attachMedia($productId, MediaType::VIDEO);

        $this->expectException(MediaLimitExceededException::class);
        $this->guard()->assertCanAttach($productId);
    }

    /**
     * The real, deliberate distinction this whole guard exists for:
     * photos attached to the SAME product must never count toward the
     * video limit — this is a genuinely separate count from
     * ProductMediaCountGuard's own combined slot total, not a filtered
     * view of it.
     */
    public function test_existing_photos_do_not_count_toward_the_video_limit(): void
    {
        $productId = $this->productId();
        $this->attachMedia($productId, MediaType::IMAGE);
        $this->attachMedia($productId, MediaType::IMAGE);
        $this->attachMedia($productId, MediaType::IMAGE);

        $this->expectNotToPerformAssertions();
        $this->guard()->assertCanAttach($productId);
    }

    public function test_exception_message_contains_the_product_id(): void
    {
        $productId = $this->productId();
        $this->attachMedia($productId, MediaType::VIDEO);

        try {
            $this->guard()->assertCanAttach($productId);
            $this->fail('Expected MediaLimitExceededException was not thrown.');
        } catch (MediaLimitExceededException $e) {
            $this->assertStringContainsString($productId, $e->getMessage());
            $this->assertStringContainsString('at most one video', $e->getMessage());
        }
    }
}
