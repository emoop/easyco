<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Media\ProductMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentProductMediaRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function repository(): ProductMediaRepository
    {
        return app(ProductMediaRepository::class);
    }

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

    public function test_save_then_find_by_product_id_round_trips(): void
    {
        $productId = $this->productId();
        $mediaId = $this->mediaId();

        $productMedia = new ProductMedia(id: null, productId: $productId, mediaId: $mediaId, sortOrder: 2);
        $this->repository()->save($productMedia);

        $this->assertNotNull($productMedia->id());

        $reloaded = $this->repository()->findByProductId($productId);
        $this->assertCount(1, $reloaded);
        $this->assertSame($productMedia->id(), $reloaded[0]->id());
        $this->assertSame($productId, $reloaded[0]->productId());
        $this->assertSame($mediaId, $reloaded[0]->mediaId());
        $this->assertSame(2, $reloaded[0]->sortOrder());
    }

    public function test_find_by_product_id_returns_results_ordered_by_sort_order_ascending(): void
    {
        $productId = $this->productId();

        // Inserted deliberately out of order.
        $this->repository()->save(new ProductMedia(null, $productId, $this->mediaId(), sortOrder: 2));
        $this->repository()->save(new ProductMedia(null, $productId, $this->mediaId(), sortOrder: 0));
        $this->repository()->save(new ProductMedia(null, $productId, $this->mediaId(), sortOrder: 1));

        $reloaded = $this->repository()->findByProductId($productId);

        $this->assertCount(3, $reloaded);
        $this->assertSame([0, 1, 2], array_map(fn (ProductMedia $pm) => $pm->sortOrder(), $reloaded));
    }

    public function test_count_by_product_id_returns_the_exact_count_and_zero_for_a_product_with_no_media(): void
    {
        $productId = $this->productId();
        $otherProductId = $this->productId();

        $this->repository()->save(new ProductMedia(null, $productId, $this->mediaId()));
        $this->repository()->save(new ProductMedia(null, $productId, $this->mediaId()));

        $this->assertSame(2, $this->repository()->countByProductId($productId));
        $this->assertSame(0, $this->repository()->countByProductId($otherProductId));
    }

    public function test_update_sort_order_via_domain_object_then_save_updates_the_same_row(): void
    {
        $productId = $this->productId();
        $productMedia = new ProductMedia(null, $productId, $this->mediaId(), sortOrder: 0);
        $this->repository()->save($productMedia);
        $originalId = $productMedia->id();

        $productMedia->updateSortOrder(5);
        $this->repository()->save($productMedia);

        $this->assertSame($originalId, $productMedia->id());
        $this->assertSame(1, ProductMediaModel::count());

        $reloaded = $this->repository()->findByProductId($productId);
        $this->assertCount(1, $reloaded);
        $this->assertSame(5, $reloaded[0]->sortOrder());
    }

    public function test_remove_deletes_the_row(): void
    {
        $productId = $this->productId();
        $productMedia = new ProductMedia(null, $productId, $this->mediaId());
        $this->repository()->save($productMedia);

        $this->repository()->remove($productMedia->id());

        $this->assertSame([], $this->repository()->findByProductId($productId));
    }
}
