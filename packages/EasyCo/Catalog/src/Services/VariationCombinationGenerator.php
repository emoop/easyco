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
     * @param callable(array<int|string, int|string>): string $skuForCombination
     *   Called once per combination to produce its sku, now that
     *   Product::addStandardVariation() requires one. This is NOT the real
     *   SKU-generation feature (still deferred, out of scope here) — it's
     *   just the injection point that keeps this generator working now
     *   that sku is a required argument; callers pass whatever strategy
     *   they have today (a naming template, a counter, a placeholder).
     * @return Variation[] Newly created DRAFT variations (existing
     *   combinations are skipped, not returned).
     */
    public function generate(Product $product, array $axisValueIdsByAttributeDefinitionId, callable $skuForCombination): array
    {
        if ($axisValueIdsByAttributeDefinitionId === []) {
            // No axes supplied at all is a no-op, not an error — distinct
            // from a supplied axis with an empty value list (see below),
            // which IS a rejected "empty/invalid axis definition".
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

        $created = [];

        foreach ($this->cartesianProduct($deduplicated) as $combination) {
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
