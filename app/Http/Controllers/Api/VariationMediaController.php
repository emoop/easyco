<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Exceptions\MediaAlreadyAttachedException;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\VariationMedia;
use EasyCo\Media\VariationMediaCountGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for attaching a MediaAsset to a Variation — mirrors
 * ProductMediaController exactly, but deliberately its own controller,
 * not shared — mirrors VariationMedia's own "each pivot stays the
 * smallest model that satisfies its own need" docblock.
 *
 * Attach-only: no list/detach/reorder endpoint here — see
 * media-domain-design.md §8, these are real, expected next gaps, not
 * oversights.
 */
class VariationMediaController extends Controller
{
    public function __construct(
        private readonly VariationMediaRepository $variationMediaRepository,
        private readonly VariationMediaCountGuard $variationMediaCountGuard,
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
}
