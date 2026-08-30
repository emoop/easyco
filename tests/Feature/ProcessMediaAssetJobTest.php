<?php

namespace Tests\Feature;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaImageProcessor;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\Exceptions\MediaProcessingException;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\MediaVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;
use Tests\TestCase;

class ProcessMediaAssetJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function repository(): MediaAssetRepository
    {
        return app(MediaAssetRepository::class);
    }

    private function createPendingAsset(string $path = 'products/original.jpg'): MediaAsset
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', $path);
        $this->repository()->save($asset);

        // The job reads this via MediaStorageAdapter::get() before
        // handing it to the processor — content doesn't need to be a
        // real image since FakeMediaImageProcessor never decodes it.
        Storage::disk('public')->put($path, 'raw source bytes');

        return $asset;
    }

    public function test_successful_path_marks_the_asset_ready_with_variants_persisted(): void
    {
        $asset = $this->createPendingAsset();

        $variants = [
            new MediaVariant('thumbnail', 400, 400, 80, 'products/original-thumbnail.webp'),
            new MediaVariant('admin_grid', 42, 42, 80, 'products/original-admin_grid.webp'),
        ];
        $this->app->instance(MediaImageProcessor::class, new FakeMediaImageProcessor(variants: $variants));

        app()->call([new ProcessMediaAssetJob($asset->id()), 'handle']);

        $reloaded = $this->repository()->findById($asset->id());

        $this->assertSame(ProcessingStatus::READY, $reloaded->processingStatus());
        $this->assertCount(2, $reloaded->variants());
        $this->assertSame('thumbnail', $reloaded->variants()[0]->tier);
        $this->assertSame('admin_grid', $reloaded->variants()[1]->tier);
        $this->assertNull($reloaded->processingFailureReason());
    }

    public function test_failed_path_marks_the_asset_failed_with_the_reason(): void
    {
        $asset = $this->createPendingAsset();

        $this->app->instance(
            MediaImageProcessor::class,
            new FakeMediaImageProcessor(throws: MediaProcessingException::unsupportedFormat())
        );

        app()->call([new ProcessMediaAssetJob($asset->id()), 'handle']);

        $reloaded = $this->repository()->findById($asset->id());

        $this->assertSame(ProcessingStatus::FAILED, $reloaded->processingStatus());
        $this->assertStringContainsString('ImageMagick', $reloaded->processingFailureReason());
    }

    public function test_missing_asset_returns_silently_without_throwing(): void
    {
        $this->expectNotToPerformAssertions();

        app()->call([new ProcessMediaAssetJob('999999'), 'handle']);
    }

    public function test_job_is_dispatchable_and_carries_the_correct_asset_id(): void
    {
        Queue::fake();

        $asset = $this->createPendingAsset();

        ProcessMediaAssetJob::dispatch($asset->id());

        Queue::assertPushed(
            ProcessMediaAssetJob::class,
            fn (ProcessMediaAssetJob $job) => $this->extractAssetId($job) === $asset->id()
        );
    }

    private function extractAssetId(ProcessMediaAssetJob $job): string
    {
        $property = new ReflectionProperty($job, 'mediaAssetId');
        $property->setAccessible(true);

        return $property->getValue($job);
    }
}
