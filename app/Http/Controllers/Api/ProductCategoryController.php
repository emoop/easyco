<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Exceptions\CategoryAlreadyAssignedException;
use EasyCo\Catalog\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for a Product's assigned Categories — mirrors
 * ProductMediaController's style exactly, minus reorder() and any
 * sort_order concern: no ordering concept exists for category
 * assignment (fcdfd77's ProductCategory/EloquentProductCategoryRepository
 * docblocks).
 */
class ProductCategoryController extends Controller
{
    public function __construct(
        private readonly ProductCategoryRepository $productCategories,
        private readonly CategoryRepository $categories,
    ) {
    }

    public function store(Request $request, string $productId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);

        $validated = $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
            'category_id' => 'required|string|exists:catalog_categories,id',
        ]);

        $productCategory = new ProductCategory(
            id: null,
            productId: $productId,
            categoryId: (string) $validated['category_id'],
        );

        try {
            $this->productCategories->save($productCategory);
        } catch (CategoryAlreadyAssignedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $productCategory->id(),
            'product_id' => $productCategory->productId(),
            'category_id' => $productCategory->categoryId(),
        ], 201);
    }

    public function index(Request $request, string $productId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);
        $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
        ]);

        $items = array_map(
            fn (ProductCategory $pivot) => $this->toListItem($pivot),
            $this->productCategories->findByProductId($productId)
        );

        return response()->json(['data' => $items]);
    }

    public function destroy(Request $request, string $productId, string $categoryId): JsonResponse
    {
        $request->merge(['product_id' => $productId]);
        $request->validate([
            'product_id' => 'required|exists:catalog_products,id',
        ]);

        $pivot = $this->findOwnedPivot($productId, $categoryId);

        if ($pivot === null) {
            return response()->json([
                'message' => "No category assignment for category {$categoryId} found on product {$productId}.",
            ], 404);
        }

        $this->productCategories->remove($pivot->id());

        return response()->json(null, 204);
    }

    /**
     * FK on category_id (2026_08_23_000013_create_catalog_product_taxonomy_pivots.php)
     * guarantees the paired Category exists — trusted, not defensively
     * null-checked; a null here would be a real data-integrity bug, not
     * a case to silently skip.
     */
    private function toListItem(ProductCategory $pivot): array
    {
        $category = $this->categories->findById($pivot->categoryId());

        return [
            'id' => $pivot->id(),
            'category_id' => $category->id(),
            'name' => $category->name(),
            'slug' => $category->slug(),
        ];
    }

    /**
     * Ownership check: a category_id assigned to a DIFFERENT product must
     * be treated the same as one never assigned to this product at all
     * — never removable just by knowing the category id. Mirrors
     * ProductMediaController::findOwnedPivot() exactly.
     */
    private function findOwnedPivot(string $productId, string $categoryId): ?ProductCategory
    {
        foreach ($this->productCategories->findByProductId($productId) as $pivot) {
            if ($pivot->categoryId() === $categoryId) {
                return $pivot;
            }
        }

        return null;
    }
}
