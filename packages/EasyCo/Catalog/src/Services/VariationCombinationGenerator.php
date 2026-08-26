<?php

namespace EasyCo\Catalog\Services;

use EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;

/**
 * Generates the cartesian product of a VARIABLE product's declared axis
 * values into DRAFT Variations.
 *
 * Deliberately minimal per the v1 scope: no bulk-management UI/workflow
 * here, just the combination math plus reusing Product::addStandardVariation()
 * for every combination so the same uniqueness/invariant rules apply
 * whether a variation was generated or created manually. In particular,
 * axis/value VALIDITY (is this attribute a declared axis of this Product?
 * is this value allowed for it?) is ultimately re-checked and enforced by
 * Product::assertValidCombination() on every addStandardVariation() call
 * regardless — but this class ALSO validates the entire request upfront,
 * against the axes/values, before creating a single Variation. This
 * matters because a cartesian product touches every requested axis/value
 * in every combination it produces: without the upfront check, an invalid
 * value appearing later in one axis's list would only be discovered after
 * several earlier, valid combinations had already been created as a side
 * effect — a partial, inconsistent result. The upfront check makes
 * generate() all-or-nothing.
 *
 * A validation failure (undeclared axis, disallowed value, empty axis)
 * REJECTS THE WHOLE REQUEST before any Variation is created —
 * InvalidVariationAxisException is deliberately not caught here, so it
 * propagates to the caller immediately.
 *
 * Already-existing combinations are skipped rather than raising — running
 * the generator again after the merchant adds one more axis value should
 * only create the *new* combinations, not fail on the ones that already
 * exist.
 */
final class VariationCombinationGenerator
{
    /**
     * @param array<int|string, array<int, int|string>> $axisValueIdsByAttributeDefinitionId
     *   Map of attribute_definition_id => list of attribute_value_id
     *   selected by the merchant for that axis on this product.
     * @param ?callable(array<int|string, int|string>): string $skuForCombination
     *   Called once per combination to produce its sku. OPTIONAL — the
     *   real sku-generation feature is now the 'catalog.variation.sku'
     *   Hook filter (see App\Providers\CatalogSkuGeneratorServiceProvider
     *   and catalog-domain-design.md §3.2), but THIS CLASS CANNOT CALL IT
     *   ITSELF: Catalog is a domain package, and
     *   extensibility-design-and-hooks.md §2 / CLAUDE.md's hard
     *   architectural rules are explicit that domain packages must never
     *   call Hook::apply() or depend on EasyCo\Extensibility at all —
     *   only app/ layer code may. So when $skuForCombination is omitted,
     *   this method does NOT silently reach for a default; it throws
     *   (see below), and it is the CALLER's job (app/ layer code) to
     *   build a closure that calls
     *   Hook::apply('catalog.variation.sku', '', $product->baseSku(), $product)
     *   and pass it in — exactly the same "wrap from outside, never call
     *   from inside" pattern ProductController::store() already uses for
     *   catalog.product.base_sku / catalog.product.slug. Still accepted
     *   as an explicit override for any caller with custom sku logic.
     * @return Variation[] Newly created DRAFT variations (existing
     *   combinations are skipped, not returned).
     *
     * @throws \LogicException if $skuForCombination is null and at least
     *   one combination needs to be generated (an empty axis map is a
     *   no-op regardless, per the empty-map short-circuit below, so it
     *   never needs a sku strategy at all).
     */
    public function generate(Product $product, array $axisValueIdsByAttributeDefinitionId, ?callable $skuForCombination = null): array
    {
        if ($axisValueIdsByAttributeDefinitionId === []) {
            // No axes supplied at all is a no-op, not an error — distinct
            // from a supplied axis with an empty value list (see below),
            // which IS a rejected "empty/invalid axis definition". No sku
            // strategy is needed here either, since nothing is generated.
            return [];
        }

        $deduplicated = [];
        foreach ($axisValueIdsByAttributeDefinitionId as $attributeDefinitionId => $valueIds) {
            // Duplicate values within one axis's list are handled
            // deterministically by simply collapsing them — {Black, Black,
            // White} generates the same two combinations as {Black, White}.
            $unique = array_values(array_unique($valueIds, SORT_STRING));

            if ($unique === []) {
                throw InvalidVariationAxisException::emptyAxis((string) $attributeDefinitionId);
            }

            $deduplicated[$attributeDefinitionId] = $unique;
        }

        $this->assertEveryAxisAndValueIsValidForProduct($product, $deduplicated);

        $combinations = $this->cartesianProduct($deduplicated);

        if ($skuForCombination === null) {
            throw new \LogicException(
                'VariationCombinationGenerator::generate() requires a sku strategy for '.
                count($combinations).' combination(s), but none was given. This class cannot call '.
                'Hook::apply() itself (Catalog is a domain package — see extensibility-design-and-hooks.md §2); '.
                'pass an explicit $skuForCombination callable, or have app/ layer code build one via '.
                "Hook::apply('catalog.variation.sku', '', \$product->baseSku(), \$product)."
            );
        }

        $created = [];

        foreach ($combinations as $combination) {
            try {
                $created[] = $product->addStandardVariation($combination, $skuForCombination($combination));
            } catch (DuplicateVariationCombinationException) {
                // Already exists (e.g. merchant re-ran generation after
                // adding one more axis value) — skip, not an error.
                continue;
            }
        }

        return $created;
    }

    /**
     * @param array<int|string, array<int, int|string>> $valuesByAxis
     */
    private function assertEveryAxisAndValueIsValidForProduct(Product $product, array $valuesByAxis): void
    {
        $axesByDefinitionId = [];
        foreach ($product->variationAxes() as $axis) {
            $axesByDefinitionId[$axis->attributeDefinitionId()] = $axis;
        }

        foreach ($valuesByAxis as $attributeDefinitionId => $valueIds) {
            $attributeDefinitionId = (string) $attributeDefinitionId;

            $axis = $axesByDefinitionId[$attributeDefinitionId] ?? null;
            if ($axis === null) {
                throw InvalidVariationAxisException::axisNotDeclaredForProduct($attributeDefinitionId);
            }

            foreach ($valueIds as $valueId) {
                if (! $axis->isAllowedValueId((string) $valueId)) {
                    throw InvalidVariationAxisException::valueNotAllowedForAxis($attributeDefinitionId, (string) $valueId);
                }
            }
        }
    }

    /**
     * @param array<int|string, array<int, int|string>> $valuesByAxis
     * @return list<array<int|string, int|string>>
     */
    private function cartesianProduct(array $valuesByAxis): array
    {
        if ($valuesByAxis === []) {
            return [];
        }

        $result = [[]];

        foreach ($valuesByAxis as $attributeDefinitionId => $valueIds) {
            $next = [];
            foreach ($result as $combination) {
                foreach ($valueIds as $valueId) {
                    $next[] = $combination + [$attributeDefinitionId => $valueId];
                }
            }
            $result = $next;
        }

        return $result;
    }
}
