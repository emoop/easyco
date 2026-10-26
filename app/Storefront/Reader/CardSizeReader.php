<?php

namespace App\Storefront\Reader;

use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Catalog\Enums\AttributeDisplayType;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Inventory\Persistence\Eloquent\StockLevelModel;
use Illuminate\Support\Facades\DB;

/**
 * PC-2: the SIZES of the product cards, for ONE batch of products (the ids of a listing page). A size is the value of the product's first variation
 * axis whose attribute has the display type `buttons` (CT-3), in the axis order of the product and the value order the admin set; at most 8 per product.
 * A size is sold out when no SHOWN variation with that value has stock — the card's own in-stock rule (CatalogReader::cards()), read the same way.
 *
 * A FIXED number of queries however many ids are asked: the visible products, their axes, their shown variations, the values of those variations, and the stock
 * levels. Products that are hidden, drafts, archived, simple or without a `buttons` axis are simply absent from the result.
 */
final class CardSizeReader
{
    /** Most sizes shown for one card: the first 8 in the value order. */
    public const MAX_SIZES = 8;

    public function __construct(private readonly StorefrontVisibility $visibility)
    {
    }

    /**
     * @param  list<int>  $productIds  positive ids, already validated (at most 48)
     * @return array<int, list<array{label: string, in_stock: bool}>> product id => its sizes, only for products that have some
     */
    public function sizesFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = $this->visibility->products(ProductModel::query())
            ->whereIn('catalog_products.id', $productIds)
            ->where('catalog_products.type', ProductType::VARIABLE->value)
            ->pluck('catalog_products.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($products === []) {
            return [];
        }

        // the first buttons axis of each product, in the product's own axis order
        $axes = [];

        foreach (DB::table('catalog_product_attributes as pa')
            ->join('catalog_attribute_definitions as def', 'def.id', '=', 'pa.attribute_definition_id')
            ->whereIn('pa.product_id', $products)
            ->where('pa.is_variation_axis', true)
            ->where('def.display_type', AttributeDisplayType::BUTTONS->value)
            ->orderBy('pa.product_id')
            ->orderBy('pa.sort_order')
            ->orderBy('pa.id')
            ->get(['pa.product_id', 'pa.attribute_definition_id']) as $row) {
            $axes[(int) $row->product_id] ??= (int) $row->attribute_definition_id;
        }

        if ($axes === []) {
            return [];
        }

        $variations = $this->visibility->variations(VariationModel::query())
            ->whereIn('catalog_variations.product_id', array_keys($axes))
            ->get(['catalog_variations.id', 'catalog_variations.product_id']);

        $variationIds = $variations->map(fn (VariationModel $v): int => (int) $v->id)->all();
        $productOf = [];

        foreach ($variations as $variation) {
            $productOf[(int) $variation->id] = (int) $variation->product_id;
        }

        $stock = $variationIds === [] ? [] : StockLevelModel::query()
            ->whereIn('variation_id', $variationIds)
            ->get(['variation_id', 'quantity'])
            ->mapWithKeys(fn (StockLevelModel $level): array => [(int) $level->variation_id => (int) $level->quantity])
            ->all();

        $values = $variationIds === [] ? [] : DB::table('catalog_variation_attribute_values as vav')
            ->join('catalog_attribute_values as val', 'val.id', '=', 'vav.attribute_value_id')
            ->whereIn('vav.variation_id', $variationIds)
            ->orderBy('val.sort_order')
            ->orderBy('val.id')
            ->get(['vav.variation_id', 'vav.attribute_definition_id', 'val.id as value_id', 'val.value as label']);

        // product => value id => [label, in stock?] in the value order; only the chosen axis counts
        $sizes = [];

        foreach ($values as $row) {
            $variationId = (int) $row->variation_id;
            $productId = $productOf[$variationId] ?? null;

            if ($productId === null || $axes[$productId] !== (int) $row->attribute_definition_id) {
                continue;
            }

            $valueId = (int) $row->value_id;
            $sizes[$productId][$valueId] ??= ['label' => (string) $row->label, 'in_stock' => false];

            if (($stock[$variationId] ?? 0) > 0) {
                $sizes[$productId][$valueId]['in_stock'] = true;
            }
        }

        $result = [];

        foreach ($sizes as $productId => $bySize) {
            $result[$productId] = array_values(array_slice($bySize, 0, self::MAX_SIZES, true));
        }

        return $result;
    }
}
