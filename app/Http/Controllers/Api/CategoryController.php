<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\CategoryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for the global, reusable Category set. Deliberately
 * minimal, mirroring AttributeDefinitionController's style: no auth, no
 * form request class, no resource transformer — store()/index() only,
 * no update/delete for now.
 *
 * slug IS TAKEN AS GIVEN, NOT AUTO-GENERATED — same posture as
 * BrandController (see that class's own docblock).
 */
class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryRepository $categories,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
            'parent_id' => 'nullable|string|exists:catalog_categories,id',
        ]);

        $category = new Category(
            id: null,
            parentId: $validated['parent_id'] ?? null,
            name: $validated['name'],
            slug: $validated['slug'],
        );

        $this->categories->save($category);

        return response()->json([
            'id' => $category->id(),
            'parent_id' => $category->parentId(),
            'name' => $category->name(),
            'slug' => $category->slug(),
        ], 201);
    }

    public function index(): JsonResponse
    {
        $categories = array_map(
            static fn (Category $category) => [
                'id' => $category->id(),
                'parent_id' => $category->parentId(),
                'name' => $category->name(),
                'slug' => $category->slug(),
            ],
            $this->categories->all()
        );

        return response()->json($categories);
    }
}
