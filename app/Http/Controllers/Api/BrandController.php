<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for the global, reusable Brand set. Deliberately minimal,
 * mirroring AttributeDefinitionController's style: no auth, no form
 * request class, no resource transformer — store()/index() only, no
 * update/delete for now.
 *
 * slug IS TAKEN AS GIVEN, NOT AUTO-GENERATED — unlike ProductController's
 * Hook::apply('catalog.product.slug', ...), which is a specific,
 * deliberate Product feature. Brand takes `slug` the same way
 * AttributeDefinition takes `code`: as given by the caller.
 */
class BrandController extends Controller
{
    public function __construct(
        private readonly BrandRepository $brands,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
        ]);

        $brand = new Brand(
            id: null,
            name: $validated['name'],
            slug: $validated['slug'],
        );

        $this->brands->save($brand);

        return response()->json([
            'id' => $brand->id(),
            'name' => $brand->name(),
            'slug' => $brand->slug(),
        ], 201);
    }

    public function index(): JsonResponse
    {
        $brands = array_map(
            static fn (Brand $brand) => [
                'id' => $brand->id(),
                'name' => $brand->name(),
                'slug' => $brand->slug(),
            ],
            $this->brands->all()
        );

        return response()->json($brands);
    }
}
