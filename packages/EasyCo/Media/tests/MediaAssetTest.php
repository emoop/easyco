<?php

namespace EasyCo\Media\Tests;

use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\Exceptions\InvalidMediaStateTransitionException;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\MediaVariant;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaAssetTest extends TestCase
{
    // --- create() -------------------------------------------------------

    #[DataProvider('imageTypes')]
    public function test_create_with_an_image_type_starts_pending_with_no_variants(MediaType $type): void
    {
        $asset = MediaAsset::create($type, 'public', 'uploads/2026/08/photo.jpg');

        $this->assertNull($asset->id());
        $this->assertSame($type, $asset->type());
        $this->assertSame(ProcessingStatus::PENDING, $asset->processingStatus());
        $this->assertSame([], $asset->variants());
        $this->assertFalse($asset->isReady());
    }

    #[DataProvider('videoTypes')]
    public function test_create_with_a_video_type_starts_ready_with_no_variants(MediaType $type): void
    {
        $asset = MediaAsset::create($type, 'public', 'uploads/2026/08/clip.mp4');

        $this->assertSame($type, $asset->type());
        $this->assertSame(ProcessingStatus::READY, $asset->processingStatus());
        $this->assertSame([], $asset->variants());
        $this->assertTrue($asset->isReady());
    }

    public function test_empty_path_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MediaAsset::create(MediaType::IMAGE, 'public', '');
    }

    public static function imageTypes(): array
    {
        return [
            'IMAGE' => [MediaType::IMAGE],
            'SOCIAL_IMAGE' => [MediaType::SOCIAL_IMAGE],
        ];
    }

    public static function videoTypes(): array
    {
        return [
            'VIDEO' => [MediaType::VIDEO],
            'SOCIAL_VIDEO' => [MediaType::SOCIAL_VIDEO],
        ];
    }

    // --- markProcessing() -------------------------------------------------

    public function test_mark_processing_from_pending_works(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');

        $asset->markProcessing();

        $this->assertSame(ProcessingStatus::PROCESSING, $asset->processingStatus());
    }

    public function test_mark_processing_from_processing_throws(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');
        $asset->markProcessing();

        $this->expectException(InvalidMediaStateTransitionException::class);
        $asset->markProcessing();
    }

    public function test_mark_processing_from_ready_throws(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');
        $asset->markProcessing();
        $asset->markReady([]);

        $this->expectException(InvalidMediaStateTransitionException::class);
        $asset->markProcessing();
    }

    public function test_mark_processing_from_failed_throws(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');
        $asset->markProcessing();
        $asset->markFailed('corrupt upload');

        $this->expectException(InvalidMediaStateTransitionException::class);
        $asset->markProcessing();
    }

    // --- markReady() ------------------------------------------------------

    public function test_mark_ready_from_processing_works_and_sets_variants(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');
        $asset->markProcessing();

        $variants = [
            new MediaVariant('thumbnail', 200, 200, 80, 'uploads/2026/08/photo-thumb.webp'),
            new MediaVariant('large', 1600, 1920, 85, 'uploads/2026/08/photo-large.webp'),
        ];
        $asset->markReady($variants);

        $this->assertSame(ProcessingStatus::READY, $asset->processingStatus());
        $this->assertSame($variants, $asset->variants());
        $this->assertTrue($asset->isReady());
    }

    public function test_mark_ready_from_pending_without_mark_processing_first_throws(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');

        $this->expectException(InvalidMediaStateTransitionException::class);
        $asset->markReady([]);
    }

    // --- markFailed() -----------------------------------------------------

    public function test_mark_failed_from_processing_works_and_sets_reason(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');
        $asset->markProcessing();

        $asset->markFailed('unsupported format');

        $this->assertSame(ProcessingStatus::FAILED, $asset->processingStatus());
        $this->assertSame('unsupported format', $asset->processingFailureReason());
    }

    public function test_mark_ready_after_mark_failed_clears_the_failure_reason(): void
    {
        // A retry attempt: markProcessing() only accepts PENDING -> PROCESSING
        // (no domain method moves FAILED back to PROCESSING — that requeue
        // decision lives outside this class, at the persistence/application
        // layer), so this simulates the row a retried job would actually see:
        // reconstituted straight into PROCESSING while still carrying the
        // previous run's failure reason, exactly like a real repository
        // would hand back to a retry job.
        $asset = MediaAsset::reconstituteFromStorage(
            id: '9',
            type: MediaType::IMAGE,
            disk: 'public',
            path: 'uploads/2026/08/photo.jpg',
            altText: null,
            processingStatus: ProcessingStatus::PROCESSING,
            processingFailureReason: 'transient storage error',
            variants: [],
        );

        $asset->markReady([]);

        $this->assertNull($asset->processingFailureReason());
        $this->assertSame(ProcessingStatus::READY, $asset->processingStatus());
    }

    // --- VIDEO/SOCIAL_VIDEO transition rejection ---------------------------

    #[DataProvider('videoTypes')]
    public function test_mark_processing_on_a_video_asset_throws_immediately(MediaType $type): void
    {
        $asset = MediaAsset::create($type, 'public', 'uploads/2026/08/clip.mp4');

        $this->expectException(InvalidMediaStateTransitionException::class);
        $asset->markProcessing();
    }

    #[DataProvider('videoTypes')]
    public function test_mark_ready_on_a_video_asset_throws_immediately(MediaType $type): void
    {
        $asset = MediaAsset::create($type, 'public', 'uploads/2026/08/clip.mp4');

        $this->expectException(InvalidMediaStateTransitionException::class);
        $asset->markReady([]);
    }

    #[DataProvider('videoTypes')]
    public function test_mark_failed_on_a_video_asset_throws_immediately(MediaType $type): void
    {
        $asset = MediaAsset::create($type, 'public', 'uploads/2026/08/clip.mp4');

        $this->expectException(InvalidMediaStateTransitionException::class);
        $asset->markFailed('irrelevant');
    }

    // --- updateAltText() ----------------------------------------------------

    public function test_update_alt_text_works_freely(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg', 'Original alt text');

        $asset->updateAltText('New alt text');
        $this->assertSame('New alt text', $asset->altText());

        $asset->updateAltText(null);
        $this->assertNull($asset->altText());
    }

    // --- assignId() -------------------------------------------------------

    public function test_id_can_only_be_assigned_once(): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', 'uploads/2026/08/photo.jpg');
        $asset->assignId('1');

        $this->assertSame('1', $asset->id());

        $this->expectException(LogicException::class);
        $asset->assignId('2');
    }

    // --- reconstituteFromStorage() -----------------------------------------

    public function test_reconstitute_from_storage_round_trips_all_fields_including_variants(): void
    {
        $variants = [
            new MediaVariant('thumbnail', 200, 200, 80, 'uploads/2026/08/photo-thumb.webp'),
            new MediaVariant('medium', 800, 960, 82, 'uploads/2026/08/photo-medium.webp'),
        ];

        $asset = MediaAsset::reconstituteFromStorage(
            id: '5',
            type: MediaType::SOCIAL_IMAGE,
            disk: 's3',
            path: 'uploads/2026/08/photo.jpg',
            altText: 'A repurposed Instagram photo',
            processingStatus: ProcessingStatus::READY,
            processingFailureReason: null,
            variants: $variants,
        );

        $this->assertSame('5', $asset->id());
        $this->assertSame(MediaType::SOCIAL_IMAGE, $asset->type());
        $this->assertSame('s3', $asset->disk());
        $this->assertSame('uploads/2026/08/photo.jpg', $asset->path());
        $this->assertSame('A repurposed Instagram photo', $asset->altText());
        $this->assertSame(ProcessingStatus::READY, $asset->processingStatus());
        $this->assertNull($asset->processingFailureReason());
        $this->assertSame($variants, $asset->variants());
    }

    public function test_reconstitute_from_storage_round_trips_a_failed_asset_with_its_reason(): void
    {
        $asset = MediaAsset::reconstituteFromStorage(
            id: '6',
            type: MediaType::IMAGE,
            disk: 'public',
            path: 'uploads/2026/08/broken.jpg',
            altText: null,
            processingStatus: ProcessingStatus::FAILED,
            processingFailureReason: 'unsupported format',
            variants: [],
        );

        $this->assertSame(ProcessingStatus::FAILED, $asset->processingStatus());
        $this->assertSame('unsupported format', $asset->processingFailureReason());
        $this->assertSame([], $asset->variants());
    }
}
