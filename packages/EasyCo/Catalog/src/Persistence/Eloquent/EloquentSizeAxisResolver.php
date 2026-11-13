<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\SizeAxisResolver;
use Illuminate\Support\Facades\DB;

/**
 * The size-axis rule of SizeAxisResolver in TWO statements, whatever the number of axes and sizes: (1) the product's variation axis, flagged `is_size` first, else the first declared (sort_order, then id);
 * (2) that axis' declared values in the attribute's own order. A product without a variation axis (a simple one) costs one statement and returns [].
 */
final class EloquentSizeAxisResolver implements SizeAxisResolver
{
    public function forProduct(string $productId): array
    {
        $definitionId = $this->axisDefinitionIdFor($productId);

        if ($definitionId === null) {
            return [];
        }

        return DB::table('catalog_product_axis_values as pav')
            ->join('catalog_attribute_values as av', 'av.id', '=', 'pav.attribute_value_id')
            ->where('pav.product_id', $productId)
            ->where('pav.attribute_definition_id', $definitionId)
            ->orderBy('av.sort_order')
            ->orderBy('av.id')
            ->pluck('av.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function axisDefinitionIdFor(string $productId): ?string
    {
        $id = DB::table('catalog_product_attributes as pa')
            ->join('catalog_attribute_definitions as ad', 'ad.id', '=', 'pa.attribute_definition_id')
            ->where('pa.product_id', $productId)
            ->where('pa.is_variation_axis', true)
            ->orderByDesc('ad.is_size')
            ->orderBy('pa.sort_order')
            ->orderBy('pa.id')
            ->value('pa.attribute_definition_id');

        return $id !== null ? (string) $id : null;
    }
}
