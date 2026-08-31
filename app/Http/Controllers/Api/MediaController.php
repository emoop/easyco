<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaImageProcessor;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Image;

/**
 * Standalone media upload endpoint — creates a MediaAsset and, for
 * images only, dispatches processing. Deliberately does NOT attach the
 * asset to any Product/Variation; that's a separate, not-yet-built
 * endpoint. Mirrors ProductController's minimal style: no auth, no
 * form request class, inline $request->validate().
 */
class MediaController extends Controller
{
    public function __construct(
        private readonly MediaAssetRepository $mediaAssets,
        private readonly MediaStorageAdapter $storage,
        private readonly MediaImageProcessor $imageProcessor,
        private readonly int $maxImageSizeKb,
        private readonly int $maxVideoSizeKb,
        private readonly int $minImageDimensionPx,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // No mimes: whitelist here — format support is checked by a
            // real decode attempt below, never assumed from
            // extension/MIME (media-domain-design.md §3.1).
            'file' => 'required|file',
            'alt_text' => 'nullable|string|max:255',
        ]);

        $file = $validated['file'];

        // getMimeType() sniffs the actual file content (magic bytes),
        // not the client-supplied Content-Type header — the same
        // "never assumed" posture §3.1 requires for format support.
        $mimeType = $file->getMimeType();
        $topLevelType = strtok((string) $mimeType, '/');

        if (! in_array($topLevelType, ['image', 'video'], true)) {
            return response()->json([
                'message' => "Unsupported media type \"{$mimeType}\" — only image/* and video/* are accepted.",
            ], 422);
        }

        $sizeKb = (int) ceil($file->getSize() / 1024);
        $maxSizeKb = $topLevelType === 'image' ? $this->maxImageSizeKb : $this->maxVideoSizeKb;

        if ($sizeKb > $maxSizeKb) {
            return response()->json([
                'message' => "File is too large ({$sizeKb} KB) — the maximum for {$topLevelType} uploads is {$maxSizeKb} KB.",
            ], 422);
        }

        $content = $file->getContent();

        if ($topLevelType === 'image') {
            // Reuses the exact decode attempt LaravelMediaImageProcessor
            // itself makes internally, rather than re-implementing it
            // here — see that method's own docblock (§3.1: there is no
            // capability-query method, only "attempt a decode and see
            // what happens").
            if (! $this->imageProcessor->supportsFormat($content)) {
                return response()->json([
                    'message' => 'Unsupported image format — this server may lack ImageMagick, required for AVIF/HEIC (see media-domain-design.md §3.1).',
                ], 422);
            }

            // supportsFormat() only reports whether decoding succeeded;
            // it doesn't hand back the decoded image, so reading real
            // dimensions needs its own decode here.
            $oriented = Image::fromBytes($content)->orient();
            $width = $oriented->width();
            $height = $oriented->height();

            if ($width < $this->minImageDimensionPx || $height < $this->minImageDimensionPx) {
                return response()->json([
                    'message' => "Image is too small ({$width}x{$height}px) — both dimensions must be at least {$this->minImageDimensionPx}px.",
                ], 422);
            }
        }

        // §4: no dimension check exists, or is planned, for video.

        $type = $topLevelType === 'image' ? MediaType::IMAGE : MediaType::VIDEO;

        $storedFile = $this->storage->store($content, $file->getClientOriginalName());

        $asset = MediaAsset::create($type, $storedFile->disk, $storedFile->path, $validated['alt_text'] ?? null);
        $this->mediaAssets->save($asset);

        // §3.6: dispatch ONLY for images. ProcessMediaAssetJob calls
        // markProcessing() before its own try/catch, and markProcessing()
        // unconditionally rejects VIDEO/SOCIAL_VIDEO
        // (InvalidMediaStateTransitionException, uncaught by the job) —
        // dispatching for video would crash a queue worker, not merely
        // waste one. See media-domain-design.md §3.6.
        if ($type === MediaType::IMAGE) {
            ProcessMediaAssetJob::dispatch($asset->id());
        }

        return response()->json([
            'id' => $asset->id(),
            'type' => $asset->type()->value,
            'disk' => $asset->disk(),
            'path' => $asset->path(),
            'url' => $this->storage->url($asset->disk(), $asset->path()),
            'processing_status' => $asset->processingStatus()->value,
            'alt_text' => $asset->altText(),
        ], 201);
    }
}
