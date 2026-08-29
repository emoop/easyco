<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\VariationMedia;
use EasyCo\Media\VariationMediaCountGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariationMediaCountGuardTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function guard(int $maxMediaCount): VariationMediaCountGuard
    {
        return new VariationMediaCountGuard(app(VariationMediaRepository::class), $maxMediaCount);
    }

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function attachMedia(string $variationId): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo-'.uniqid().'.jpg');
        app(MediaAssetRepository::class)->save($asset);

        app(VariationMediaRepository::class)->save(new VariationMedia(null, $variationId, $asset->id()));
    }

    public function test_zero_existing_photos_max_three_does_not_throw(): void
    {
        $variationId = $this->variationId();

        $this->expectNotToPerformAssertions();
        $this->guard(3)->assertCanAttach($variationId);
    }

    public function test_two_existing_photos_max_three_does_not_throw(): void
    {
        $variationId = $this->variationId();
        $this->attachMedia($variationId);
        $this->attachMedia($variationId);

        $this->expectNotToPerformAssertions();
        $this->guard(3)->assertCanAttach($variationId);
    }

    public function test_three_existing_photos_max_three_throws(): void
    {
        $variationId = $this->variationId();
        $this->attachMedia($variationId);
        $this->attachMedia($variationId);
        $this->attachMedia($variationId);

        $this->expectException(MediaLimitExceededException::class);
        $this->guard(3)->assertCanAttach($variationId);
    }

    public function test_exception_message_contains_variation_id_current_count_and_max(): void
    {
        $variationId = $this->variationId();
        $this->attachMedia($variationId);
        $this->attachMedia($variationId);
        $this->attachMedia($variationId);

        try {
            $this->guard(3)->assertCanAttach($variationId);
            $this->fail('Expected MediaLimitExceededException was not thrown.');
        } catch (MediaLimitExceededException $e) {
            $this->assertStringContainsString($variationId, $e->getMessage());
            $this->assertStringContainsString('3', $e->getMessage());
        }
    }
}
