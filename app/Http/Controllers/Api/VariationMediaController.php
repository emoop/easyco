<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Exceptions\MediaAlreadyAttachedException;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\MediaVariant;
use EasyCo\Media\VariationMedia;
use EasyCo\Media\VariationMediaCountGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HTTP surface for a Variation's attached media — mirrors
 * ProductMediaController exactly, but deliberately its own controller,
 * not shared — mirrors VariationMedia's own "each pivot stays the
 * smallest model that satisfies its own need" docblock.
 *
 * Covers attach/list/detach/reorder — see media-domain-design.md
 * §8/§8.5 for the entity shapes and the reorder/detach decisions
 * recorded there.
 */
class VariationMediaController extends Controller
{
    public function __construct(
        private readonly VariationMediaRepository $variationMediaRepository,
        private readonly VariationMediaCountGuard $variationMediaCountGuard,
        private readonly MediaAssetRepository $mediaAssets,
        private readonly MediaStorageAdapter $storage,
    ) {
    }

    public function store(Request $request, string $variationId): JsonResponse
    {
        $request->merge(['variation_id' => $variationId]);

        $validated = $request->validate([
            'variation_id' => 'required|exists:catalog_variations,id',
            'media_id' => 'required|exists:catalog_media,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            $this->variationMediaCountGuard->assertCanAttach($variationId);
        } catch (MediaLimitExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $variationMedia = new VariationMedia(
            id: null,
            variationId: $variationId,
            mediaId: (string) $validated['media_id'],
            sortOrder: $validated['sort_order'] ?? 0,
        );

        try {
            $this->variationMediaRepository->save($variationMedia);
        } catch (MediaAlreadyAttachedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $variationMedia->id(),
            'variation_id' => $variationMedia->variationId(),
            'media_id' => $variationMedia->mediaId(),
            'sort_order' => $variationMedia->sortOrder(),
        ], 201);
    }

    public function index(Request $request, string $variationId): JsonResponse
    {
        $request->merge(['variation_id' => $variationId]);
        $request->validate([
            'variation_id' => 'required|exists:catalog_variations,id',
        ]);

        $items = array_map(
            fn (VariationMedia $pivot) => $this->toListItem($pivot->id(), $pivot->mediaId(), $pivot->sortOrder()),
            $this->variationMediaRepository->findByVariationId($variationId)
        );

        return response()->json(['data' => $items]);
    }

    public function destroy(Request $request, string $variationId, string $variationMediaId): JsonResponse
    {
        $request->merge(['variation_id' => $variationId]);
        $request->validate([
            'variation_id' => 'required|exists:catalog_variations,id',
        ]);

        $variationMedia = $this->findOwnedPivot($variationId, $variationMediaId);

        if ($variationMedia === null) {
            return response()->json([
                'message' => "No media attachment {$variationMediaId} found for variation {$variationId}.",
            ], 404);
        }

        $this->variationMediaRepository->remove($variationMediaId);

        return response()->json(null, 204);
    }

    public function reorder(Request $request, string $variationId): JsonResponse
    {
        $request->merge(['variation_id' => $variationId]);
        $validated = $request->validate([
            'variation_id' => 'required|exists:catalog_variations,id',
            // present, not required: Laravel's `required` rule treats an
            // empty array as "missing," which would wrongly reject the
            // legitimate empty-variation/empty-array no-op case.
            'order' => 'present|array',
            'order.*' => 'string',
        ]);

        $current = $this->variationMediaRepository->findByVariationId($variationId);

        $currentIds = array_map(fn (VariationMedia $pivot) => $pivot->id(), $current);
        $providedIds = array_values($validated['order']);

        $sortedCurrentIds = $currentIds;
        $sortedProvidedIds = $providedIds;
        sort($sortedCurrentIds);
        sort($sortedProvidedIds);

        if ($sortedCurrentIds !== $sortedProvidedIds) {
            return response()->json([
                'message' => 'The order array must contain exactly the current set of media attachment ids '.
                    'for this variation — no missing, extra, or duplicate ids.',
            ], 422);
        }

        $pivotsById = [];
        foreach ($current as $pivot) {
            $pivotsById[$pivot->id()] = $pivot;
        }

        DB::transaction(function () use ($providedIds, $pivotsById): void {
            foreach ($providedIds as $index => $id) {
                $pivot = $pivotsById[$id];
                $pivot->updateSortOrder($index);
                $this->variationMediaRepository->save($pivot);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }

    /**
     * FK on media_id (2026_08_23_000012_create_catalog_media_tables.php)
     * guarantees the paired MediaAsset exists — trusted, not
     * defensively null-checked; a null here would be a real
     * data-integrity bug, not a case to silently skip.
     */
    private function toListItem(string $pivotId, string $mediaId, int $sortOrder): array
    {
        $asset = $this->mediaAssets->findById($mediaId);

        return [
            'id' => $pivotId,
            'media_id' => $asset->id(),
            'sort_order' => $sortOrder,
            'type' => $asset->type()->value,
            'processing_status' => $asset->processingStatus()->value,
            'alt_text' => $asset->altText(),
            'url' => $this->storage->url($asset->disk(), $asset->path()),
            'variants' => collect($asset->variants())
                ->mapWithKeys(fn (MediaVariant $variant) => [$variant->tier => $this->storage->url($asset->disk(), $variant->path)])
                ->all(),
        ];
    }

    /**
     * Ownership check: a pivot id belonging to a DIFFERENT variation
     * must be treated the same as a nonexistent one — never removable
     * just by knowing its id (media-domain-design.md §8.5).
     */
    private function findOwnedPivot(string $variationId, string $variationMediaId): ?VariationMedia
    {
        foreach ($this->variationMediaRepository->findByVariationId($variationId) as $pivot) {
            if ($pivot->id() === $variationMediaId) {
                return $pivot;
            }
        }

        return null;
    }
}
