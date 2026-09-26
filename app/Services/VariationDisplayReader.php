<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Variation;
use Illuminate\Support\Collection;
use LogicException;

/**
 * THE ONE batched variation -> display-data read in this codebase: what a
 * variation's lines need to be shown and what its sold attributes are.
 *
 * TWO CALLERS, ONE READ EACH, NO SECOND COPY OF THE WALK: the checkout path's
 * SaleLineSnapshotBuilder needs the full attribute record it stores on a
 * SaleLine (soldAttributesFor()), and the cart API needs each line's product
 * name, SKU and attribute labels for display (lineDisplayFor()). The batched
 * walk — findByIds(), one definition query, one value query — lives here once;
 * the snapshot builder used to have it inline, and its own query-count test
 * proves the extraction is behaviour-preserving (its printed numbers are
 * unchanged).
 *
 * FOUR QUERIES FOR ATTRIBUTES, FIVE INCLUDING IDENTITY, REGARDLESS OF HOW MANY
 * VARIATIONS ARE ASKED FOR — never one round trip per line:
 * VariationRepository::findByIds() (itself ~2 bounded queries, the primitive
 * CatalogScopeResolver::forVariations() also reuses), one batched
 * AttributeDefinitionModel::whereIn(), one batched AttributeValueModel::whereIn()
 * — plus, for lineDisplayFor() only, one batched product-name pluck, since a
 * variation carries its own SKU but its product's name lives on the product.
 * Each public method calls findByIds() exactly once and shares that map across
 * everything it builds.
 *
 * NO SILENT FALLBACKS: a variation id that findByIds() does not return, or an
 * assignment whose definition or value row is missing, throws a LogicException
 * naming the offending id — never a silently empty/partial label list. A SIMPLE
 * product's UNIVERSAL variation legitimately has zero assignments and still
 * yields [] — that is the correct answer for that case, not a fallback.
 *
 * ORDER IS DETERMINISTIC: there is no authoritative merchant-defined axis order
 * in this codebase today (catalog_product_attributes.sort_order is always
 * written as 0 and never ordered by — a finding inherited from
 * SaleLineSnapshotBuilder's own docblock), so assignments are sorted by
 * attribute_definition_id ascending (cast to int — real auto-increment ids)
 * rather than trusting unordered DB row order.
 */
final class VariationDisplayReader
{
    public function __construct(
        private readonly VariationRepository $variations,
    ) {}

    /**
     * The attribute record the checkout snapshot stores on a SaleLine.
     *
     * @param  list<string>  $variationIds
     * @return array<string, list<array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}>> keyed by variationId — one entry for EVERY id asked for.
     */
    public function soldAttributesFor(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $variationsById = $this->variationsById($variationIds);

        return $this->attributesFor($variationsById);
    }

    /**
     * What the cart API returns per line: the product's name, the variation's own
     * SKU, and the attribute labels as an ordered name/value list ([] for a SIMPLE
     * product's universal variation).
     *
     * @param  list<string>  $variationIds
     * @return array<string, array{product_name: ?string, sku: string, attributes: list<array{name: string, value: string}>}> keyed by variationId — one entry for EVERY id asked for.
     */
    public function lineDisplayFor(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $variationsById = $this->variationsById($variationIds);
        $attributesByVariationId = $this->attributesFor($variationsById);

        // ONE query for every product involved, never one per line — and it runs
        // even when the map is empty (Laravel turns that into a `where 0 = 1`),
        // so the query count stays constant for any cart shape.
        $productIds = [];
        foreach ($variationsById as $variation) {
            $productIds[] = $variation->productId();
        }

        $productNamesById = ProductModel::whereIn('id', array_values(array_unique($productIds)))
            ->pluck('name', 'id');

        $display = [];
        foreach ($variationsById as $variationId => $variation) {
            $attributes = [];

            foreach ($attributesByVariationId[$variationId] as $attribute) {
                $attributes[] = [
                    'name' => $attribute['definitionName'],
                    'value' => $attribute['value'],
                ];
            }

            $display[$variationId] = [
                'product_name' => $productNamesById[$variation->productId()] ?? null,
                'sku' => $variation->sku(),
                'attributes' => $attributes,
            ];
        }

        return $display;
    }

    /**
     * @param  list<string>  $variationIds
     * @return array<string, Variation> keyed by variationId — one entry for EVERY id asked for.
     *
     * @throws LogicException If findByIds() doesn't return one of the requested ids.
     */
    private function variationsById(array $variationIds): array
    {
        $uniqueVariationIds = array_values(array_unique($variationIds));
        $variationsById = $this->variations->findByIds($uniqueVariationIds);

        foreach ($uniqueVariationIds as $variationId) {
            if (! isset($variationsById[$variationId])) {
                throw new LogicException(
                    "VariationDisplayReader: variation \"{$variationId}\" was not returned by ".
                    'VariationRepository::findByIds() — cannot build its display data.'
                );
            }
        }

        return $variationsById;
    }

    /**
     * @param  array<string, Variation>  $variationsById
     * @return array<string, list<array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}>>
     */
    private function attributesFor(array $variationsById): array
    {
        $definitionIds = [];
        $valueIds = [];

        foreach ($variationsById as $variation) {
            foreach ($variation->attributeAssignments() as $definitionId => $valueId) {
                $definitionIds[] = $definitionId;
                $valueIds[] = $valueId;
            }
        }

        $definitionModels = AttributeDefinitionModel::whereIn('id', array_unique($definitionIds))
            ->get()
            ->keyBy('id');
        $valueModels = AttributeValueModel::whereIn('id', array_unique($valueIds))
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($variationsById as $variationId => $variation) {
            $result[$variationId] = $this->labelsFor($variationId, $variation, $definitionModels, $valueModels);
        }

        return $result;
    }

    /**
     * @param  Collection<int, AttributeDefinitionModel>  $definitionModels
     * @param  Collection<int, AttributeValueModel>  $valueModels
     * @return array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}>
     *
     * @throws LogicException If an assignment references a definition or value
     *                        row that no longer exists — structurally impossible today (Catalog's own
     *                        restrictOnDelete FKs forbid deleting a referenced definition/value), so
     *                        this is a real corruption signal, never silently skipped.
     */
    private function labelsFor(string $variationId, Variation $variation, Collection $definitionModels, Collection $valueModels): array
    {
        $assignments = $variation->attributeAssignments();

        // No authoritative order exists — see this class's own docblock. A SIMPLE
        // product's UNIVERSAL variation has $assignments === []; the loop below
        // simply doesn't run, correctly yielding [].
        ksort($assignments, SORT_NUMERIC);

        $attributes = [];
        foreach ($assignments as $definitionId => $valueId) {
            $definitionModel = $definitionModels->get($definitionId);

            if ($definitionModel === null) {
                throw new LogicException(
                    "VariationDisplayReader: variation \"{$variationId}\" references attribute_definition_id ".
                    "\"{$definitionId}\", which does not exist."
                );
            }

            $valueModel = $valueModels->get($valueId);

            if ($valueModel === null) {
                throw new LogicException(
                    "VariationDisplayReader: variation \"{$variationId}\" references attribute_value_id ".
                    "\"{$valueId}\", which does not exist."
                );
            }

            $attributes[] = [
                'definitionId' => (string) $definitionId,
                'definitionCode' => $definitionModel->code,
                'definitionName' => $definitionModel->name,
                'valueId' => (string) $valueId,
                'value' => $valueModel->value,
            ];
        }

        return $attributes;
    }
}
