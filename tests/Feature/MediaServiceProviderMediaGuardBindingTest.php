<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use EasyCo\Media\VariationMedia;
use EasyCo\Media\VariationMediaCountGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms the container binding itself, not just the guard classes'
 * own behavior in isolation (already covered by ProductMediaCountGuardTest/
 * VariationMediaCountGuardTest) — MediaServiceProvider must actually
 * inject the real config()-sourced max count, not merely have code
 * that looks like it does. No env override is set for either value in
 * this test environment, so this also exercises config/services.php's
 * own defaults (10/3).
 */
class MediaServiceProviderMediaGuardBindingTest extends TestCase
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

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function attachMediaToProduct(string $productId): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo-'.uniqid().'.jpg');
        app(MediaAssetRepository::class)->save($asset);

        app(ProductMediaRepository::class)->save(new ProductMedia(null, $productId, $asset->id()));
    }

    private function attachMediaToVariation(string $variationId): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo-'.uniqid().'.jpg');
        app(MediaAssetRepository::class)->save($asset);

        app(VariationMediaRepository::class)->save(new VariationMedia(null, $variationId, $asset->id()));
    }

    public function test_product_media_count_guard_binding_uses_the_configured_default_of_ten(): void
    {
        $productId = $this->productId();
        for ($i = 0; $i < 9; $i++) {
            $this->attachMediaToProduct($productId);
        }

        // 9 attached, default max 10 — must not throw yet.
        app(ProductMediaCountGuard::class)->assertCanAttach($productId);

        $this->attachMediaToProduct($productId);

        // Now 10 attached — must throw.
        $this->expectException(MediaLimitExceededException::class);
        app(ProductMediaCountGuard::class)->assertCanAttach($productId);
    }

    public function test_variation_media_count_guard_binding_uses_the_configured_default_of_three(): void
    {
        $variationId = $this->variationId();
        $this->attachMediaToVariation($variationId);
        $this->attachMediaToVariation($variationId);

        // 2 attached, default max 3 — must not throw yet.
        app(VariationMediaCountGuard::class)->assertCanAttach($variationId);

        $this->attachMediaToVariation($variationId);

        // Now 3 attached — must throw.
        $this->expectException(MediaLimitExceededException::class);
        app(VariationMediaCountGuard::class)->assertCanAttach($variationId);
    }
}
