<?php

namespace App\Storefront\ProductSummary;

/**
 * The layout of the product page's right column (SC-1): `{components: [{type, settings}]}`, an ordered list. The DEFAULT is exactly what the page printed before the column became a list: brand, title,
 * short description, price, stock, purchase. A layout that is not valid is never printed: the default is.
 */
final class ProductSummaryLayout
{
    /** @return array{components: list<array{type: string, settings: array<string, mixed>}>} */
    public static function default(): array
    {
        return ['components' => array_map(
            // PB-1: every type with ITS defaults (only `purchase` has any), which is what the validator returns for an untouched component
            static fn (string $type): array => ['type' => $type, 'settings' => ProductSummaryCatalog::defaultsFor($type)],
            ['brand', 'title', 'short_description', 'price', 'stock', 'purchase'],
        )];
    }
}
