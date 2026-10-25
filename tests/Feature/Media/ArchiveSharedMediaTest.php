<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Services\ArchiveProductMediaCleaner;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Media\ProductMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * ML-3: archiving a product when its pictures may be SHARED. Gallery photos and the video are detached and handed to MediaDeleter (which keeps whatever anything else uses); the main photo is
 * demoted to its thumbnail only when nothing else uses it; every file goes only after the whole clean committed.
 */
class ArchiveSharedMediaTest extends TestCase
{
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use MakesPickerAssets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->giveProductsAShippingClass();
        app(HookRegistry::class)->clear('media.usages');
        Storage::fake('public');
        $this->actingAsStaff('Administrator');
    }

    private function product(string $slug): ProductModel
    {
        $product = Product::createSimple($slug, 'SKU-'.$slug, $slug);
        app(ProductRepository::class)->save($product);

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    /** An image asset with a real original, a thumbnail and a medium file on the fake disk. */
    private function realAsset(string $name): MediaAssetModel
    {
        $paths = ["uploads/2026/10/{$name}.jpg", "uploads/2026/10/{$name}-thumbnail.webp", "uploads/2026/10/{$name}-medium.webp"];

        foreach ($paths as $path) {
            Storage::disk('public')->put($path, 'bytes');
        }

        return $this->pickerAsset(['path' => $paths[0], 'variants' => [
            ['tier' => 'thumbnail', 'width' => 400, 'height' => 400, 'quality' => 80, 'path' => $paths[1]],
            ['tier' => 'medium', 'width' => 900, 'height' => 900, 'quality' => 80, 'path' => $paths[2]],
        ]]);
    }

    private function realVideo(string $name): MediaAssetModel
    {
        Storage::disk('public')->put("uploads/2026/10/{$name}.mp4", 'video');

        return $this->pickerVideo(['path' => "uploads/2026/10/{$name}.mp4"]);
    }

    /** @return list<string> the files of a named asset that are on the disk */
    private function filesOf(string $name): array
    {
        return array_values(array_filter(["uploads/2026/10/{$name}.jpg", "uploads/2026/10/{$name}-thumbnail.webp", "uploads/2026/10/{$name}-medium.webp", "uploads/2026/10/{$name}.mp4"], fn (string $f): bool => Storage::disk('public')->exists($f)));
    }

    private function attach(ProductModel $product, MediaAssetModel $asset, int $sort): void
    {
        app(ProductMediaRepository::class)->save(new ProductMedia(null, (string) $product->id, (string) $asset->id, $sort));
    }

    private function archive(ProductModel $product): void
    {
        Livewire::test(EditProduct::class, ['record' => $product->id])->fillForm(['status' => ProductStatus::ARCHIVED->value])->call('save')->assertHasNoFormErrors();
    }

    public function test_a_gallery_photo_shared_with_another_product_stays_with_its_files(): void
    {
        $archived = $this->product('archived');
        $other = $this->product('other');
        [$main, $shared, $own] = [$this->realAsset('main'), $this->realAsset('shared'), $this->realAsset('own')];
        $this->attach($archived, $main, 0);
        $this->attach($archived, $shared, 1);
        $this->attach($archived, $own, 2);
        $this->attach($other, $shared, 0);

        $this->archive($archived);

        $this->assertNotNull(MediaAssetModel::find($shared->id), 'the shared asset row stays');
        $this->assertSame(3, count($this->filesOf('shared')), 'and so do all its files');
        $this->assertTrue(ProductMediaModel::where('product_id', $other->id)->where('media_id', $shared->id)->exists(), 'the other product still has the photo');
        $this->assertFalse(ProductMediaModel::where('product_id', $archived->id)->where('media_id', $shared->id)->exists(), 'the archived product lost it');
        $this->assertNull(MediaAssetModel::find($own->id), 'its own photo is gone, as before');
        $this->assertSame([], $this->filesOf('own'));
    }

    public function test_a_shared_main_photo_is_not_demoted_and_nothing_is_deleted(): void
    {
        $archived = $this->product('archived');
        $other = $this->product('other');
        $main = $this->realAsset('main');
        $this->attach($archived, $main, 0);
        $this->attach($other, $main, 0);

        $this->archive($archived);

        $this->assertSame([(int) $main->id], ProductMediaModel::where('product_id', $archived->id)->pluck('media_id')->map(fn ($id): int => (int) $id)->all(), 'the archived product keeps the full photo');
        $this->assertSame('uploads/2026/10/main.jpg', MediaAssetModel::find($main->id)->path);
        $this->assertCount(3, $this->filesOf('main'));
        $this->assertSame(1, MediaAssetModel::query()->count(), 'no demoted copy was made');
    }

    public function test_a_main_photo_used_by_a_brand_is_not_demoted_either(): void
    {
        $archived = $this->product('archived');
        $main = $this->realAsset('main');
        $this->attach($archived, $main, 0);
        \EasyCo\Catalog\Persistence\Eloquent\BrandModel::query()->create(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $main->id]);

        $this->archive($archived);

        $this->assertCount(3, $this->filesOf('main'));
        $this->assertSame(1, ProductMediaModel::where('product_id', $archived->id)->count());
    }

    public function test_a_product_that_shares_nothing_behaves_exactly_as_before(): void
    {
        $archived = $this->product('archived');
        [$main, $g1, $g2] = [$this->realAsset('main'), $this->realAsset('g1'), $this->realAsset('g2')];
        $video = $this->realVideo('clip');
        $this->attach($archived, $main, 0);
        $this->attach($archived, $g1, 1);
        $this->attach($archived, $g2, 2);
        $this->attach($archived, $video, 0);

        $this->archive($archived);

        $this->assertNull(MediaAssetModel::find($g1->id));
        $this->assertNull(MediaAssetModel::find($g2->id));
        $this->assertNull(MediaAssetModel::find($video->id));
        $this->assertSame([], $this->filesOf('g1') + $this->filesOf('g2') + $this->filesOf('clip'));
        $pivots = ProductMediaModel::where('product_id', $archived->id)->get();
        $this->assertCount(1, $pivots, 'only the demoted main photo is left');
        $this->assertSame('uploads/2026/10/main-thumbnail.webp', MediaAssetModel::find($pivots[0]->media_id)->path);
        $this->assertNull(MediaAssetModel::find($main->id), 'the old main asset row is gone');
        $this->assertSame(['uploads/2026/10/main-thumbnail.webp'], $this->filesOf('main'), 'only the thumbnail file the demoted asset points at is kept');
    }

    public function test_a_failure_in_the_middle_deletes_no_file_and_keeps_every_row(): void
    {
        $archived = $this->product('archived');
        [$main, $g1, $g2] = [$this->realAsset('main'), $this->realAsset('g1'), $this->realAsset('g2')];
        $this->attach($archived, $main, 0);
        $this->attach($archived, $g1, 1);
        $this->attach($archived, $g2, 2);

        // the third detach fails: the demotion and the first gallery photo were already handled by then
        $this->app->extend(ProductMediaRepository::class, fn (ProductMediaRepository $inner): ProductMediaRepository => new class($inner) implements ProductMediaRepository
        {
            private int $removed = 0;

            public function __construct(private readonly ProductMediaRepository $inner)
            {
            }

            public function save(ProductMedia $productMedia): void
            {
                $this->inner->save($productMedia);
            }

            public function remove(string $id): void
            {
                if (++$this->removed === 3) {
                    throw new \RuntimeException('disk full, say');
                }

                $this->inner->remove($id);
            }

            public function findByProductId(string $productId): array
            {
                return $this->inner->findByProductId($productId);
            }

            public function countByProductId(string $productId): int
            {
                return $this->inner->countByProductId($productId);
            }

            public function countByProductIdAndType(string $productId, \EasyCo\Media\Enums\MediaType $type): int
            {
                return $this->inner->countByProductIdAndType($productId, $type);
            }
        });

        try {
            app(ArchiveProductMediaCleaner::class)->clean((string) $archived->id);
            $this->fail('the failure should have come out');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full, say', $e->getMessage());
        }

        foreach (['main' => 3, 'g1' => 3, 'g2' => 3] as $name => $count) {
            $this->assertCount($count, $this->filesOf($name), "{$name}: no file was deleted");
        }

        foreach ([$main, $g1, $g2] as $asset) {
            $this->assertNotNull(MediaAssetModel::find($asset->id), 'every asset row is back');
        }

        $this->assertSame(3, ProductMediaModel::where('product_id', $archived->id)->count(), 'and every pivot');
        $this->assertSame(3, MediaAssetModel::query()->count(), 'no demoted copy was left behind');
    }
}
