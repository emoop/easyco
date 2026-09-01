<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for the global, reusable Tag set. Deliberately minimal,
 * mirroring AttributeDefinitionController's style: no auth, no form
 * request class, no resource transformer — store()/index() only, no
 * update/delete for now.
 *
 * slug IS TAKEN AS GIVEN, NOT AUTO-GENERATED — same posture as
 * BrandController (see that class's own docblock).
 */
class TagController extends Controller
{
    public function __construct(
        private readonly TagRepository $tags,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
        ]);

        $tag = new Tag(
            id: null,
            name: $validated['name'],
            slug: $validated['slug'],
        );

        $this->tags->save($tag);

        return response()->json([
            'id' => $tag->id(),
            'name' => $tag->name(),
            'slug' => $tag->slug(),
        ], 201);
    }

    public function index(): JsonResponse
    {
        $tags = array_map(
            static fn (Tag $tag) => [
                'id' => $tag->id(),
                'name' => $tag->name(),
                'slug' => $tag->slug(),
            ],
            $this->tags->all()
        );

        return response()->json($tags);
    }
}
