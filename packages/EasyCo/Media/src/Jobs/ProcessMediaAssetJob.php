<?php

namespace EasyCo\Media\Jobs;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaImageProcessor;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs the image-processing pipeline (§3) for one MediaAsset —
 * queued, not synchronous during the upload request, per §3.4.
 *
 * Constructor takes only the asset's id, not the MediaAsset object
 * itself — standard Laravel practice for queued jobs: the id is
 * serialized onto the queue, and the asset is reloaded fresh from
 * storage when the job actually runs, rather than serializing a
 * possibly-stale in-memory entity.
 */
class ProcessMediaAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $mediaAssetId,
    ) {
    }

    public function handle(MediaAssetRepository $assets, MediaImageProcessor $processor, MediaStorageAdapter $storage): void
    {
        $asset = $assets->findById($this->mediaAssetId);

        if ($asset === null) {
            // Deleted before this job ran — not an error.
            return;
        }

        $asset->markProcessing();
        $assets->save($asset);

        try {
            $sourceContent = $storage->get($asset->disk(), $asset->path());
            $variants = $processor->generateVariants($sourceContent, $asset->disk(), $asset->path());

            $asset->markReady($variants);
        } catch (Throwable $e) {
            // Catches Throwable, not just MediaProcessingException: an
            // asset stuck forever in PROCESSING after an unexpected
            // error (a storage read failure, an unhandled driver error,
            // anything not already wrapped by the processor) is exactly
            // the "invisible broken upload" problem §3 exists to avoid
            // — silently missing thumbnails forever, with no visible
            // failure state. Any error here must still resolve to
            // FAILED with a reason. Any partial variants the processor
            // itself wrote have already been cleaned up inside it
            // (§3.5) — this job does not duplicate that cleanup.
            $asset->markFailed($e->getMessage());
        }

        $assets->save($asset);
    }
}
