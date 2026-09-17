<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\MediaVariant;
use EasyCo\Media\ProductMedia;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production archive-transition photo cleanup —
 * ArchiveProductMediaCleaner, wired into EditProduct's status-change
 * block. Fixture helpers mirror ProductResourceTest's own established
 * shapes (staff/role construction), not reinvented.
 */
class ProductArchiveMediaCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('admin@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function persistedProduct(string $slug = 'archive-media-product'): ProductModel
    {
        $product = Product::createSimple('Archive Media Product', 'SKU-'.strtoupper($slug), $slug);
        app(ProductRepository::class)->save($product);

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    /**
     * Writes real bytes to Storage::fake('public') for the asset's
     * original path and every one of its variant paths, then saves a
     * real, READY MediaAsset (with those variants already attached) via
     * the real repository, and attaches it to the product at the given
     * sort_order.
     */
    private function attachRealImage(string $productId, int $sortOrder, string $basePath, array $variantTiers): MediaAsset
    {
        Storage::disk('public')->put($basePath, 'original bytes');

        $variants = [];
        foreach ($variantTiers as $tier => $maxWidth) {
            $variantPath = str_replace('.webp', "-{$tier}.webp", $basePath);
            Storage::disk('public')->put($variantPath, "{$tier} bytes");
            $variants[] = new MediaVariant($tier, $maxWidth, $maxWidth, 80, $variantPath);
        }

        $asset = MediaAsset::create(MediaType::IMAGE, 'public', $basePath);
        $asset->markProcessing();
        $asset->markReady($variants);
        app(MediaAssetRepository::class)->save($asset);

        app(ProductMediaRepository::class)->save(new ProductMedia(
            id: null,
            productId: $productId,
            mediaId: $asset->id(),
            sortOrder: $sortOrder,
        ));

        return $asset;
    }

    private function attachRealVideo(string $productId, int $sortOrder, string $path): MediaAsset
    {
        Storage::disk('public')->put($path, 'video bytes');

        $asset = MediaAsset::create(MediaType::VIDEO, 'public', $path);
        app(MediaAssetRepository::class)->save($asset);

        app(ProductMediaRepository::class)->save(new ProductMedia(
            id: null,
            productId: $productId,
            mediaId: $asset->id(),
            sortOrder: $sortOrder,
        ));

        return $asset;
    }

    public function test_archiving_a_product_with_three_gallery_photos_and_one_video_keeps_only_the_main_photo_thumbnail(): void
    {
        $this->actingAsPanelAdministrator();

        $product = $this->persistedProduct();
        $productId = (string) $product->id;

        $mainAsset = $this->attachRealImage($productId, 0, 'products/main.webp', [
            'thumbnail' => 400,
            'medium' => 900,
            'large' => 1600,
        ]);
        $gallery1 = $this->attachRealImage($productId, 1, 'products/gallery1.webp', ['thumbnail' => 400]);
        $gallery2 = $this->attachRealImage($productId, 2, 'products/gallery2.webp', ['thumbnail' => 400]);
        $gallery3 = $this->attachRealImage($productId, 3, 'products/gallery3.webp', ['thumbnail' => 400]);
        $video = $this->attachRealVideo($productId, 4, 'products/video.mp4');

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['status' => ProductStatus::ARCHIVED->value])
            ->call('save')
            ->assertHasNoFormErrors();

        // Exactly one pivot remains — the demoted main photo.
        $pivots = app(ProductMediaRepository::class)->findByProductId($productId);
        $this->assertCount(1, $pivots);
        $keptPivot = $pivots[0];

        // The kept pivot references a NEW asset, not the original
        // full-size one — mediaId is readonly, so this MUST be a
        // detach + fresh attach, never the old row mutated in place.
        $this->assertNotSame($mainAsset->id(), $keptPivot->mediaId());

        $newAsset = app(MediaAssetRepository::class)->findById($keptPivot->mediaId());
        $this->assertNotNull($newAsset);
        $this->assertSame(MediaType::IMAGE, $newAsset->type());
        $this->assertSame(ProcessingStatus::READY, $newAsset->processingStatus());
        $this->assertSame([], $newAsset->variants());
        $this->assertSame('products/main-thumbnail.webp', $newAsset->path());

        // Every other MediaAsset row is genuinely gone — not merely
        // detached — queried directly, not inferred from the pivot list.
        $this->assertNull(app(MediaAssetRepository::class)->findById($mainAsset->id()));
        $this->assertNull(app(MediaAssetRepository::class)->findById($gallery1->id()));
        $this->assertNull(app(MediaAssetRepository::class)->findById($gallery2->id()));
        $this->assertNull(app(MediaAssetRepository::class)->findById($gallery3->id()));
        $this->assertNull(app(MediaAssetRepository::class)->findById($video->id()));

        // Real files, actually deleted from the fake disk.
        Storage::disk('public')->assertMissing('products/main.webp');
        Storage::disk('public')->assertMissing('products/main-medium.webp');
        Storage::disk('public')->assertMissing('products/main-large.webp');
        Storage::disk('public')->assertMissing('products/gallery1.webp');
        Storage::disk('public')->assertMissing('products/gallery1-thumbnail.webp');
        Storage::disk('public')->assertMissing('products/gallery2.webp');
        Storage::disk('public')->assertMissing('products/gallery2-thumbnail.webp');
        Storage::disk('public')->assertMissing('products/gallery3.webp');
        Storage::disk('public')->assertMissing('products/gallery3-thumbnail.webp');
        Storage::disk('public')->assertMissing('products/video.mp4');

        // The one file that survives.
        Storage::disk('public')->assertExists('products/main-thumbnail.webp');
    }

    public function test_resaving_an_already_archived_product_does_not_rerun_the_cleanup(): void
    {
        $this->actingAsPanelAdministrator();

        $product = $this->persistedProduct('already-archived-product');
        $productId = (string) $product->id;

        $this->attachRealImage($productId, 0, 'products/already-main.webp', ['thumbnail' => 400]);

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['status' => ProductStatus::ARCHIVED->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $pivotsAfterFirstArchive = app(ProductMediaRepository::class)->findByProductId($productId);
        $this->assertCount(1, $pivotsAfterFirstArchive);
        $mediaIdAfterFirstArchive = $pivotsAfterFirstArchive[0]->mediaId();

        // Re-save the already-archived product — status unchanged, so
        // this must never re-enter the cleanup at all.
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['status' => ProductStatus::ARCHIVED->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $pivotsAfterSecondSave = app(ProductMediaRepository::class)->findByProductId($productId);
        $this->assertCount(1, $pivotsAfterSecondSave);
        $this->assertSame($mediaIdAfterFirstArchive, $pivotsAfterSecondSave[0]->mediaId());
    }
}
