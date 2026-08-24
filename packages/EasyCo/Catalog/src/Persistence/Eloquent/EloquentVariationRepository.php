<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Variation;
use Illuminate\Support\Facades\DB;

/**
 * Read-focused repository for the hot commerce lookup paths that don't need
 * the whole Product aggregate loaded — see Contracts\VariationRepository's
 * docblock (POS scanning a barcode, Cart/Orders resolving a SKU, Pricing/
 * Inventory resolving by priceableId). Maps catalog_variations (+
 * catalog_variation_attribute_values) onto the domain Variation via
 * Variation::reconstituteFromStorage(), the same persistence-only factory
 * EloquentProductRepository uses.
 */
final class EloquentVariationRepository implements VariationRepository
{
    public function findById(string $id): ?Variation
    {
        $model = VariationModel::find($id);

        return $model !== null ? $this->toDomainVariation($model) : null;
    }

    public function findBySku(string $sku): ?Variation
    {
        $model = VariationModel::where('sku', $sku)->first();

        return $model !== null ? $this->toDomainVariation($model) : null;
    }

    public function findByBarcode(string $barcode): ?Variation
    {
        // A single unique result, not a list — catalog_variations.barcode
        // carries a UNIQUE index (see the migration) and this was decided
        // explicitly as ?Variation, not Variation[] on the contract. Do not
        // change this to a list.
        $model = VariationModel::where('barcode', $barcode)->first();

        return $model !== null ? $this->toDomainVariation($model) : null;
    }

    /** @return Variation[] */
    public function findByProductId(string $productId): array
    {
        $models = VariationModel::where('product_id', $productId)->get();

        $assignmentsByVariationId = $this->loadAttributeAssignments($models->pluck('id')->all());

        return $models
            ->map(fn (VariationModel $model) => $this->toDomainVariation(
                $model,
                $assignmentsByVariationId[$model->id] ?? null
            ))
            ->all();
    }

    private function toDomainVariation(VariationModel $model, ?array $attributeAssignments = null): Variation
    {
        $attributeAssignments ??= $this->loadAttributeAssignments([$model->id])[$model->id] ?? [];

        return Variation::reconstituteFromStorage(
            id: (string) $model->id,
            productId: (string) $model->product_id,
            type: VariationType::from($model->type),
            status: VariationStatus::from($model->status),
            attributeAssignments: $attributeAssignments,
            // See EloquentProductRepository::toDomainVariation() for why
            // this is cast rather than passed through directly.
            sku: (string) $model->sku,
            barcode: $model->barcode,
            isVisible: (bool) $model->is_visible,
            isPurchasable: (bool) $model->is_purchasable,
            shortDescription: $model->short_description,
            shippingClass: $model->shipping_class,
            weightGrams: $model->weight_grams,
            lengthMm: $model->length_mm,
            widthMm: $model->width_mm,
            heightMm: $model->height_mm,
        );
    }

    /**
     * @param array<int|string> $variationIds
     * @return array<int, array<int, int>> variation_id => [attribute_definition_id => attribute_value_id]
     */
    private function loadAttributeAssignments(array $variationIds): array
    {
        if ($variationIds === []) {
            return [];
        }

        $rows = DB::table('catalog_variation_attribute_values')
            ->whereIn('variation_id', $variationIds)
            ->get(['variation_id', 'attribute_definition_id', 'attribute_value_id']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->variation_id][$row->attribute_definition_id] = $row->attribute_value_id;
        }

        return $grouped;
    }
}
