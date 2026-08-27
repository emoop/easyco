<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for AttributeValue — the enumerable values belonging to a
 * SELECT/MULTISELECT AttributeDefinition. Mirrors
 * AttributeDefinitionController's style exactly: no auth, no form
 * request class, no resource transformer. index() lists values scoped
 * to one definition, not a global list — that's the query shape the
 * upcoming axis-declaration flow actually needs.
 */
class AttributeValueController extends Controller
{
    public function __construct(
        private readonly AttributeValueRepository $values,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attribute_definition_id' => 'required|exists:catalog_attribute_definitions,id',
            'value' => 'required|string|max:255',
            'sort_order' => 'nullable|integer',
        ]);

        $value = new AttributeValue(
            id: null,
            attributeDefinitionId: (string) $validated['attribute_definition_id'],
            value: $validated['value'],
            sortOrder: $validated['sort_order'] ?? 0,
        );

        $this->values->save($value);

        return response()->json([
            'id' => $value->id(),
            'attribute_definition_id' => $value->attributeDefinitionId(),
            'value' => $value->value(),
            'sort_order' => $value->sortOrder(),
        ], 201);
    }

    public function index(string $attributeDefinitionId): JsonResponse
    {
        $values = array_map(
            static fn (AttributeValue $value) => [
                'id' => $value->id(),
                'attribute_definition_id' => $value->attributeDefinitionId(),
                'value' => $value->value(),
                'sort_order' => $value->sortOrder(),
            ],
            $this->values->findByAttributeDefinitionId($attributeDefinitionId)
        );

        return response()->json($values);
    }
}
