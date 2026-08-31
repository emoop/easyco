<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Exceptions\MediaAlreadyAttachedException;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\MediaVariant;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HTTP surface for a Product's attached media — mirrors
 * AttributeValueController's style exactly: no auth, no form request
 * class, no resource transformer. Deliberately its own controller, not
 * shared with VariationMediaController — mirrors ProductMedia's own
 * "each pivot stays the smallest model that satisfies its own need"
 * docblock.
 *
 * Covers attach/list/detach/reorder — see media-domain-design.md
 * §8/§8.5 for the entity shapes and the reorder/detach decisions
 * recorded there.
 */
class ProductMediaController extends Controller
{
    public function __construct(
        private readonly ProductMediaRepository $productMediaRepository,
        private readonly ProductMediaCountGuard $productMediaCountGuard,
        private readonly MediaAssetRepository $mediaAssets,
        private readonly MediaStorageAdapter $storage,
    ) {
    }

    public function store(Request $request, string $productId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);

        $validated = $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
            'media_id' => 'required|exists:catalog_media,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            $this->productMediaCountGuard->assertCanAttach($productId);
        } catch (MediaLimitExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $productMedia = new ProductMedia(
            id: null,
            productId: $productId,
            mediaId: (string) $validated['media_id'],
            sortOrder: $validated['sort_order'] ?? 0,
        );

        try {
            $this->productMediaRepository->save($productMedia);
        } catch (MediaAlreadyAttachedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $productMedia->id(),
            'product_id' => $productMedia->productId(),
            'media_id' => $productMedia->mediaId(),
            'sort_order' => $productMedia->sortOrder(),
        ], 201);
    }

    public function index(Request $request, string $productId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);
        $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
        ]);

        $items = array_map(
            fn (ProductMedia $pivot) => $this->toListItem($pivot->id(), $pivot->mediaId(), $pivot->sortOrder()),
            $this->productMediaRepository->findByProductId($productId)
        );

        return response()->json(['data' => $items]);
    }

    public function destroy(Request $request, string $productId, string $productMediaId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);
        $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
        ]);

        $productMedia = $this->findOwnedPivot($productId, $productMediaId);

        if ($productMedia === null) {
            return response()->json([
                'message' => "No media attachment {$productMediaId} found for product {$productId}.",
            ], 404);
        }

        $this->productMediaRepository->remove($productMediaId);

        return response()->json(null, 204);
    }

    public function reorder(Request $request, string $productId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);
        $validated = $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
            // present, not required: Laravel's `required` rule treats an
            // empty array as "missing," which would wrongly reject the
            // legitimate empty-product/empty-array no-op case.
            'order' => 'present|array',
            'order.*' => 'string',
        ]);

        $current = $this->productMediaRepository->findByProductId($productId);

        $currentIds = array_map(fn (ProductMedia $pivot) => $pivot->id(), $current);
        $providedIds = array_values($validated['order']);

        $sortedCurrentIds = $currentIds;
        $sortedProvidedIds = $providedIds;
        sort($sortedCurrentIds);
        sort($sortedProvidedIds);

        if ($sortedCurrentIds !== $sortedProvidedIds) {
            return response()->json([
                'message' => 'The order array must contain exactly the current set of media attachment ids '.
                    'for this product — no missing, extra, or duplicate ids.',
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
                $this->productMediaRepository->save($pivot);
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
     * Ownership check: a pivot id belonging to a DIFFERENT product must
     * be treated the same as a nonexistent one — never removable just
     * by knowing its id (media-domain-design.md §8.5).
     */
    private function findOwnedPivot(string $productId, string $productMediaId): ?ProductMedia
    {
        foreach ($this->productMediaRepository->findByProductId($productId) as $pivot) {
            if ($pivot->id() === $productMediaId) {
                return $pivot;
            }
        }

        return null;
    }
}
