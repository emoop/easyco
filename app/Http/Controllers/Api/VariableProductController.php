<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Providers\CatalogSkuGeneratorServiceProvider;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Services\VariationCombinationGenerator;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\Hook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for creating a VARIABLE product with its variation axes
 * declared and all combinations generated in one request — the final
 * step of the VARIABLE-product HTTP chain (AttributeDefinition ->
 * AttributeValue -> this). Kept as its own controller rather than a
 * second method on ProductController: the request shape (nested axes,
 * two extra repositories, the combination generator) is materially
 * different from SIMPLE creation, the same reasoning that already gave
 * AttributeDefinition/AttributeValue their own controllers instead of
 * folding into ProductController.
 *
 * Deliberately minimal, mirroring ProductController's style: no auth, no
 * form request class, no resource transformer.
 */
class VariableProductController extends Controller
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly AttributeDefinitionRepository $attributeDefinitions,
        private readonly AttributeValueRepository $attributeValues,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'base_sku' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255',
            'axes' => 'required|array|min:1',
            'axes.*.attribute_definition_id' => 'required|exists:catalog_attribute_definitions,id',
            'axes.*.value_ids' => 'required|array|min:1',
            // Only existence is checked here — NOT that each value belongs
            // to the axis's attribute_definition_id. That cross-check is
            // NOT a simple Laravel rule (it depends on another field in
            // the same array element), and is already enforced by
            // VariationAxis's own constructor below
            // (InvalidVariationAxisException::valueBelongsToWrongDefinition),
            // which is the single place this invariant is checked — see
            // that class's docblock. Duplicating it here would just be a
            // second, redundant implementation of the same rule.
            'axes.*.value_ids.*' => 'required|exists:catalog_attribute_values,id',
        ]);

        // Same empty-string-triggers-generation behavior as
        // ProductController::store() — reusing the exact same hooks.
        $baseSku = Hook::apply('catalog.product.base_sku', $validated['base_sku'] ?? '');
        $slug = Hook::apply('catalog.product.slug', $validated['slug'] ?? '', $validated['name']);

        $product = Product::createVariable($validated['name'], $baseSku, $slug);

        try {
            $axes = [];
            $axisValueIdsByAttributeDefinitionId = [];

            foreach ($validated['axes'] as $axisInput) {
                $attributeDefinitionId = (string) $axisInput['attribute_definition_id'];

                $definition = $this->attributeDefinitions->findById($attributeDefinitionId);
                $values = array_map(
                    fn ($valueId) => $this->attributeValues->findById((string) $valueId),
                    $axisInput['value_ids']
                );

                // VariationAxis's constructor is what actually enforces
                // "every value belongs to this attribute_definition_id"
                // and "an axis must be non-empty" — see VariationAxis.php.
                // A violation is a client-correctable input error (a
                // value_id that doesn't belong to the referenced
                // attribute_definition_id), so InvalidVariationAxisException
                // is caught below and turned into a 422, not left to
                // propagate to Laravel's default handler as a raw 500.
                $axes[] = new VariationAxis($definition, $values);

                $axisValueIdsByAttributeDefinitionId[$attributeDefinitionId] = $axisInput['value_ids'];
            }

            // declareVariationAxes() throws a plain \LogicException (not a
            // dedicated Catalog exception class) in exactly two branches:
            // "Only a VARIABLE product can declare variation axes" — dead
            // code here, since $product always comes from
            // Product::createVariable() a few lines up — and "attribute
            // definition X was declared as a variation axis more than
            // once", which IS a client-correctable input error (two
            // 'axes' entries repeating the same attribute_definition_id).
            // Catching \LogicException is scoped to ONLY this one call,
            // deliberately not merged into the wider catch below or
            // widened to the whole try block: every other throw site in
            // this method's flow (VariationAxis's own "must be persisted"
            // \LogicException, VariationCombinationGenerator's "requires a
            // sku strategy" \LogicException, Product::addStandardVariation()'s
            // SIMPLE-product guard) is either a different exception type
            // already handled below (UnsafeAxisRedeclarationException
            // extends RuntimeException, not \LogicException — confirmed by
            // reading its source) or structurally unreachable given how
            // this controller always calls these APIs — so widening this
            // catch to cover more of the method would risk silently
            // swallowing a genuinely unexpected \LogicException from one of
            // those other sites instead of letting it surface as a bug.
            try {
                $product->declareVariationAxes($axes);
            } catch (\LogicException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }

            // Reuses the real 'catalog.variation.sku' generator (the same
            // one ProductController's SIMPLE flow would use if it ever
            // needed a variation sku) instead of hand-rolling a new
            // strategy — see CatalogSkuGeneratorServiceProvider::
            // variationSkuStrategy()'s own docblock for why this factory
            // is the one canonical place to get this closure from.
            $variations = (new VariationCombinationGenerator())->generate(
                $product,
                $axisValueIdsByAttributeDefinitionId,
                CatalogSkuGeneratorServiceProvider::variationSkuStrategy($product)
            );

            // Persists the Product together with every generated Variation
            // in one transaction (see ProductRepository's contract
            // docblock) — this is also what assigns real ids to $product
            // and to each Variation instance still referenced by
            // $variations below. DuplicateVariationCombinationException
            // is not reachable here in practice (product_id is brand new,
            // and every combination generated above is already guaranteed
            // distinct in-memory), but the DB-level (product_id,
            // attribute_signature) constraint it's translated from is the
            // real, authoritative guarantee per CLAUDE.md's "app-layer
            // validation alone is never sufficient" rule — caught here too
            // so this endpoint degrades to a 422 rather than a 500 if that
            // ever fires.
            $this->products->save($product);
        } catch (InvalidVariationAxisException|DuplicateVariationCombinationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'product_id' => $product->id(),
            'base_sku' => $product->baseSku(),
            'slug' => $product->slug(),
            'variations' => array_map(
                static fn ($variation) => [
                    'id' => $variation->id(),
                    'sku' => $variation->sku(),
                    'attribute_assignments' => $variation->attributeAssignments(),
                ],
                $variations
            ),
        ], 201);
    }
}
