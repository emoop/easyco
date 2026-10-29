<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeDisplayType;
use EasyCo\Catalog\Enums\AttributeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HTTP surface for the global, reusable AttributeDefinition set
 * (catalog-domain-design.md §3.3). Deliberately minimal, mirroring
 * ProductController's style: no auth, no form request class, no resource
 * transformer — definitions are global and small in number, so index()
 * needs no pagination yet.
 */
class AttributeDefinitionController extends Controller
{
    public function __construct(
        private readonly AttributeDefinitionRepository $definitions,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'type' => 'required|in:text,number,boolean,select,multiselect',
            // AT-1: how its values are shown (default dropdown)
            'display_type' => ['nullable', Rule::enum(AttributeDisplayType::class)],
        ]);

        $definition = new AttributeDefinition(
            id: null,
            code: $validated['code'],
            name: $validated['name'],
            type: AttributeType::from($validated['type']),
        );

        isset($validated['display_type']) && $definition->setDisplayType($validated['display_type']);

        $this->definitions->save($definition);

        return response()->json([
            'id' => $definition->id(),
            'code' => $definition->code(),
            'name' => $definition->name(),
            'type' => $definition->type()->value,
            'display_type' => $definition->displayType()->value,
        ], 201);
    }

    public function index(): JsonResponse
    {
        $definitions = array_map(
            static fn (AttributeDefinition $definition) => [
                'id' => $definition->id(),
                'code' => $definition->code(),
                'name' => $definition->name(),
                'type' => $definition->type()->value,
                'display_type' => $definition->displayType()->value,
            ],
            $this->definitions->all()
        );

        return response()->json($definitions);
    }
}
