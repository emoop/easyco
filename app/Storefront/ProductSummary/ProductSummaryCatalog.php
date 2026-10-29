<?php

namespace App\Storefront\ProductSummary;

/**
 * The components the right column of the product page («Дясна колона», SC-1) may hold: a CLOSED list, each with its own validated settings and how many times it may appear. `title` and `purchase` are
 * LOCKED (exactly one each, cannot be removed or duplicated, can be moved). Every component prints TODAY's markup through the partial `storefront/partials/summary/{type}.blade.php`.
 */
final class ProductSummaryCatalog
{
    public const ZONE = 'product_summary';

    public const SCOPE = 'global';

    public const MAX_COMPONENTS = 15;

    public const MAX_HTML_BLOCKS = 5;

    /** PB-1.1: the most attribute groups the merchant can put in order. */
    public const MAX_AXES = 10;

    /** Exactly one of each, never removed, never duplicated. */
    public const LOCKED = ['title', 'purchase'];

    /**
     * type => {label (a lang key), max (the most times it may appear), schema (the settings), view}.
     *
     * @return array<string, array{label: string, max: int, schema: array<string, array<string, mixed>>, view: string}>
     */
    public static function all(): array
    {
        $types = [
            'brand' => [1, []],
            'title' => [1, []],
            'sku' => [1, []],
            'short_description' => [1, []],
            'price' => [1, []],
            'stock' => [1, []],
            // PB-1: the add-to-cart button's own texts and look. An empty text = the shop's own wording (translated); a colour is `#rrggbb` or none (= the shop's colours); `rounded` yes = the shop's button radius (LK-1: the default is square)
            'purchase' => [1, [
                'label' => ['kind' => 'text', 'max' => 30, 'default' => ''],
                'label_choose' => ['kind' => 'text', 'max' => 30, 'default' => ''],
                'label_sold_out' => ['kind' => 'text', 'max' => 30, 'default' => ''],
                'bg' => ['kind' => 'color', 'hex6' => true, 'default' => null],
                'color' => ['kind' => 'color', 'hex6' => true, 'default' => null],
                'border_w' => ['kind' => 'int', 'min' => 0, 'max' => 4, 'default' => 0],
                'border_color' => ['kind' => 'color', 'hex6' => true, 'default' => null],
                'rounded' => ['kind' => 'bool', 'default' => false],
                // PB-1.1: the counter next to the button (off = no counter, quantity 1) and the merchant's order of the attribute groups (ids of attribute definitions; empty = the page's own order)
                'show_quantity' => ['kind' => 'bool', 'default' => true],
                'axes' => ['kind' => 'axes', 'max' => self::MAX_AXES, 'default' => []],
            ]],
            'html_block' => [self::MAX_HTML_BLOCKS, ['html_block' => ['kind' => 'html_block', 'default' => null, 'required' => true]]],
        ];
        $out = [];

        foreach ($types as $type => [$max, $schema]) {
            $out[$type] = ['label' => 'design.summary.types.'.$type, 'max' => $max, 'schema' => $schema, 'view' => 'storefront.partials.summary.'.$type];
        }

        return $out;
    }

    public static function isLocked(string $type): bool
    {
        return in_array($type, self::LOCKED, true);
    }

    /** The defaults of one type's settings (an id kind starts as "none chosen"). @return array<string, mixed> */
    public static function defaultsFor(string $type): array
    {
        $defaults = [];

        foreach ((self::all()[$type]['schema'] ?? []) as $name => $field) {
            $defaults[$name] = $field['default'] ?? null;
        }

        return $defaults;
    }
}
