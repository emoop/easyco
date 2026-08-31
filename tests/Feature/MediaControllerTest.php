<?php

namespace Tests\Feature;

use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;
use Tests\TestCase;

class MediaControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
    }

    /**
     * Real JPEG magic bytes (SOI marker) followed by garbage — passes a
     * MIME-type check (real content-sniffed in production, extension-based
     * for Illuminate\Http\Testing\File in tests — see the test class
     * docblock note below) but genuinely fails to decode via
     * Illuminate\Image, confirmed manually before writing this test:
     * a text-file-renamed-to-.jpg does NOT work for this, because a real
     * content sniff correctly reports text/plain and gets rejected at the
     * MIME-type gate instead of ever reaching the decode step.
     */
    private function corruptJpegContent(): string
    {
        return "\xFF\xD8\xFF\xE0".str_repeat('garbage-not-real-jpeg-data', 20);
    }

    private function extractAssetId(ProcessMediaAssetJob $job): string
    {
        $property = new ReflectionProperty($job, 'mediaAssetId');
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    public function test_happy_path_image_upload_creates_a_pending_asset_and_dispatches_processing(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 800, 800);

        $response = $this->postJson('/api/media', ['file' => $file]);

        $response->assertStatus(201);
        $response->assertJsonPath('type', 'image');
        $response->assertJsonPath('processing_status', 'pending');

        $assetId = $response->json('id');
        $this->assertSame(1, MediaAssetModel::count());
        $this->assertSame(ProcessingStatus::PENDING->value, MediaAssetModel::first()->processing_status);

        Queue::assertPushed(
            ProcessMediaAssetJob::class,
            fn (ProcessMediaAssetJob $job) => $this->extractAssetId($job) === (string) $assetId
        );
    }

    public function test_happy_path_video_upload_is_ready_immediately_and_does_not_dispatch_processing(): void
    {
        $file = UploadedFile::fake()->create('clip.mp4', 5000, 'video/mp4');

        $response = $this->postJson('/api/media', ['file' => $file]);

        $response->assertStatus(201);
        $response->assertJsonPath('type', 'video');
        $response->assertJsonPath('processing_status', 'ready');

        $this->assertSame(1, MediaAssetModel::count());

        Queue::assertNotPushed(ProcessMediaAssetJob::class);
    }

    public function test_image_below_minimum_dimension_is_rejected_and_creates_no_asset(): void
    {
        $file = UploadedFile::fake()->image('small.jpg', 300, 300);

        $response = $this->postJson('/api/media', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertSame(0, MediaAssetModel::count());
        Queue::assertNotPushed(ProcessMediaAssetJob::class);
    }

    public function test_image_over_the_size_cap_is_rejected_and_creates_no_asset(): void
    {
        $file = UploadedFile::fake()->image('big.jpg', 800, 800)->size(10240 + 1);

        $response = $this->postJson('/api/media', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertSame(0, MediaAssetModel::count());
    }

    public function test_video_over_the_size_cap_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('clip.mp4', 102400 + 1, 'video/mp4');

        $response = $this->postJson('/api/media', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertSame(0, MediaAssetModel::count());
    }

    public function test_a_non_image_non_video_file_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/media', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertSame(0, MediaAssetModel::count());
    }

    public function test_an_undecodable_image_is_rejected_distinctly_from_the_too_small_case(): void
    {
        $file = UploadedFile::fake()->createWithContent('corrupt.jpg', $this->corruptJpegContent());

        $response = $this->postJson('/api/media', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertSame(0, MediaAssetModel::count());

        $message = $response->json('message');
        $this->assertStringContainsString('format', $message);
        $this->assertStringNotContainsString('too small', $message);
    }
}
