<?php

namespace Tests\Feature;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\MediaVariant;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentMediaAssetRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): MediaAssetRepository
    {
        return app(MediaAssetRepository::class);
    }

    public function test_save_insert_image_then_find_by_id_round_trips_all_fields(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg', 'A product photo');

        $this->repository()->save($asset);

        $this->assertNotNull($asset->id());

        $reloaded = $this->repository()->findById($asset->id());

        $this->assertNotNull($reloaded);
        $this->assertSame($asset->id(), $reloaded->id());
        $this->assertSame(MediaType::IMAGE, $reloaded->type());
        $this->assertSame('public', $reloaded->disk());
        $this->assertSame('uploads/2026/08/photo.jpg', $reloaded->path());
        $this->assertSame('A product photo', $reloaded->altText());
        $this->assertSame(ProcessingStatus::PENDING, $reloaded->processingStatus());
        $this->assertNull($reloaded->processingFailureReason());
        $this->assertSame([], $reloaded->variants());
    }

    public function test_save_insert_video_round_trips_ready_status_and_empty_variants_as_json_array_not_null(): void
    {
        $asset = MediaAsset::create(MediaType::VIDEO, 'public', 'uploads/2026/08/clip.mp4');

        $this->repository()->save($asset);

        $reloaded = $this->repository()->findById($asset->id());
        $this->assertSame(ProcessingStatus::READY, $reloaded->processingStatus());
        $this->assertSame([], $reloaded->variants());

        // Direct DB check, bypassing the domain-layer read entirely —
        // proves the raw stored JSON is really '[]', not NULL.
        $rawVariants = DB::table('catalog_media')->where('id', $asset->id())->value('variants');
        $this->assertNotNull($rawVariants);
        $this->assertSame([], json_decode($rawVariants, true));
    }

    public function test_update_in_place_round_trips_variants_after_mark_ready_with_multiple_variants(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');
        $this->repository()->save($asset);

        $asset->markProcessing();
        $variants = [
            new MediaVariant('thumbnail', 200, 200, 80, 'uploads/2026/08/photo-thumb.webp'),
            new MediaVariant('medium', 800, 960, 82, 'uploads/2026/08/photo-medium.webp'),
            new MediaVariant('large', 1600, 1920, 85, 'uploads/2026/08/photo-large.webp'),
        ];
        $asset->markReady($variants);
        $this->repository()->save($asset);

        $reloaded = $this->repository()->findById($asset->id());

        $this->assertSame(ProcessingStatus::READY, $reloaded->processingStatus());
        $this->assertCount(3, $reloaded->variants());

        foreach ($variants as $index => $expected) {
            $actual = $reloaded->variants()[$index];
            $this->assertSame($expected->tier, $actual->tier);
            $this->assertSame($expected->width, $actual->width);
            $this->assertSame($expected->height, $actual->height);
            $this->assertSame($expected->quality, $actual->quality);
            $this->assertSame($expected->path, $actual->path);
        }
    }

    public function test_update_does_not_create_a_new_row_and_preserves_the_id(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');
        $this->repository()->save($asset);
        $originalId = $asset->id();

        $asset->updateAltText('Updated alt text');
        $this->repository()->save($asset);

        $this->assertSame($originalId, $asset->id());
        $this->assertSame(1, MediaAssetModel::count());

        $reloaded = $this->repository()->findById($originalId);
        $this->assertSame('Updated alt text', $reloaded->altText());
    }

    public function test_mark_failed_round_trips_the_failure_reason(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');
        $this->repository()->save($asset);

        $asset->markProcessing();
        $asset->markFailed('unsupported format');
        $this->repository()->save($asset);

        $reloaded = $this->repository()->findById($asset->id());

        $this->assertSame(ProcessingStatus::FAILED, $reloaded->processingStatus());
        $this->assertSame('unsupported format', $reloaded->processingFailureReason());
    }
}
