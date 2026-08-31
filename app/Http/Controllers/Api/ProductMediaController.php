<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Exceptions\MediaAlreadyAttachedException;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for attaching a MediaAsset to a Product — mirrors
 * AttributeValueController's style exactly: no auth, no form request
 * class, no resource transformer. Deliberately its own controller, not
 * shared with VariationMediaController — mirrors ProductMedia's own
 * "each pivot stays the smallest model that satisfies its own need"
 * docblock.
 *
 * Attach-only: no list/detach/reorder endpoint here — see
 * media-domain-design.md §8, these are real, expected next gaps, not
 * oversights.
 */
class ProductMediaController extends Controller
{
    public function __construct(
        private readonly ProductMediaRepository $productMediaRepository,
        private readonly ProductMediaCountGuard $productMediaCountGuard,
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
}
