<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\Persistence\Eloquent\VariationMediaModel;
use EasyCo\Media\VariationMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentVariationMediaRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function repository(): VariationMediaRepository
    {
        return app(VariationMediaRepository::class);
    }

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

    public function test_save_then_find_by_variation_id_round_trips(): void
    {
        $variationId = $this->variationId();
        $mediaId = $this->mediaId();

        $variationMedia = new VariationMedia(id: null, variationId: $variationId, mediaId: $mediaId, sortOrder: 2);
        $this->repository()->save($variationMedia);

        $this->assertNotNull($variationMedia->id());

        $reloaded = $this->repository()->findByVariationId($variationId);
        $this->assertCount(1, $reloaded);
        $this->assertSame($variationMedia->id(), $reloaded[0]->id());
        $this->assertSame($variationId, $reloaded[0]->variationId());
        $this->assertSame($mediaId, $reloaded[0]->mediaId());
        $this->assertSame(2, $reloaded[0]->sortOrder());
    }

    public function test_find_by_variation_id_returns_results_ordered_by_sort_order_ascending(): void
    {
        $variationId = $this->variationId();

        // Inserted deliberately out of order.
        $this->repository()->save(new VariationMedia(null, $variationId, $this->mediaId(), sortOrder: 2));
        $this->repository()->save(new VariationMedia(null, $variationId, $this->mediaId(), sortOrder: 0));
        $this->repository()->save(new VariationMedia(null, $variationId, $this->mediaId(), sortOrder: 1));

        $reloaded = $this->repository()->findByVariationId($variationId);

        $this->assertCount(3, $reloaded);
        $this->assertSame([0, 1, 2], array_map(fn (VariationMedia $vm) => $vm->sortOrder(), $reloaded));
    }

    public function test_count_by_variation_id_returns_the_exact_count_and_zero_for_a_variation_with_no_media(): void
    {
        $variationId = $this->variationId();
        $otherVariationId = $this->variationId();

        $this->repository()->save(new VariationMedia(null, $variationId, $this->mediaId()));
        $this->repository()->save(new VariationMedia(null, $variationId, $this->mediaId()));

        $this->assertSame(2, $this->repository()->countByVariationId($variationId));
        $this->assertSame(0, $this->repository()->countByVariationId($otherVariationId));
    }

    public function test_update_sort_order_via_domain_object_then_save_updates_the_same_row(): void
    {
        $variationId = $this->variationId();
        $variationMedia = new VariationMedia(null, $variationId, $this->mediaId(), sortOrder: 0);
        $this->repository()->save($variationMedia);
        $originalId = $variationMedia->id();

        $variationMedia->updateSortOrder(5);
        $this->repository()->save($variationMedia);

        $this->assertSame($originalId, $variationMedia->id());
        $this->assertSame(1, VariationMediaModel::count());

        $reloaded = $this->repository()->findByVariationId($variationId);
        $this->assertCount(1, $reloaded);
        $this->assertSame(5, $reloaded[0]->sortOrder());
    }

    public function test_remove_deletes_the_row(): void
    {
        $variationId = $this->variationId();
        $variationMedia = new VariationMedia(null, $variationId, $this->mediaId());
        $this->repository()->save($variationMedia);

        $this->repository()->remove($variationMedia->id());

        $this->assertSame([], $this->repository()->findByVariationId($variationId));
    }
}
