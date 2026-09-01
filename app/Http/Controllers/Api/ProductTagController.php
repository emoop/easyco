<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Exceptions\TagAlreadyAssignedException;
use EasyCo\Catalog\ProductTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for a Product's assigned Tags — mirrors
 * ProductMediaController's style exactly, minus reorder() and any
 * sort_order concern: no ordering concept exists for tag assignment
 * (fcdfd77's ProductTag/EloquentProductTagRepository docblocks).
 */
class ProductTagController extends Controller
{
    public function __construct(
        private readonly ProductTagRepository $productTags,
        private readonly TagRepository $tags,
    ) {
    }

    public function store(Request $request, string $productId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);

        $validated = $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
            'tag_id' => 'required|string|exists:catalog_tags,id',
        ]);

        $productTag = new ProductTag(
            id: null,
            productId: $productId,
            tagId: (string) $validated['tag_id'],
        );

        try {
            $this->productTags->save($productTag);
        } catch (TagAlreadyAssignedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $productTag->id(),
            'product_id' => $productTag->productId(),
            'tag_id' => $productTag->tagId(),
        ], 201);
    }

    public function index(Request $request, string $productId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);
        $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
        ]);

        $items = array_map(
            fn (ProductTag $pivot) => $this->toListItem($pivot),
            $this->productTags->findByProductId($productId)
        );

        return response()->json(['data' => $items]);
    }

    public function destroy(Request $request, string $productId, string $tagId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);
        $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
        ]);

        $pivot = $this->findOwnedPivot($productId, $tagId);

        if ($pivot === null) {
            return response()->json([
                'message' => "No tag assignment for tag {$tagId} found on product {$productId}.",
            ], 404);
        }

        $this->productTags->remove($pivot->id());

        return response()->json(null, 204);
    }

    /**
     * FK on tag_id (2026_08_23_000013_create_catalog_product_taxonomy_pivots.php)
     * guarantees the paired Tag exists — trusted, not defensively
     * null-checked; a null here would be a real data-integrity bug, not
     * a case to silently skip.
     */
    private function toListItem(ProductTag $pivot): array
    {
        $tag = $this->tags->findById($pivot->tagId());

        return [
            'id' => $pivot->id(),
            'tag_id' => $tag->id(),
            'name' => $tag->name(),
            'slug' => $tag->slug(),
        ];
    }

    /**
     * Ownership check: a tag_id assigned to a DIFFERENT product must be
     * treated the same as one never assigned to this product at all —
     * never removable just by knowing the tag id. Mirrors
     * ProductMediaController::findOwnedPivot() exactly.
     */
    private function findOwnedPivot(string $productId, string $tagId): ?ProductTag
    {
        foreach ($this->productTags->findByProductId($productId) as $pivot) {
            if ($pivot->tagId() === $tagId) {
                return $pivot;
            }
        }

        return null;
    }
}
